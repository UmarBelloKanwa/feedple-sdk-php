<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\IrBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for IrBuilder — verifies that every IR construct produces the
 * expected parameterized SQL string and parameter list.
 *
 * Mirrors the IR execution paths exercised in Python's _execute_ir and
 * the build_query_from_ir function.
 */
class IrBuilderTest extends TestCase
{
    // ── Basic SELECT ──────────────────────────────────────────────────────

    public function testSimpleSelectAllColumns(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'users',
            'fields'    => [],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsStringIgnoringCase('SELECT', $sql);
        $this->assertStringContainsStringIgnoringCase('"users".*', $sql);
        $this->assertStringContainsStringIgnoringCase('FROM "users"', $sql);
        $this->assertSame([], $params);
    }

    public function testSelectSpecificFields(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'users',
            'fields'    => [
                ['column' => 'users.id',    'expression' => null, 'alias' => null],
                ['column' => 'users.email', 'expression' => null, 'alias' => 'user_email'],
            ],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('"users"."id"', $sql);
        $this->assertStringContainsString('"users"."email"', $sql);
        $this->assertStringContainsString('AS "user_email"', $sql);
        $this->assertSame([], $params);
    }

    // ── Unsupported operation ─────────────────────────────────────────────

    public function testUnsupportedOperationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported IR operation: insert/');

