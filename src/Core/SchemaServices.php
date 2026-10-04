<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

use Feedple\Sdk\Core\Exceptions\SchemaSyncException;

/**
 * Database schema inspection, hashing and sync utilities.
 *
 * Mirrors Python's schema_services.py:
 *   - get_schema()
 *   - _normalize_schema()
 *   - _generate_schema_hash()
 *   - send_schema_to_api()
 *   - should_sync_schema()
 *   - filter_sensitive_columns()
 */
class SchemaServices
{
    /**
     * Column names that are stripped from schema output for security.
     *
     * Mirrors: SENSITIVE_COLUMNS = {"password", "token", "secret", "hash", "salt", "ssn", "credit_card"}
     *
     * @var string[]
     */
    public const SENSITIVE_COLUMNS = [
        'password', 'token', 'secret', 'hash', 'salt', 'ssn', 'credit_card',
    ];

    /**
     * Reflect the database schema for tables permitted by the given identity.
     *
     * Uses INFORMATION_SCHEMA queries (supported by MySQL, PostgreSQL, SQLite via
     * pragma) as the PHP equivalent of SQLAlchemy's Inspector API.
     *
     * Mirrors: get_schema(db: Engine, identity: Identity) -> dict
     *
     * @param  \PDO      $db
     * @param  Identity  $identity
     * @return array<string, array{
     *     columns: list<array{name: string, type: string, nullable: bool, default: string|null}>,
     *     primary_key: list<string>,
     *     foreign_keys: list<array{columns: list<string>, references_table: string, references_columns: list<string>}>,
     *     indexes: list<array{name: string, columns: list<string>, unique: bool}>,
     *     unique_constraints: list<array{name: string, columns: list<string>}>
     * }>
     * @throws \ValueError  if a requested table does not exist
     */
    public static function getSchema(\PDO $db, Identity $identity): array
    {
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Determine which tables to inspect
        $allTables = self::listTables($db, $driver);

        if ($identity->allTables) {
            $tables = $allTables;
        } else {
            // Validate each requested table exists before inspecting
            foreach ($identity->allowedTables as $requested) {
                if (!in_array($requested, $allTables, true)) {
                    throw new \ValueError("Table '{$requested}' does not exist in the database");
                }
            }
            $tables = $identity->allowedTables;
        }

        if ($driver === 'pgsql') {
            return self::getPgsqlSchemaBulk($db, $tables);
        }

        if ($driver === 'mysql') {
            return self::getMysqlSchemaBulk($db, $tables);
        }

        $result = [];
        foreach ($tables as $table) {
            $result[$table] = [
                'columns'            => self::getColumns($db, $driver, $table),
                'primary_key'        => self::getPrimaryKey($db, $driver, $table),
                'foreign_keys'       => self::getForeignKeys($db, $driver, $table),
                'indexes'            => self::getIndexes($db, $driver, $table),
                'unique_constraints' => self::getUniqueConstraints($db, $driver, $table),
            ];
        }

        return $result;
    }

