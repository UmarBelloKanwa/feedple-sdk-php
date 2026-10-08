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
        if (($ir['operation'] ?? '') !== 'query') {
            throw new \InvalidArgumentException(
                "Unsupported IR operation: " . ($ir['operation'] ?? 'null')
            );
        }

        $baseTable = $ir['table'] ?? null;
        if (!$baseTable) {
            throw new \InvalidArgumentException("IR is missing primary 'table'");
        }

        $driver = $driver ? strtolower($driver) : null;
        $params = [];

        // 1. SELECT clause
        $selectClause = self::buildSelectClause($ir['fields'] ?? [], $baseTable, $ir['joins'] ?? [], $driver);

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
        if (!empty($ir['group_by'])) {
            $groupBy = is_array($ir['group_by']) ? $ir['group_by'] : [$ir['group_by']];
            $cols    = [];
            foreach ($groupBy as $gb) {
                $gb = trim((string) $gb);
                if ($gb !== '') {
                    $cols[] = self::resolveColumnRef($gb, $driver);
                }
            }
            if (!empty($cols)) {
                $sql .= ' GROUP BY ' . implode(', ', $cols);
            }
        }

        // 6. HAVING clause
        if (!empty($ir['having'])) {
            [$havingSql, $havingParams] = self::buildConditionList($ir['having'], $driver);
            if (trim($havingSql) !== '') {
                $sql    .= " HAVING {$havingSql}";
                $params  = array_merge($params, $havingParams);
            }
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
            if (is_string($value) && preg_match('/^(CURRENT_TIMESTAMP|NOW\(\)|CURRENT_DATE)\b/i', trim($value))) {
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
