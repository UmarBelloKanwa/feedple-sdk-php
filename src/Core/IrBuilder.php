<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

use Feedple\Sdk\Core\Exceptions\IrExecutionException;

/**
 * Translates a Feedple IR (Intermediate Representation) query object into
 * a safe, parameterized SQL statement.
 *
 * Mirrors Python's ir_builder.py — build_query_from_ir() and its helpers.
 *
 * The Python SDK uses SQLAlchemy Core Select objects (which bind parameters
 * automatically). In PHP we use PDO prepared statements, which require the
 * SQL string and the parameter bindings to be separated. This builder returns
 * both so callers can do: $pdo->prepare($sql)->execute($params).
 *
 * IR shape (same as Python):
 * {
 *   "operation": "query",
 *   "table": "orders",
 *   "fields": [{"column": "id", "expression": null, "alias": null}],
 *   "joins": [{"table": "users", "on_left": "orders.user_id", "on_right": "users.id", "join_type": "INNER"}],
 *   "filters": [{"column": "status", "operator": "eq", "value": "pending"}],
 *   "group_by": ["orders.status"],
 *   "having": [{"column": "count", "operator": "gt", "value": 5}],
 *   "order_by": ["orders.created_at DESC"],
 *   "limit": 100,
 *   "offset": 0
 * }
 */
class IrBuilder
{
    /** Aggregate functions permitted in field expressions. Mirrors _ALLOWED_FUNCTIONS */
    private const ALLOWED_FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * Build a parameterized SQL query from an IR payload.
     *
     * @param  array<string, mixed> $ir
     * @param  string|null          $driver Database driver name (e.g. 'mysql', 'pgsql', 'sqlite')
     * @return array{sql: string, params: list<mixed>}
     * @throws IrExecutionException
     * @throws \InvalidArgumentException
     */
    public static function buildQueryFromIr(array $ir, ?string $driver = null): array
    {
        $driver = $driver ? strtolower($driver) : null;

        $thenAggregate = $ir['then_aggregate'] ?? null;
        if (!empty($thenAggregate)) {
            self::v2Dialect($driver, 'then_aggregate');
            if (!is_array($thenAggregate)) {
                throw new \InvalidArgumentException("Invalid IR v2 then_aggregate: must be an object");
            }
            // Inner query is built as usual, but WITHOUT order_by / limit / offset.
            $inner = self::buildSelectQuery($ir, $driver, false);
            return self::wrapThenAggregate($thenAggregate, $inner, $ir, $driver);
        }

        return self::buildSelectQuery($ir, $driver, true);
    }

