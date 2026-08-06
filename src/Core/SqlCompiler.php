<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

/**
 * Validates and re-serializes raw SQL strings while enforcing RBAC.
 *
 * Mirrors Python's SQLCompiler (compiler.py).
 *
 * The Python implementation uses sqlglot for a proper AST parse. PHP has no
 * direct equivalent bundled with the language, so this class uses a
 * regex-based table extractor that covers the common SQL patterns.
 * For strict validation, users should use IrBuilder which produces
 * parameterized queries from structured IR instead of raw SQL strings.
 *
 * Python equivalents:
 *   parse(sql)           → parse_one(sql, read=dialect) — we store the sql as-is
 *   extract_tables(ast)  → [t.name for t in ast.find_all(exp.Table)]
 *   validate_access(…)   → check policy per table
 *   compile(sql)         → ast.sql(dialect=dialect)  — we return the original sql
 */
class SqlCompiler
{
    public function __construct(
        private readonly PolicyEngine $policy,
        private readonly string       $dialect = 'postgres',
    ) {
    }

    /**
     * Parse the SQL string and return a normalized representation.
     *
     * In the Python SDK this returns a sqlglot AST. Here we return an
     * associative array with the original SQL and extracted table names,
     * which is sufficient for the access-validation use case.
     *
     * @param  string $sql
     * @return array{sql: string, tables: string[]}
     */
    public function parse(string $sql): array
    {
        return [
            'sql'    => $sql,
            'tables' => $this->extractTables($sql),
        ];
    }

    /**
     * Extract all table names referenced in a SQL statement.
     *
     * Mirrors: extract_tables(ast) -> [t.name for t in ast.find_all(exp.Table)]
     *
     * Handles:
     *   FROM table_name
     *   JOIN table_name
     *   INTO table_name
     *   UPDATE table_name
     *
     * @param  string $sql
     * @return string[]
     */
    public function extractTables(string $sql): array
    {
        // Match: FROM, JOIN (any kind), INTO, UPDATE followed by an identifier
        // The identifier may be optionally aliased (we capture only the table name)
        $pattern = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+([`"\[]?[a-zA-Z_][a-zA-Z0-9_$]*[`"\]]?)/i';
        preg_match_all($pattern, $sql, $matches);

        $tables = array_map(
            // Strip any surrounding quote characters
            static fn(string $t): string => trim($t, '`"[]'),
            $matches[1] ?? []
        );

        return array_values(array_unique(array_filter($tables)));
    }

    /**
     * Validate that all tables referenced in the SQL are accessible
     * under the current identity's policy.
     *
     * Mirrors: validate_access(self, tables: List[str]) -> None
     *
     * @param  string[] $tables
     * @throws \RuntimeException if any table is denied
     */
    public function validateAccess(array $tables): void
    {
        if ($this->policy->identity->allTables) {
            return;
        }

        foreach ($tables as $table) {
            if (!$this->policy->canAccessTable($table)) {
                throw new \RuntimeException("Access denied to table: {$table}");
            }
        }
    }

    /**
     * Parse, validate access, and return the SQL (unchanged).
     *
     * Mirrors: compile(self, sql: str) -> str
     *   ast = self.parse(sql)
     *   tables = self.extract_tables(ast)
     *   self.validate_access(tables)
     *   return ast.sql(dialect=self.dialect)
     *
     * @param  string $sql
     * @return string
     * @throws \RuntimeException if access denied
     */
    public function compile(string $sql): string
    {
        $parsed = $this->parse($sql);
        $this->validateAccess($parsed['tables']);
        // PHP has no dialect transpiler bundled in; we return the original SQL.
        // For dialect translation, integrate a library like PhpMyAdmin's SQL parser.
        return $parsed['sql'];
    }
}