        IrBuilder::buildQueryFromIr(['operation' => 'insert', 'table' => 'users']);
    }

    public function testMissingTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/IR is missing primary 'table'/");

        IrBuilder::buildQueryFromIr(['operation' => 'query']);
    }

    // ── Aggregate expressions ─────────────────────────────────────────────

    public function testCountAggregation(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [['column' => 'orders.id', 'expression' => 'count', 'alias' => 'total']],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('COUNT("orders"."id")', $sql);
        $this->assertStringContainsString('AS "total"', $sql);
    }

    public function testCountDistinctAggregation(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [['column' => 'orders.user_id', 'expression' => 'COUNT(DISTINCT)', 'alias' => 'unique_users']],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('DISTINCT', $sql);
        $this->assertStringContainsString('"orders"."user_id"', $sql);
    }

    public function testSumAggregation(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [['column' => 'orders.amount', 'expression' => 'sum', 'alias' => 'total_amount']],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('SUM("orders"."amount")', $sql);
    }

    public function testUnsupportedExpressionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported field expression aggregation/');

        IrBuilder::buildQueryFromIr([
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [['column' => 'orders.id', 'expression' => 'stddev', 'alias' => null]],
        ]);
    }

    // ── JOIN clauses ──────────────────────────────────────────────────────

    public function testInnerJoin(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'joins'     => [[
                'table'     => 'users',
                'on_left'   => 'orders.user_id',
                'on_right'  => 'users.id',
                'join_type' => 'INNER',
            ]],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('INNER JOIN "users"', $sql);
        $this->assertStringContainsString('"orders"."user_id" = "users"."id"', $sql);
        $this->assertSame([], $params);
    }

    public function testLeftJoin(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'joins'     => [[
                'table'     => 'users',
                'on_left'   => 'orders.user_id',
                'on_right'  => 'users.id',
                'join_type' => 'LEFT',
            ]],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);
        $this->assertStringContainsString('LEFT JOIN "users"', $sql);
    }

    // ── Filter operators ──────────────────────────────────────────────────

    #[DataProvider('comparisonOperatorProvider')]
    public function testComparisonOperators(string $operator, string $expectedSqlOp): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'filters'   => [['column' => 'orders.status', 'operator' => $operator, 'value' => 'active']],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('"orders"."status"', $sql);
        $this->assertStringContainsString($expectedSqlOp, $sql);
        $this->assertSame(['active'], $params);
    }

    /** @return array<string, array{string, string}> */
    public static function comparisonOperatorProvider(): array
    {
        return [
            'eq'  => ['eq',  '='],
            '='   => ['=',   '='],
            'neq' => ['neq', '!='],
            '!='  => ['!=',  '!='],
            'gt'  => ['gt',  '>'],
            '>'   => ['>',   '>'],
            'gte' => ['gte', '>='],
            '>='  => ['>=',  '>='],
            'lt'  => ['lt',  '<'],
            '<'   => ['<',   '<'],
            'lte' => ['lte', '<='],
            '<='  => ['<=',  '<='],
        ];
    }

    public function testInOperator(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'filters'   => [['column' => 'orders.status', 'operator' => 'in', 'value' => ['pending', 'active']]],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('IN (?, ?)', $sql);
        $this->assertSame(['pending', 'active'], $params);
    }

    public function testLikeOperator(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'users',
            'fields'    => [],
            'filters'   => [['column' => 'users.email', 'operator' => 'like', 'value' => '%@example.com']],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('LIKE ?', $sql);
        $this->assertSame(['%@example.com'], $params);
    }

    public function testIlikeOperator(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'users',
            'fields'    => [],
            'filters'   => [['column' => 'users.name', 'operator' => 'ilike', 'value' => '%john%']],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsStringIgnoringCase('ILIKE', $sql);
        $this->assertSame(['%john%'], $params);
    }

    public function testUnsupportedOperatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported filter operator: between/');

        IrBuilder::buildQueryFromIr([
            'operation' => 'query',
            'table'     => 'orders',
            'filters'   => [['column' => 'orders.id', 'operator' => 'between', 'value' => [1, 10]]],
        ]);
    }

    // ── GROUP BY ──────────────────────────────────────────────────────────

    public function testGroupBy(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [['column' => 'orders.status', 'expression' => null, 'alias' => null]],
            'group_by'  => ['orders.status'],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('GROUP BY "orders"."status"', $sql);
    }

    // ── HAVING ───────────────────────────────────────────────────────────

    public function testHaving(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'group_by'  => ['orders.status'],
            'having'    => [['column' => 'orders.id', 'operator' => 'gt', 'value' => 5]],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('HAVING', $sql);
        $this->assertContains(5, $params);
    }

    // ── ORDER BY ─────────────────────────────────────────────────────────

    public function testOrderByAscending(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'order_by'  => ['orders.created_at ASC'],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);
        $this->assertStringContainsString('"orders"."created_at" ASC', $sql);
    }

    public function testOrderByDescending(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'order_by'  => ['orders.created_at DESC'],
        ];

        ['sql' => $sql] = IrBuilder::buildQueryFromIr($ir);
        $this->assertStringContainsString('"orders"."created_at" DESC', $sql);
    }

    // ── LIMIT / OFFSET ────────────────────────────────────────────────────

    public function testLimitAndOffset(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'limit'     => 10,
            'offset'    => 20,
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('LIMIT ?', $sql);
        $this->assertStringContainsString('OFFSET ?', $sql);
        $this->assertContains(10, $params);
        $this->assertContains(20, $params);
    }

    // ── Multiple filters (AND) ────────────────────────────────────────────

    public function testMultipleFiltersAreAndedTogether(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [],
            'filters'   => [
                ['column' => 'orders.status', 'operator' => 'eq', 'value' => 'active'],
                ['column' => 'orders.amount', 'operator' => 'gt', 'value' => 100],
            ],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->assertStringContainsString('AND', $sql);
        $this->assertSame(['active', 100], $params);
    }

    // ── End-to-end: execute against a real SQLite DB ──────────────────────

    public function testQueryExecutesAgainstSQLite(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), strict: true)) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1, 'Alice', 'alice@example.com')");
        $pdo->exec("INSERT INTO users VALUES (2, 'Bob', 'bob@example.com')");

        $ir = [
            'operation' => 'query',
            'table'     => 'users',
            'fields'    => [
                ['column' => 'users.name', 'expression' => null, 'alias' => null],
            ],
            'filters'   => [['column' => 'users.id', 'operator' => 'eq', 'value' => 1]],
        ];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }
}