    /**
     * Build the (v1 + v2 group_keys / relation_aggregates) SELECT statement.
     *
     * @param  array<string, mixed> $ir
     * @return array{sql: string, params: list<mixed>}
     */
    private static function buildSelectQuery(array $ir, ?string $driver, bool $withOrderAndLimit): array
    {
        if (($ir['operation'] ?? '') !== 'query') {
            throw new \InvalidArgumentException(
                "Unsupported IR operation: " . ($ir['operation'] ?? 'null')
            );
        }

        $baseTable = $ir['table'] ?? null;
        if (!$baseTable) {
            throw new \InvalidArgumentException("IR is missing primary 'table'");
        }

        $params = [];

        // IR v2 keys (validated up-front so unsupported dialects fail before any SQL is built)
        $groupKeys = $ir['group_keys'] ?? null;
        $relationAggregates = $ir['relation_aggregates'] ?? null;
        $groupKeyExprs = [];   // list<array{expr: string, alias: string}>
        $relationJoins = [];   // list<array{sql: string, params: list<mixed>}>
        $relationSelects = []; // list<string>
        if (!empty($groupKeys)) {
            $dialect = self::v2Dialect($driver, 'group_keys');
            if (!is_array($groupKeys)) {
                throw new \InvalidArgumentException("Invalid IR v2 group_keys: must be a list");
            }
            foreach (array_values($groupKeys) as $key) {
                $groupKeyExprs[] = self::buildGroupKey($key, $baseTable, $dialect, $driver);
            }
        }
        if (!empty($relationAggregates)) {
            self::v2Dialect($driver, 'relation_aggregates');
            if (!is_array($relationAggregates)) {
                throw new \InvalidArgumentException("Invalid IR v2 relation_aggregates: must be a list");
            }
            foreach (array_values($relationAggregates) as $i => $rel) {
                [$joinSql, $joinParams, $selects] = self::buildRelationAggregate($rel, $i, $baseTable, $driver);
                $relationJoins[] = ['sql' => $joinSql, 'params' => $joinParams];
                array_push($relationSelects, ...$selects);
            }
        }

        // 1. SELECT clause
        $selectClause = self::buildSelectClause($ir['fields'] ?? [], $baseTable, $ir['joins'] ?? [], $driver);
        foreach ($groupKeyExprs as $gk) {
            $selectClause .= ', ' . $gk['expr'] . ' AS ' . self::quoteIdentifier($gk['alias'], $driver);
        }
        foreach ($relationSelects as $relSelect) {
            $selectClause .= ', ' . $relSelect;
        }

        // 2. FROM clause
        $sql = "SELECT {$selectClause} FROM " . self::quoteIdentifier($baseTable, $driver);

        // 3. JOIN clauses
        foreach (($ir['joins'] ?? []) as $join) {
            $sql .= self::buildJoinClause($join, $driver);
        }

        // 3b. Multi-source evidence relation derived JOIN
        if (!empty($ir['multi_source_relation'])) {
            $unionParts = [];
            foreach ($ir['multi_source_relation'] as $src) {
                $uTbl = self::quoteIdentifier($src['table'], $driver);
                $uKey = self::quoteIdentifier($src['entity_key'], $driver);
                $uTs  = self::quoteIdentifier($src['timestamp_column'], $driver);
                $unionParts[] = "SELECT {$uKey} AS entity_ref, {$uTs} AS event_at FROM {$uTbl}";
            }
            $unionSql = implode(" UNION ALL ", $unionParts);
            $baseTblQuoted = self::quoteIdentifier($baseTable, $driver);
            $pkQuoted = self::quoteIdentifier('id', $driver);
            $sql     .= " LEFT JOIN ({$unionSql}) AS event_stream ON event_stream.entity_ref = {$baseTblQuoted}.{$pkQuoted}";
        }

        // 3c. IR v2 relation_aggregates: LEFT JOIN pre-aggregated child subqueries (no fan-out)
        foreach ($relationJoins as $rj) {
            $sql   .= $rj['sql'];
            $params = array_merge($params, $rj['params']);
        }

        // 4. WHERE clause (filters AND filter_groups)
        $whereParts = [];
        if (!empty($ir['filters'])) {
            [$whereSql, $whereParams] = self::buildConditionList($ir['filters'], $driver);
            if (trim($whereSql) !== '') {
                $whereParts[] = $whereSql;
                $params = array_merge($params, $whereParams);
            }
        }
        if (!empty($ir['filter_groups'])) {
            foreach ($ir['filter_groups'] as $group) {
                if (empty($group['filters'])) {
                    continue;
                }
                $logic = strtoupper($group['logic'] ?? 'OR');
                $glue = ($logic === 'AND') ? ' AND ' : ' OR ';
                $groupParts = [];
                foreach ($group['filters'] as $f) {
                    [$fSql, $fParams] = self::buildFilterCondition($f, $driver);
                    if (trim($fSql) !== '') {
                        $groupParts[] = $fSql;
                        $params = array_merge($params, $fParams);
                    }
                }
                if (!empty($groupParts)) {
                    $whereParts[] = '(' . implode($glue, $groupParts) . ')';
                }
            }
        }
        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        // 5. GROUP BY clause
        $cols = [];
        if (!empty($ir['group_by'])) {
            $groupBy = is_array($ir['group_by']) ? $ir['group_by'] : [$ir['group_by']];
            foreach ($groupBy as $gb) {
                $gb = trim((string) $gb);
                if ($gb !== '') {
                    $cols[] = self::resolveColumnRef($gb, $driver);
                }
            }
        }
        // IR v2 group_keys are grouped after any v1 group_by columns
        foreach ($groupKeyExprs as $gk) {
            $cols[] = $gk['expr'];
        }
        if (!empty($cols)) {
            $sql .= ' GROUP BY ' . implode(', ', $cols);
        }

        // 6. HAVING clause
        if (!empty($ir['having'])) {
            [$havingSql, $havingParams] = self::buildConditionList($ir['having'], $driver);
            if (trim($havingSql) !== '') {
                $sql    .= " HAVING {$havingSql}";
                $params  = array_merge($params, $havingParams);
            }
        }

        if (!$withOrderAndLimit) {
            return ['sql' => $sql, 'params' => $params];
        }

        // 7. ORDER BY clause
        if (!empty($ir['order_by'])) {
            $orderBy  = is_array($ir['order_by']) ? $ir['order_by'] : [$ir['order_by']];
            $orderStr = [];
            foreach ($orderBy as $ob) {
                $ob = trim((string) $ob);
                if ($ob !== '') {
                    $orderStr[] = self::resolveOrderByRef($ob, $driver);
                }
            }
            if (!empty($orderStr)) {
                $sql .= ' ORDER BY ' . implode(', ', $orderStr);
            }
        }

        // 8. LIMIT and OFFSET
        $hasLimit = isset($ir['limit']) && $ir['limit'] !== null && $ir['limit'] !== '';
        $hasOffset = isset($ir['offset']) && $ir['offset'] !== null && $ir['offset'] !== '';

        if ($hasLimit) {
            $sql     .= ' LIMIT ?';
            $params[] = (int) $ir['limit'];
        } elseif ($hasOffset && $driver === 'mysql') {
            // MySQL/MariaDB syntax forbids OFFSET without LIMIT
            $sql     .= ' LIMIT 18446744073709551615';
        }

        if ($hasOffset) {
            $sql     .= ' OFFSET ?';
            $params[] = (int) $ir['offset'];
        }

        return ['sql' => $sql, 'params' => $params];
    }