    private static function getMysqlSchemaBulk(\PDO $db, array $allowedTables): array
    {
        if (empty($allowedTables)) {
            return [];
        }

        $columnsMap = [];
        $pkMap = [];
        $fkRawMap = [];
        $idxRawMap = [];
        $ucRawMap = [];

        // Process in chunks of 100 tables to scale effortlessly from 1 to 10,000+ tables
        $chunks = array_chunk($allowedTables, 100);

        foreach ($chunks as $chunk) {
            $inClause = implode(',', array_fill(0, count($chunk), '?'));

            // 1. Columns
            $colStmt = $db->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$inClause})
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            );
            $colStmt->execute($chunk);
            while ($row = $colStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $columnsMap[$t][] = [
                    'name'     => self::getRowVal($row, 'column_name'),
                    'type'     => self::getRowVal($row, 'data_type'),
                    'nullable' => strtoupper((string) self::getRowVal($row, 'is_nullable')) === 'YES',
                    'default'  => self::getRowVal($row, 'column_default') !== null ? (string) self::getRowVal($row, 'column_default') : null,
                ];
            }

            // 2. Primary Keys and Foreign Keys
            $keyStmt = $db->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$inClause})
                 ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"
            );
            $keyStmt->execute($chunk);
            while ($row = $keyStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $constraint = self::getRowVal($row, 'constraint_name');
                $column = self::getRowVal($row, 'column_name');
                $refTable = self::getRowVal($row, 'referenced_table_name');
                $refCol = self::getRowVal($row, 'referenced_column_name');

                if ($constraint === 'PRIMARY') {
                    $pkMap[$t][] = $column;
                } elseif ($refTable !== null) {
                    if (!isset($fkRawMap[$t][$constraint])) {
                        $fkRawMap[$t][$constraint] = [
                            'columns'            => [],
                            'references_table'   => $refTable,
                            'references_columns' => [],
                        ];
                    }
                    $fkRawMap[$t][$constraint]['columns'][]            = $column;
                    $fkRawMap[$t][$constraint]['references_columns'][] = $refCol;
                }
            }

            // 3. Indexes & Unique Constraints
            $idxStmt = $db->prepare(
                "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$inClause})
                 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
            );
            $idxStmt->execute($chunk);
            while ($row = $idxStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $name = self::getRowVal($row, 'index_name');
                if ($name === 'PRIMARY') continue;
                $isUnique = (int) self::getRowVal($row, 'non_unique', 1) === 0;
                $col = self::getRowVal($row, 'column_name');

                if (!isset($idxRawMap[$t][$name])) {
                    $idxRawMap[$t][$name] = [
                        'name'     => $name,
                        'columns'  => [],
                        'unique'   => $isUnique,
                    ];
                }
                $idxRawMap[$t][$name]['columns'][] = $col;

                if ($isUnique) {
                    if (!isset($ucRawMap[$t][$name])) {
                        $ucRawMap[$t][$name] = [
                            'name'    => $name,
                            'columns' => [],
                        ];
                    }
                    $ucRawMap[$t][$name]['columns'][] = $col;
                }
            }
        }

        $fkMap = [];
        foreach ($fkRawMap as $t => $constraints) {
            $fkMap[$t] = array_values($constraints);
        }

        $idxMap = [];
        foreach ($idxRawMap as $t => $indexes) {
            $idxMap[$t] = array_values($indexes);
        }

        $ucMap = [];
        foreach ($ucRawMap as $t => $ucs) {
            $ucMap[$t] = array_values($ucs);
        }

        // 4. Assemble final schema dictionary
        $result = [];
        foreach ($allowedTables as $table) {
            $cols = $columnsMap[$table] ?? [];
            $filteredCols = [];
            foreach ($cols as $c) {
                if (in_array(strtolower($c['name']), self::SENSITIVE_COLUMNS, true)) {
                    continue;
                }
                $filteredCols[] = $c;
            }
            $result[$table] = [
                'columns'            => $filteredCols,
                'primary_key'        => $pkMap[$table] ?? [],
                'foreign_keys'       => $fkMap[$table] ?? [],
                'indexes'            => $idxMap[$table] ?? [],
                'unique_constraints' => $ucMap[$table] ?? [],
            ];
        }

        return $result;
    }

    private static function getPgsqlSchemaBulk(\PDO $db, array $allowedTables): array
    {
        if (empty($allowedTables)) {
            return [];
        }

        $columnsMap = [];
        $pkMap = [];
        $fkRawMap = [];
        $idxRawMap = [];
        $ucRawMap = [];

        // Process in chunks of 100 tables
        $chunks = array_chunk($allowedTables, 100);

        foreach ($chunks as $chunk) {
            $inClause = implode(',', array_fill(0, count($chunk), '?'));

            // 1. Columns
            $colStmt = $db->prepare(
                "SELECT table_name, column_name, data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name IN ({$inClause})
                 ORDER BY table_name, ordinal_position"
            );
            $colStmt->execute($chunk);
            while ($row = $colStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $columnsMap[$t][] = [
                    'name'     => self::getRowVal($row, 'column_name'),
                    'type'     => self::getRowVal($row, 'data_type'),
                    'nullable' => strtoupper((string) self::getRowVal($row, 'is_nullable')) === 'YES',
                    'default'  => self::getRowVal($row, 'column_default') !== null ? (string) self::getRowVal($row, 'column_default') : null,
                ];
            }

            // 2. Primary Keys
            $pkStmt = $db->prepare(
                "SELECT k.table_name, k.column_name
                 FROM information_schema.key_column_usage k
                 JOIN information_schema.table_constraints c
                   ON k.constraint_name = c.constraint_name AND k.table_schema = c.table_schema
                 WHERE c.constraint_type = 'PRIMARY KEY'
                   AND k.table_schema = current_schema()
                   AND k.table_name IN ({$inClause})
                 ORDER BY k.table_name, k.ordinal_position"
            );
            $pkStmt->execute($chunk);
            while ($row = $pkStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $pkMap[$t][] = self::getRowVal($row, 'column_name');
            }

            // 3. Foreign Keys
            $fkStmt = $db->prepare(
                "SELECT k.table_name, k.column_name, ccu.table_name AS referenced_table_name, ccu.column_name AS referenced_column_name, k.constraint_name
                 FROM information_schema.key_column_usage k
                 JOIN information_schema.table_constraints c
                   ON k.constraint_name = c.constraint_name AND k.table_schema = c.table_schema
                 JOIN information_schema.constraint_column_usage ccu
                   ON c.constraint_name = ccu.constraint_name AND c.table_schema = ccu.table_schema
                 WHERE c.constraint_type = 'FOREIGN KEY'
                   AND k.table_schema = current_schema()
                   AND k.table_name IN ({$inClause})
                 ORDER BY k.table_name, k.constraint_name, k.ordinal_position"
            );
            $fkStmt->execute($chunk);
            while ($row = $fkStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $name = self::getRowVal($row, 'constraint_name', 'fk');
                $refTable = self::getRowVal($row, 'referenced_table_name', '');
                $colName = self::getRowVal($row, 'column_name', '');
                $refColName = self::getRowVal($row, 'referenced_column_name', '');
                if (!isset($fkRawMap[$t][$name])) {
                    $fkRawMap[$t][$name] = [
                        'columns'            => [],
                        'references_table'   => $refTable,
                        'references_columns' => [],
                    ];
                }
                $fkRawMap[$t][$name]['columns'][]            = $colName;
                $fkRawMap[$t][$name]['references_columns'][] = $refColName;
            }

            // 4. Indexes & Unique Constraints
            $idxStmt = $db->prepare(
                "SELECT t.relname AS table_name, i.relname AS index_name, a.attname AS column_name, ix.indisunique AS is_unique
                 FROM pg_class t
                 JOIN pg_index ix ON t.oid = ix.indrelid
                 JOIN pg_class i ON i.oid = ix.indexrelid
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE n.nspname = current_schema()
                   AND t.relkind = 'r'
                   AND t.relname IN ({$inClause})
                 ORDER BY t.relname, i.relname"
            );
            $idxStmt->execute($chunk);
            while ($row = $idxStmt->fetch(\PDO::FETCH_ASSOC)) {
                $t = self::getRowVal($row, 'table_name');
                if (!$t) continue;
                $name = self::getRowVal($row, 'index_name');
                $col = self::getRowVal($row, 'column_name');
                $isUnique = (bool) self::getRowVal($row, 'is_unique', false);

                if (!isset($idxRawMap[$t][$name])) {
                    $idxRawMap[$t][$name] = [
                        'name'     => $name,
                        'columns'  => [],
                        'unique'   => $isUnique,
                    ];
                }
                $idxRawMap[$t][$name]['columns'][] = $col;

                if ($isUnique) {
                    if (!isset($ucRawMap[$t][$name])) {
                        $ucRawMap[$t][$name] = [
                            'name'    => $name,
                            'columns' => [],
                        ];
                    }
                    $ucRawMap[$t][$name]['columns'][] = $col;
                }
            }
        }

        $fkMap = [];
        foreach ($fkRawMap as $t => $constraints) {
            $fkMap[$t] = array_values($constraints);
        }

        $idxMap = [];
        foreach ($idxRawMap as $t => $indexes) {
            $idxMap[$t] = array_values($indexes);
        }

        $ucMap = [];
        foreach ($ucRawMap as $t => $ucs) {
            $ucMap[$t] = array_values($ucs);
        }

        // 5. Assemble final schema dictionary
        $result = [];
        foreach ($allowedTables as $table) {
            $cols = $columnsMap[$table] ?? [];
            $filteredCols = [];
            foreach ($cols as $c) {
                if (in_array(strtolower($c['name']), self::SENSITIVE_COLUMNS, true)) {
                    continue;
                }
                $filteredCols[] = $c;
            }
            $result[$table] = [
                'columns'            => $filteredCols,
                'primary_key'        => $pkMap[$table] ?? [],
                'foreign_keys'       => $fkMap[$table] ?? [],
                'indexes'            => $idxMap[$table] ?? [],
                'unique_constraints' => $ucMap[$table] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Produce a deterministic, sorted JSON string of the schema for hashing.
     *
     * Mirrors: _normalize_schema(schema) -> str
     *   json.dumps(schema, sort_keys=True, separators=(',', ':'))
     */
    public static function normalizeSchema(array $schema): string
    {
        ksort($schema);
        return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * SHA-256 hash of the normalized schema for change detection.
     *
     * Mirrors: _generate_schema_hash(schema) -> str
     */
    public static function generateSchemaHash(array $schema): string
    {
        return hash('sha256', self::normalizeSchema($schema));
    }

    /**
     * Returns true if the two schemas differ and a sync is required.
     *
     * Mirrors: should_sync_schema(old_schema, new_schema) -> bool
     */
    public static function shouldSyncSchema(array $oldSchema, array $newSchema): bool
    {
        return self::generateSchemaHash($oldSchema) !== self::generateSchemaHash($newSchema);
    }

    /**
     * Remove columns whose names appear in SENSITIVE_COLUMNS from every table.
     *
     * Mirrors: filter_sensitive_columns(schema) -> dict
     */
    public static function filterSensitiveColumns(array $schema): array
    {
        foreach ($schema as &$table) {
            $table['columns'] = array_values(array_filter(
                $table['columns'],
                function (array $col): bool {
                    return !in_array(strtolower($col['name']), self::SENSITIVE_COLUMNS, true);
                }
            ));
        }
        unset($table);
        return $schema;
    }

    // ── Private introspection helpers ─────────────────────────────────────

    /**
     * List all table names in the database.
     *
     * @return string[]
     */
    private static function listTables(\PDO $db, string $driver): array
    {
        $tables = [];

        if ($driver === 'sqlite') {
            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $tables[] = $row['name'];
            }
        } elseif ($driver === 'pgsql') {
            $stmt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $tables[] = $row['table_name'] ?? $row['TABLE_NAME'];
            }
        } elseif ($driver === 'mysql') {
            $dbName = $db->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = \'BASE TABLE\' ORDER BY table_name');
            $stmt->execute([$dbName]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $tables[] = $row['table_name'] ?? $row['TABLE_NAME'];
            }
        } else {
            // Fallback: try ANSI information_schema
            $stmt = $db->query("SELECT table_name FROM information_schema.tables WHERE table_type = 'BASE TABLE'");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $tables[] = $row['table_name'] ?? $row['TABLE_NAME'];
            }
        }

        return $tables;
    }

    /**
     * Return column metadata for a table.
     *
     * Mirrors: inspector.get_columns(table) flattened into column dicts
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null}>
     */
    private static function getColumns(\PDO $db, string $driver, string $table): array
    {
        $columns = [];

        if ($driver === 'sqlite') {
            $stmt = $db->query("PRAGMA table_info(" . self::quoteIdentifier($table) . ")");
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = [
                    'name'     => $row['name'],
                    'type'     => $row['type'],
                    'nullable' => ($row['notnull'] == '0'),
                    'default'  => $row['dflt_value'] !== null ? (string) $row['dflt_value'] : null,
                ];
            }
        } elseif ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'SELECT column_name, data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_name = ?
                   AND table_schema = current_schema()
                 ORDER BY ordinal_position'
            );
            $stmt->execute([$table]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = [
                    'name'     => $row['column_name'] ?? $row['COLUMN_NAME'],
                    'type'     => $row['data_type'] ?? $row['DATA_TYPE'],
                    'nullable' => strtoupper($row['is_nullable'] ?? $row['IS_NULLABLE']) === 'YES',
                    'default'  => isset($row['column_default']) || isset($row['COLUMN_DEFAULT'])
                        ? (string) ($row['column_default'] ?? $row['COLUMN_DEFAULT'])
                        : null,
                ];
            }
        } else {
            // MySQL via INFORMATION_SCHEMA
            $stmt = $db->prepare(
                'SELECT column_name, data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_name = ?
                 ORDER BY ordinal_position'
            );
            $stmt->execute([$table]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = [
                    'name'     => $row['column_name'] ?? $row['COLUMN_NAME'],
                    'type'     => $row['data_type'] ?? $row['DATA_TYPE'],
                    'nullable' => strtoupper($row['is_nullable'] ?? $row['IS_NULLABLE']) === 'YES',
                    'default'  => isset($row['column_default']) || isset($row['COLUMN_DEFAULT'])
                        ? (string) ($row['column_default'] ?? $row['COLUMN_DEFAULT'])
                        : null,
                ];
            }
        }

        return $columns;
    }

    /**
     * Return primary key column names for a table.
     *
     * Mirrors: inspector.get_pk_constraint(table)['constrained_columns']
     *
     * @return string[]
     */
    private static function getPrimaryKey(\PDO $db, string $driver, string $table): array
    {
        if ($driver === 'sqlite') {
            $stmt = $db->query("PRAGMA table_info(" . self::quoteIdentifier($table) . ")");
            $pks = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if ($row['pk'] > 0) {
                    $pks[(int)$row['pk']] = $row['name'];
                }
            }
            ksort($pks);
            return array_values($pks);
        }

        if ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'SELECT column_name
                 FROM information_schema.key_column_usage k
                 JOIN information_schema.table_constraints c
                   ON k.constraint_name = c.constraint_name AND k.table_schema = c.table_schema
                 WHERE c.constraint_type = \'PRIMARY KEY\'
                   AND k.table_name = ?
                   AND k.table_schema = current_schema()
                 ORDER BY k.ordinal_position'
            );
            $stmt->execute([$table]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        }

        // MySQL
        $stmt = $db->prepare(
            'SELECT column_name
             FROM information_schema.key_column_usage k
             JOIN information_schema.table_constraints c
               ON k.constraint_name = c.constraint_name
              AND k.table_name = c.table_name
             WHERE c.constraint_type = \'PRIMARY KEY\'
               AND k.table_name = ?
             ORDER BY k.ordinal_position'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return foreign key definitions for a table.
     *
     * Mirrors: inspector.get_foreign_keys(table)
     *
     * @return list<array{columns: list<string>, references_table: string, references_columns: list<string>}>
     */
    private static function getForeignKeys(\PDO $db, string $driver, string $table): array
    {
        if ($driver === 'sqlite') {
            $stmt = $db->query("PRAGMA foreign_key_list(" . self::quoteIdentifier($table) . ")");
            $fkMap = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $id = $row['id'];
                if (!isset($fkMap[$id])) {
                    $fkMap[$id] = [
                        'columns'            => [],
                        'references_table'   => $row['table'],
                        'references_columns' => [],
                    ];
                }
                $fkMap[$id]['columns'][]            = $row['from'];
                $fkMap[$id]['references_columns'][] = $row['to'];
            }
            return array_values($fkMap);
        }

        if ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'SELECT k.column_name, ccu.table_name AS referenced_table_name, ccu.column_name AS referenced_column_name, k.constraint_name
                 FROM information_schema.key_column_usage k
                 JOIN information_schema.table_constraints c
                   ON k.constraint_name = c.constraint_name AND k.table_schema = c.table_schema
                 JOIN information_schema.constraint_column_usage ccu
                   ON c.constraint_name = ccu.constraint_name AND c.table_schema = ccu.table_schema
                 WHERE c.constraint_type = \'FOREIGN KEY\'
                   AND k.table_name = ?
                   AND k.table_schema = current_schema()
                 ORDER BY k.constraint_name, k.ordinal_position'
            );
            $stmt->execute([$table]);
            $fkMap = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $name = $row['constraint_name'];
                if (!isset($fkMap[$name])) {
                    $fkMap[$name] = [
                        'columns'            => [],
                        'references_table'   => $row['referenced_table_name'],
                        'references_columns' => [],
                    ];
                }
                $fkMap[$name]['columns'][]            = $row['column_name'];
                $fkMap[$name]['references_columns'][] = $row['referenced_column_name'];
            }
            return array_values($fkMap);
        }

        // MySQL
        $stmt = $db->prepare(
            'SELECT k.column_name, k.referenced_table_name, k.referenced_column_name, k.constraint_name
             FROM information_schema.key_column_usage k
             JOIN information_schema.table_constraints c
               ON k.constraint_name = c.constraint_name
              AND k.table_name = c.table_name
             WHERE c.constraint_type = \'FOREIGN KEY\'
               AND k.table_name = ?
             ORDER BY k.constraint_name, k.ordinal_position'
        );
        $stmt->execute([$table]);
        $fkMap = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $name = $row['constraint_name'] ?? $row['CONSTRAINT_NAME'];
            if (!isset($fkMap[$name])) {
                $fkMap[$name] = [
                    'columns'            => [],
                    'references_table'   => $row['referenced_table_name'] ?? $row['REFERENCED_TABLE_NAME'],
                    'references_columns' => [],
                ];
            }
            $fkMap[$name]['columns'][]            = $row['column_name'] ?? $row['COLUMN_NAME'];
            $fkMap[$name]['references_columns'][] = $row['referenced_column_name'] ?? $row['REFERENCED_COLUMN_NAME'];
        }
        return array_values($fkMap);
    }

    /**
     * Return index definitions for a table.
     *
     * Mirrors: inspector.get_indexes(table)
     *
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    private static function getIndexes(\PDO $db, string $driver, string $table): array
    {
        if ($driver === 'sqlite') {
            $stmt = $db->query("PRAGMA index_list(" . self::quoteIdentifier($table) . ")");
            $indexes = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $indexName = $row['name'];
                $infoStmt  = $db->query("PRAGMA index_info(" . self::quoteIdentifier($indexName) . ")");
                $cols = [];
                while ($info = $infoStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $cols[] = $info['name'];
                }
                $indexes[] = [
                    'name'    => $indexName,
                    'columns' => $cols,
                    'unique'  => (bool)$row['unique'],
                ];
            }
            return $indexes;
        }

        if ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'SELECT i.relname AS index_name, a.attname AS column_name, ix.indisunique AS is_unique
                 FROM pg_class t
                 JOIN pg_index ix ON t.oid = ix.indrelid
                 JOIN pg_class i ON i.oid = ix.indexrelid
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE t.relname = ?
                   AND n.nspname = current_schema()
                   AND NOT ix.indisprimary
                 ORDER BY i.relname'
            );
            $stmt->execute([$table]);
            $idxMap = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $name = $row['index_name'];
                if (!isset($idxMap[$name])) {
                    $idxMap[$name] = [
                        'name'    => $name,
                        'columns' => [],
                        'unique'  => (bool)$row['is_unique'],
                    ];
                }
                $idxMap[$name]['columns'][] = $row['column_name'];
            }
            return array_values($idxMap);
        }

        // MySQL
        $stmt = $db->prepare(
            'SELECT s.index_name, s.column_name, s.non_unique
             FROM information_schema.statistics s
             WHERE s.table_name = ?
               AND s.index_name != \'PRIMARY\'
             ORDER BY s.index_name, s.seq_in_index'
        );
        $stmt->execute([$table]);
        $idxMap = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $name = $row['index_name'] ?? $row['INDEX_NAME'];
            if (!isset($idxMap[$name])) {
                $idxMap[$name] = [
                    'name'    => $name,
                    'columns' => [],
                    'unique'  => !(bool)($row['non_unique'] ?? $row['NON_UNIQUE']),
                ];
            }
            $idxMap[$name]['columns'][] = $row['column_name'] ?? $row['COLUMN_NAME'];
        }
        return array_values($idxMap);
    }

    /**
     * Return unique constraint definitions for a table.
     *
     * Mirrors: inspector.get_unique_constraints(table)
     *
     * @return list<array{name: string, columns: list<string>}>
     */
    private static function getUniqueConstraints(\PDO $db, string $driver, string $table): array
    {
        if ($driver === 'sqlite') {
            // SQLite unique constraints are reflected through indexes with unique=true
            $stmt = $db->query("PRAGMA index_list(" . self::quoteIdentifier($table) . ")");
            $constraints = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (!$row['unique']) {
                    continue;
                }
                $indexName = $row['name'];
                $infoStmt  = $db->query("PRAGMA index_info(" . self::quoteIdentifier($indexName) . ")");
                $cols = [];
                while ($info = $infoStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $cols[] = $info['name'];
                }
                $constraints[] = ['name' => $indexName, 'columns' => $cols];
            }
            return $constraints;
        }

        if ($driver === 'pgsql') {
            $stmt = $db->prepare(
                'SELECT k.constraint_name, k.column_name
                 FROM information_schema.key_column_usage k
                 JOIN information_schema.table_constraints c
                   ON k.constraint_name = c.constraint_name AND k.table_schema = c.table_schema
                 WHERE c.constraint_type = \'UNIQUE\'
                   AND k.table_name = ?
                   AND k.table_schema = current_schema()
                 ORDER BY k.constraint_name, k.ordinal_position'
            );
            $stmt->execute([$table]);
            $ucMap = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $name = $row['constraint_name'];
                $ucMap[$name][] = $row['column_name'];
            }
            return array_map(
                function (string $name, array $cols): array {
                    return ['name' => $name, 'columns' => $cols];
                },
                array_keys($ucMap),
                array_values($ucMap)
            );
        }

        // MySQL
        $stmt = $db->prepare(
            'SELECT k.constraint_name, k.column_name
             FROM information_schema.key_column_usage k
             JOIN information_schema.table_constraints c
               ON k.constraint_name = c.constraint_name
              AND k.table_name = c.table_name
             WHERE c.constraint_type = \'UNIQUE\'
               AND k.table_name = ?
             ORDER BY k.constraint_name, k.ordinal_position'
        );
        $stmt->execute([$table]);
        $ucMap = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $name = $row['constraint_name'] ?? $row['CONSTRAINT_NAME'];
            $ucMap[$name][] = $row['column_name'] ?? $row['COLUMN_NAME'];
        }
        return array_map(
            function (string $name, array $cols): array {
                return ['name' => $name, 'columns' => $cols];
            },
            array_keys($ucMap),
            array_values($ucMap)
        );
    }

    /**
     * Safely quote a table or index identifier for use in PRAGMA / raw queries.
     * Only used for SQLite PRAGMAs where parameterized binding is not supported.
     */
    private static function quoteIdentifier(string $name): string
    {
        // Validate: identifiers may only contain alphanumeric, underscore, dollar sign
        if (!preg_match('/^[a-zA-Z0-9_$]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid identifier: {$name}");
        }
        return '"' . $name . '"';
    }

    /**
     * Case-insensitive safe fetch of a key from a PDO row array.
     */
    private static function getRowVal(array $row, string $key, $default = null)
    {
        $upperKey = strtoupper($key);
        $lowerKey = strtolower($key);
        if (array_key_exists($upperKey, $row)) {
            return $row[$upperKey];
        }
        if (array_key_exists($lowerKey, $row)) {
            return $row[$lowerKey];
        }
        return $default;
    }
}