    // ── IR v2 helpers ──────────────────────────────────────────────────────

    /** IR version implemented by this builder; reported as "ir_version" in every IR result payload. */
    public const IR_VERSION = 2;

    /** date_bucket expressions per dialect ("{c}" = quoted, table-qualified column). */
    private const DATE_BUCKET_SQL = [
        'postgres' => [
            'hour'    => "date_trunc('hour', {c})",
            'day'     => "date_trunc('day', {c})",
            'week'    => "date_trunc('week', {c})",
            'month'   => "date_trunc('month', {c})",
            'quarter' => "date_trunc('quarter', {c})",
            'year'    => "date_trunc('year', {c})",
        ],
        'mysql' => [
            'hour'    => "DATE_FORMAT({c}, '%Y-%m-%d %H:00:00')",
            'day'     => "DATE({c})",
            'week'    => "DATE_SUB(DATE({c}), INTERVAL WEEKDAY({c}) DAY)",
            'month'   => "DATE_FORMAT({c}, '%Y-%m-01')",
            'quarter' => "MAKEDATE(YEAR({c}), 1) + INTERVAL (QUARTER({c}) - 1) QUARTER",
            'year'    => "DATE_FORMAT({c}, '%Y-01-01')",
        ],
        'sqlite' => [
            'hour'    => "strftime('%Y-%m-%d %H:00:00', {c})",
            'day'     => "date({c})",
            'week'    => "date({c}, '-' || ((CAST(strftime('%w', {c}) AS INTEGER) + 6) % 7) || ' days')",
            'month'   => "strftime('%Y-%m-01', {c})",
            'quarter' => "printf('%04d-%02d-01', CAST(strftime('%Y', {c}) AS INTEGER), ((CAST(strftime('%m', {c}) AS INTEGER) - 1) / 3) * 3 + 1)",
            'year'    => "strftime('%Y-01-01', {c})",
        ],
    ];

    /** date_part expressions per dialect ("{c}" = quoted, table-qualified column). */
    private const DATE_PART_SQL = [
        'postgres' => [
            'hour_of_day'     => "CAST(EXTRACT(HOUR FROM {c}) AS INTEGER)",
            'day_of_week'     => "CAST(EXTRACT(ISODOW FROM {c}) AS INTEGER)",
            'month_of_year'   => "CAST(EXTRACT(MONTH FROM {c}) AS INTEGER)",
            'quarter_of_year' => "CAST(EXTRACT(QUARTER FROM {c}) AS INTEGER)",
        ],
        'mysql' => [
            'hour_of_day'     => "HOUR({c})",
            'day_of_week'     => "(WEEKDAY({c}) + 1)",
            'month_of_year'   => "MONTH({c})",
            'quarter_of_year' => "QUARTER({c})",
        ],
        'sqlite' => [
            'hour_of_day'     => "CAST(strftime('%H', {c}) AS INTEGER)",
            'day_of_week'     => "((CAST(strftime('%w', {c}) AS INTEGER) + 6) % 7 + 1)",
            'month_of_year'   => "CAST(strftime('%m', {c}) AS INTEGER)",
            'quarter_of_year' => "((CAST(strftime('%m', {c}) AS INTEGER) + 2) / 3)",
        ],
    ];

    /** Aggregate functions allowed inside relation_aggregates[].aggregates. */
    private const RELATION_FUNCTIONS = ['COUNT', 'COUNT DISTINCT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /** Reducer functions allowed in then_aggregate.reducers. */
    private const REDUCER_FUNCTIONS = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT'];

    private static function unsupported(string $what): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Unsupported IR v2 feature: {$what}");
    }

    /**
     * Map a PDO driver name to a v2 dialect, or throw for drivers without v2 support.
     */
    private static function v2Dialect(?string $driver, string $feature): string
    {
        switch ($driver) {
            case 'mysql':
                return 'mysql';
            case 'pgsql':
                return 'postgres';
            case 'sqlite':
                return 'sqlite';
        }
        throw self::unsupported("{$feature} is not supported for database driver '" . ($driver ?? 'unknown') . "'");
    }

    /**
     * Resolve a plain column reference ("col" or "table.col") to a quoted, table-qualified column.
     * Function-call syntax is NOT accepted here (unlike resolveColumnRef).
     *
     * @param string|null $requiredTable If set, a qualified reference must use exactly this table.
     */
    private static function qualifiedColumn(mixed $ref, string $defaultTable, ?string $driver, ?string $requiredTable = null): string
    {
        if (!is_string($ref) || trim($ref) === '') {
            throw new \InvalidArgumentException('Invalid IR v2 column reference');
        }
        $ref = trim($ref);
        if (strpos($ref, '.') !== false) {
            [$tablePart, $colPart] = explode('.', $ref, 2);
            if ($requiredTable !== null && $tablePart !== $requiredTable) {
                throw new \InvalidArgumentException(
                    "Invalid IR v2 column reference '{$ref}': must belong to table '{$requiredTable}'"
                );
            }
        } else {
            $tablePart = $defaultTable;
            $colPart   = $ref;
        }
        return self::quoteIdentifier($tablePart, $driver) . '.' . self::quoteIdentifier($colPart, $driver);
    }

    private static function requireAlias(array $spec, string $context): string
    {
        $alias = $spec['alias'] ?? null;
        if (!is_string($alias) || $alias === '') {
            throw new \InvalidArgumentException("Invalid IR v2 {$context}: missing 'alias'");
        }
        return $alias;
    }

    /**
     * Build one group_keys entry.
     *
     * @return array{expr: string, alias: string}
     */
    private static function buildGroupKey(mixed $key, string $baseTable, string $dialect, ?string $driver): array
    {
        if (!is_array($key)) {
            throw new \InvalidArgumentException('Invalid IR v2 group_keys entry: must be an object');
        }
        $alias = self::requireAlias($key, 'group_keys entry');
        self::quoteIdentifier($alias, $driver);
        $col  = self::qualifiedColumn($key['column'] ?? null, $baseTable, $driver);
        $type = $key['type'] ?? null;

        if ($type === 'date_bucket') {
            $interval = $key['interval'] ?? null;
            if (!is_string($interval) || !isset(self::DATE_BUCKET_SQL[$dialect][$interval])) {
                throw self::unsupported('date_bucket interval ' . json_encode($interval));
            }
            $template = self::DATE_BUCKET_SQL[$dialect][$interval];
        } elseif ($type === 'date_part') {
            $part = $key['part'] ?? null;
            if (!is_string($part) || !isset(self::DATE_PART_SQL[$dialect][$part])) {
                throw self::unsupported('date_part part ' . json_encode($part));
            }
            $template = self::DATE_PART_SQL[$dialect][$part];
        } else {
            throw self::unsupported('group_keys type ' . json_encode($type));
        }

        return ['expr' => str_replace('{c}', $col, $template), 'alias' => $alias];
    }

    /**
     * Build the LEFT JOIN (pre-aggregated subquery) and SELECT items for relation_aggregates[$index].
     *
     * @return array{0: string, 1: list<mixed>, 2: list<string>}
     */
    private static function buildRelationAggregate(mixed $rel, int $index, string $baseTable, ?string $driver): array
    {
        if (!is_array($rel)) {
            throw new \InvalidArgumentException('Invalid IR v2 relation_aggregates entry: must be an object');
        }
        $table = $rel['table'] ?? null;
        if (!is_string($table) || $table === '') {
            throw new \InvalidArgumentException("Invalid IR v2 relation_aggregates entry: missing 'table'");
        }
        $tableSql = self::quoteIdentifier($table, $driver);
        $fkSql    = self::qualifiedColumn($rel['foreign_key'] ?? null, $table, $driver, $table);
        $refSql   = self::qualifiedColumn($rel['references'] ?? null, $baseTable, $driver, $baseTable);
        $relAlias = self::quoteIdentifier("fp_rel_{$index}", $driver);

        $aggregates = $rel['aggregates'] ?? null;
        if (!is_array($aggregates) || empty($aggregates)) {
            throw new \InvalidArgumentException("Invalid IR v2 relation_aggregates entry: 'aggregates' must be a non-empty list");
        }

        $innerSelects = ["{$fkSql} AS fk"];
        $outerSelects = [];
        foreach ($aggregates as $agg) {
            if (!is_array($agg)) {
                throw new \InvalidArgumentException('Invalid IR v2 relation aggregate: must be an object');
            }
            $fn = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) ($agg['function'] ?? ''))));
            if (!in_array($fn, self::RELATION_FUNCTIONS, true)) {
                throw self::unsupported('relation aggregate function ' . json_encode($agg['function'] ?? null));
            }
            $alias    = self::requireAlias($agg, 'relation aggregate');
            $aliasSql = self::quoteIdentifier($alias, $driver);
            $column   = $agg['column'] ?? null;

            if ($column === '*') {
                if ($fn !== 'COUNT') {
                    throw new \InvalidArgumentException("Invalid IR v2 relation aggregate: '*' is only allowed with COUNT");
                }
                $expr = 'COUNT(*)';
            } else {
                $colSql = self::qualifiedColumn($column, $table, $driver, $table);
                $expr   = $fn === 'COUNT DISTINCT' ? "COUNT(DISTINCT {$colSql})" : "{$fn}({$colSql})";
            }
            $innerSelects[] = "{$expr} AS {$aliasSql}";

            $outerRef = "{$relAlias}.{$aliasSql}";
            $outerSelects[] = str_starts_with($fn, 'COUNT')
                ? "COALESCE({$outerRef}, 0) AS {$aliasSql}"
                : "{$outerRef} AS {$aliasSql}";
        }

        $sub = 'SELECT ' . implode(', ', $innerSelects) . " FROM {$tableSql}";
        $params = [];
        $filters = $rel['filters'] ?? [];
        if (!empty($filters)) {
            if (!is_array($filters)) {
                throw new \InvalidArgumentException("Invalid IR v2 relation_aggregates entry: 'filters' must be a list");
            }
            $qualified = [];
            foreach ($filters as $f) {
                if (!is_array($f) || !isset($f['column'])) {
                    throw new \InvalidArgumentException("Filter missing 'column'");
                }
                $col = trim((string) $f['column']);
                if (strpos($col, '.') !== false) {
                    [$tPart] = explode('.', $col, 2);
                    if ($tPart !== $table) {
                        throw new \InvalidArgumentException(
                            "Invalid IR v2 column reference '{$col}': must belong to table '{$table}'"
                        );
                    }
                } else {
                    $col = "{$table}.{$col}";
                }
                $f['column'] = $col;
                $qualified[] = $f;
            }
            [$whereSql, $params] = self::buildConditionList($qualified, $driver);
            if (trim($whereSql) !== '') {
                $sub .= " WHERE {$whereSql}";
            }
        }
        $sub .= " GROUP BY {$fkSql}";

        $fkRef = $relAlias . '.' . self::quoteIdentifier('fk', $driver);
        $join  = " LEFT JOIN ({$sub}) AS {$relAlias} ON {$fkRef} = {$refSql}";

        return [$join, $params, $outerSelects];
    }

    /**
     * Wrap an inner (grouped) query for then_aggregate.
     *
     * @param  array<string, mixed>                     $spec
     * @param  array{sql: string, params: list<mixed>}  $inner
     * @param  array<string, mixed>                     $ir
     * @return array{sql: string, params: list<mixed>}
     */
    private static function wrapThenAggregate(array $spec, array $inner, array $ir, ?string $driver): array
    {
        $g = self::quoteIdentifier('g', $driver);
        $selects = [];

        $countAlias = $spec['group_count_alias'] ?? null;
        if ($countAlias !== null && $countAlias !== '') {
            if (!is_string($countAlias)) {
                throw new \InvalidArgumentException('Invalid IR v2 then_aggregate: group_count_alias must be a string');
            }
            $selects[] = 'COUNT(*) AS ' . self::quoteIdentifier($countAlias, $driver);
        }

        $reducers = $spec['reducers'] ?? [];
        if (!is_array($reducers)) {
            throw new \InvalidArgumentException("Invalid IR v2 then_aggregate: 'reducers' must be a list");
        }
        $innerNames = self::innerOutputNames($ir);
        foreach ($reducers as $r) {
            if (!is_array($r)) {
                throw new \InvalidArgumentException('Invalid IR v2 then_aggregate reducer: must be an object');
            }
            $fn = strtoupper(trim((string) ($r['function'] ?? '')));
            if (!in_array($fn, self::REDUCER_FUNCTIONS, true)) {
                throw self::unsupported('then_aggregate reducer function ' . json_encode($r['function'] ?? null));
            }
            $of = $r['of'] ?? null;
            if (!is_string($of) || $of === '') {
                throw new \InvalidArgumentException("Invalid IR v2 then_aggregate reducer: missing 'of'");
            }
            $ofSql = self::quoteIdentifier($of, $driver);
            if ($innerNames !== null && !in_array($of, $innerNames, true)) {
                throw new \InvalidArgumentException(
                    "Invalid IR v2 then_aggregate reducer: '{$of}' is not a column produced by the inner query"
                );
            }
            $alias = self::requireAlias($r, 'then_aggregate reducer');
            $selects[] = "{$fn}({$g}.{$ofSql}) AS " . self::quoteIdentifier($alias, $driver);
        }

        if (empty($selects)) {
            throw new \InvalidArgumentException(
                'Invalid IR v2 then_aggregate: at least one of group_count_alias or reducers is required'
            );
        }

        return [
            'sql'    => 'SELECT ' . implode(', ', $selects) . " FROM ({$inner['sql']}) AS {$g}",
            'params' => $inner['params'],
        ];
    }

    /**
     * Output column names of the inner query, or null when they cannot be determined (SELECT *).
     *
     * @param  array<string, mixed> $ir
     * @return list<string>|null
     */
    private static function innerOutputNames(array $ir): ?array
    {
        $fields = $ir['fields'] ?? [];
        if (empty($fields)) {
            return null;
        }
        $names = [];
        foreach ($fields as $f) {
            if (!empty($f['alias'])) {
                $names[] = (string) $f['alias'];
            } elseif (($f['expression'] ?? null) === null && isset($f['column'])
                && preg_match('/^(?:[a-zA-Z0-9_$]+\.)?([a-zA-Z0-9_$]+)$/', (string) $f['column'], $m)) {
                $names[] = $m[1];
            }
        }
        foreach (($ir['group_keys'] ?? []) ?: [] as $k) {
            if (is_array($k) && isset($k['alias'])) {
                $names[] = (string) $k['alias'];
            }
        }
        foreach (($ir['relation_aggregates'] ?? []) ?: [] as $rel) {
            foreach ((is_array($rel) ? ($rel['aggregates'] ?? []) : []) as $a) {
                if (is_array($a) && isset($a['alias'])) {
                    $names[] = (string) $a['alias'];
                }
            }
        }
        return $names;
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Build the SELECT column list string.
     *
     * Mirrors: _build_select_columns(fields, tables_by_name)
     *
     * When no fields are given, selects all columns from all involved tables
     * (mirrors: return [tables_by_name[name] for name in tables_by_name]).
     */
    private static function buildSelectClause(array $fields, string $baseTable, array $joins, ?string $driver = null): string
    {
        if (empty($fields)) {
            // Select all columns: base table + joined tables
            $tables   = [$baseTable, ...array_column($joins, 'table')];
            $selects  = array_map(
                static fn(string $t): string => self::quoteIdentifier($t, $driver) . '.*',
                $tables
            );
            return implode(', ', $selects);
        }

        $parts = [];
        foreach ($fields as $field) {
            if (!isset($field['column'])) {
                throw new \InvalidArgumentException("Field missing 'column' key");
            }
            $colRef  = $field['column'];
            $colSql  = self::resolveColumnRef($colRef, $driver);
            $exprRaw = $field['expression'] ?? null;

            if ($exprRaw !== null) {
                $exprClean = strtolower((string) $exprRaw);
                $colSql    = self::applyAggregation($colSql, $colRef, $exprClean, $exprRaw);
            }

            if (!empty($field['alias'])) {
                $colSql .= ' AS ' . self::quoteIdentifier((string) $field['alias'], $driver);
            }

            $parts[] = $colSql;
        }

        return implode(', ', $parts);
    }

    /**
     * Wrap a column reference in an aggregate function expression.
     *
     * Mirrors: the expr_raw / _ALLOWED_FUNCTIONS logic in _build_select_columns
     */
    private static function applyAggregation(
        string $colSql,
        string $colRef,
        string $exprClean,
        string $exprRaw
    ): string {
        if (strpos($exprClean, 'count') !== false) {
            if (strpos($exprClean, 'distinct') !== false) {
                return "COUNT(DISTINCT {$colSql})";
            }
            return "COUNT({$colSql})";
        }

        foreach (self::ALLOWED_FUNCTIONS as $fn) {
            if ($exprClean === $fn) {
                return strtoupper($fn) . "({$colSql})";
            }
        }

        throw new \InvalidArgumentException("Unsupported field expression aggregation: {$exprRaw}");
    }

    /**
     * Build a single JOIN clause string.
     *
     * Mirrors: the join loop in build_query_from_ir
     */
    private static function buildJoinClause(array $join, ?string $driver = null): string
    {
        if (!isset($join['table'])) {
            throw new \InvalidArgumentException("Join missing 'table'");
        }
        if (!isset($join['on_left'])) {
            throw new \InvalidArgumentException("Join missing 'on_left'");
        }
        if (!isset($join['on_right'])) {
            throw new \InvalidArgumentException("Join missing 'on_right'");
        }
        $joinTable = $join['table'];
        $onLeft    = $join['on_left'];
        $onRight   = $join['on_right'];

        $joinType  = strtoupper($join['join_type'] ?? 'INNER');
        $isOuter   = in_array($joinType, ['LEFT', 'LEFT OUTER'], true);

        $keyword   = $isOuter ? 'LEFT JOIN' : 'INNER JOIN';
        $leftSql   = self::resolveColumnRef($onLeft, $driver);
        $rightSql  = self::resolveColumnRef($onRight, $driver);

        return " {$keyword} " . self::quoteIdentifier($joinTable, $driver) . " ON {$leftSql} = {$rightSql}";
    }

    /**
     * Build a list of filter conditions joined by AND.
     *
     * Mirrors: and_(*[_build_filter_condition(f, tables_by_name) for f in filters])
     *
     * @param  array<int, array<string, mixed>> $conditions  (filters or having)
     * @param  string|null                      $driver
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildConditionList(array $conditions, ?string $driver = null): array
    {
        $parts  = [];
        $params = [];

        foreach ($conditions as $condition) {
            [$condSql, $condParams] = self::buildFilterCondition($condition, $driver);
            if (trim($condSql) !== '') {
                $parts[]  = $condSql;
                $params   = array_merge($params, $condParams);
            }
        }

        return [implode(' AND ', $parts), $params];
    }

    /**
     * Build a single filter condition (column operator value).
     *
     * Mirrors: _build_filter_condition(f, tables_by_name)
     *
     * @param  array<string, mixed> $filter
     * @param  string|null          $driver
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildFilterCondition(array $filter, ?string $driver = null): array
    {
        if (!isset($filter['operator'])) {
            throw new \InvalidArgumentException("Filter missing 'operator'");
        }
        if (!isset($filter['column'])) {
            throw new \InvalidArgumentException("Filter missing 'column'");
        }
        $operator = strtolower($filter['operator']);
        $colRef   = $filter['column'];
        $value    = $filter['value'] ?? null;
        $colSql   = self::resolveColumnRef($colRef, $driver);

        // Comparison operators (mirrors _OPERATOR_BUILDERS dict)
        $comparisonMap = [
            'eq'  => '=',  '='  => '=',
            'neq' => '!=', '!=' => '!=',
            'gt'  => '>',  '>'  => '>',
            'gte' => '>=', '>=' => '>=',
            'lt'  => '<',  '<'  => '<',
            'lte' => '<=', '<=' => '<=',
        ];

        if (isset($comparisonMap[$operator])) {
            // Check if value is a raw SQL datetime expression like CURRENT_TIMESTAMP - INTERVAL '30 days' or NOW()
            // The WHOLE value must be a known-safe datetime expression (optionally +/- one
            // INTERVAL literal). A prefix-only match would let "NOW(); DROP TABLE x" through
            // as raw SQL; anything else is bound as a normal parameter below.
            if (is_string($value) && preg_match('/^(CURRENT_TIMESTAMP|NOW\(\)|CURRENT_DATE)(\s*[-+]\s*INTERVAL\s*[\'"]\d+\s*[a-zA-Z]+[\'"])?$/i', trim($value))) {
                $dtExpr = $value;
                if ($driver === 'mysql') {
                    $dtExpr = preg_replace_callback(
                        '/INTERVAL\s*[\'"](\d+)\s*([a-zA-Z]+)[\'"]/i',
                        function ($m) {
                            $unit = rtrim(strtoupper($m[2]), 'S');
                            return "INTERVAL {$m[1]} {$unit}";
                        },
                        $dtExpr
                    );
                }
                return ["{$colSql} {$comparisonMap[$operator]} {$dtExpr}", []];
            }
            return ["{$colSql} {$comparisonMap[$operator]} ?", [$value]];
        }

        // IN / NOT IN operators
        if (in_array($operator, ['in', 'in_'], true)) {
            if (!is_array($value)) {
                throw new \InvalidArgumentException("Value for 'in' operator must be an array");
            }
            if (empty($value)) {
                return ["1 = 0", []];
            }
            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            return ["{$colSql} IN ({$placeholders})", array_values($value)];
        }

        if (in_array($operator, ['not in', 'not_in', 'not_in_'], true)) {
            if (!is_array($value)) {
                throw new \InvalidArgumentException("Value for 'not in' operator must be an array");
            }
            if (empty($value)) {
                return ["1 = 1", []];
            }
            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            return ["{$colSql} NOT IN ({$placeholders})", array_values($value)];
        }

        // LIKE / ILIKE operators (mirrors col.like / col.ilike)
        if ($operator === 'like') {
            return ["{$colSql} LIKE ?", [$value]];
        }
        if ($operator === 'ilike') {
            if ($driver === 'mysql') {
                return ["LOWER({$colSql}) LIKE LOWER(?)", [$value]];
            }
            return ["{$colSql} ILIKE ?", [$value]];
        }

        // IS NULL / IS NOT NULL operators
        if (in_array($operator, ['is_null', 'is null'], true)) {
            return ["{$colSql} IS NULL", []];
        }
        if (in_array($operator, ['is_not_null', 'is not null'], true)) {
            return ["{$colSql} IS NOT NULL", []];
        }

        throw new \InvalidArgumentException("Unsupported filter operator: {$operator}");
    }

    /**
     * Resolve a column reference string like "orders.id" or just "id"
     * into a properly quoted SQL expression.
     *
     * Mirrors: _resolve_column(field, tables_by_name)
     *
     * The Python version uses SQLAlchemy column() objects with _selectable;
     * in PHP we produce a quoted SQL string directly.
     */
    private static function resolveColumnRef(string $ref, ?string $driver = null): string
    {
        if (preg_match('/^(MAX|MIN|COUNT|SUM|AVG)\s*\((.+)\)$/i', trim($ref), $matches)) {
            $fn = strtoupper($matches[1]);
            $inner = self::resolveColumnRef($matches[2], $driver);
            return "{$fn}({$inner})";
        }
        if (strpos($ref, '.') !== false) {
            [$tablePart, $colPart] = explode('.', $ref, 2);
            return self::quoteIdentifier($tablePart, $driver) . '.' . self::quoteIdentifier($colPart, $driver);
        }
        return self::quoteIdentifier($ref, $driver);
    }

    /**
     * Parse an order-by string like "orders.created_at DESC" into
     * a SQL ORDER BY fragment.
     *
     * Mirrors: _resolve_order_by_column(order_string, tables_by_name)
     */
    private static function resolveOrderByRef(string $orderString, ?string $driver = null): string
    {
        $parts   = preg_split('/\s+/', trim($orderString));
        $colSql  = self::resolveColumnRef($parts[0], $driver);
        $direction = isset($parts[1]) && strtoupper($parts[1]) === 'DESC' ? 'DESC' : 'ASC';
        return "{$colSql} {$direction}";
    }

    /**
     * Quote a SQL identifier (table or column name).
     * Backticks for MySQL/MariaDB; double quotes for Postgres/SQLite/default.
     * Only allows safe identifier characters to prevent injection.
     */
    private static function quoteIdentifier(string $name, ?string $driver = null): string
    {
        if (!preg_match('/^[a-zA-Z0-9_$]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
        }
        if ($driver === 'mysql') {
            return '`' . $name . '`';
        }
        return '"' . $name . '"';
    }
}
