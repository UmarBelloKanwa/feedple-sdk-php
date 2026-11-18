<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\FeedpleWebSocket;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\IrBuilder;
use Feedple\Sdk\Core\PolicyEngine;
use Feedple\Sdk\Core\WebhookBridge;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\FeedpleSDK;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

/**
 * IR v2 (group_keys, relation_aggregates, then_aggregate), ir_version in result
 * payloads, and FeedpleSDK::createWidgetToken().
 *
 * Contract: feedple-ai/docs/contracts-ir-v2-widget-charts.md, sections 1 and 2.
 */
class IrV2Test extends TestCase
{
    // ── group_keys: per-dialect expressions (contract tables 1.1) ──────────

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function groupKeyExpressionProvider(): array
    {
        $pg = '"orders"."created_at"';
        $my = '`orders`.`created_at`';
        $sl = '"orders"."created_at"';

        return [
            // date_bucket
            'pgsql hour'    => ['pgsql', 'interval', 'hour', "date_trunc('hour', {$pg})"],
            'pgsql day'     => ['pgsql', 'interval', 'day', "date_trunc('day', {$pg})"],
            'pgsql week'    => ['pgsql', 'interval', 'week', "date_trunc('week', {$pg})"],
            'pgsql month'   => ['pgsql', 'interval', 'month', "date_trunc('month', {$pg})"],
            'pgsql quarter' => ['pgsql', 'interval', 'quarter', "date_trunc('quarter', {$pg})"],
            'pgsql year'    => ['pgsql', 'interval', 'year', "date_trunc('year', {$pg})"],
            'mysql hour'    => ['mysql', 'interval', 'hour', "DATE_FORMAT({$my}, '%Y-%m-%d %H:00:00')"],
            'mysql day'     => ['mysql', 'interval', 'day', "DATE({$my})"],
            'mysql week'    => ['mysql', 'interval', 'week', "DATE_SUB(DATE({$my}), INTERVAL WEEKDAY({$my}) DAY)"],
            'mysql month'   => ['mysql', 'interval', 'month', "DATE_FORMAT({$my}, '%Y-%m-01')"],
            'mysql quarter' => ['mysql', 'interval', 'quarter', "MAKEDATE(YEAR({$my}), 1) + INTERVAL (QUARTER({$my}) - 1) QUARTER"],
            'mysql year'    => ['mysql', 'interval', 'year', "DATE_FORMAT({$my}, '%Y-01-01')"],
            'sqlite hour'   => ['sqlite', 'interval', 'hour', "strftime('%Y-%m-%d %H:00:00', {$sl})"],
            'sqlite day'    => ['sqlite', 'interval', 'day', "date({$sl})"],
            'sqlite week'   => ['sqlite', 'interval', 'week', "date({$sl}, '-' || ((CAST(strftime('%w', {$sl}) AS INTEGER) + 6) % 7) || ' days')"],
            'sqlite month'  => ['sqlite', 'interval', 'month', "strftime('%Y-%m-01', {$sl})"],
            'sqlite quarter' => ['sqlite', 'interval', 'quarter', "printf('%04d-%02d-01', CAST(strftime('%Y', {$sl}) AS INTEGER), ((CAST(strftime('%m', {$sl}) AS INTEGER) - 1) / 3) * 3 + 1)"],
            'sqlite year'   => ['sqlite', 'interval', 'year', "strftime('%Y-01-01', {$sl})"],
            // date_part
            'pgsql hour_of_day'      => ['pgsql', 'part', 'hour_of_day', "CAST(EXTRACT(HOUR FROM {$pg}) AS INTEGER)"],
            'pgsql day_of_week'      => ['pgsql', 'part', 'day_of_week', "CAST(EXTRACT(ISODOW FROM {$pg}) AS INTEGER)"],
            'pgsql month_of_year'    => ['pgsql', 'part', 'month_of_year', "CAST(EXTRACT(MONTH FROM {$pg}) AS INTEGER)"],
            'pgsql quarter_of_year'  => ['pgsql', 'part', 'quarter_of_year', "CAST(EXTRACT(QUARTER FROM {$pg}) AS INTEGER)"],
            'mysql hour_of_day'      => ['mysql', 'part', 'hour_of_day', "HOUR({$my})"],
            'mysql day_of_week'      => ['mysql', 'part', 'day_of_week', "(WEEKDAY({$my}) + 1)"],
            'mysql month_of_year'    => ['mysql', 'part', 'month_of_year', "MONTH({$my})"],
            'mysql quarter_of_year'  => ['mysql', 'part', 'quarter_of_year', "QUARTER({$my})"],
            'sqlite hour_of_day'     => ['sqlite', 'part', 'hour_of_day', "CAST(strftime('%H', {$sl}) AS INTEGER)"],
            'sqlite day_of_week'     => ['sqlite', 'part', 'day_of_week', "((CAST(strftime('%w', {$sl}) AS INTEGER) + 6) % 7 + 1)"],
            'sqlite month_of_year'   => ['sqlite', 'part', 'month_of_year', "CAST(strftime('%m', {$sl}) AS INTEGER)"],
            'sqlite quarter_of_year' => ['sqlite', 'part', 'quarter_of_year', "((CAST(strftime('%m', {$sl}) AS INTEGER) + 2) / 3)"],
        ];
    }

    #[DataProvider('groupKeyExpressionProvider')]
    public function testGroupKeyExpressionPerDialect(string $driver, string $kind, string $value, string $expr): void
    {
        $key = $kind === 'interval'
            ? ['type' => 'date_bucket', 'column' => 'orders.created_at', 'interval' => $value, 'alias' => 'k']
            : ['type' => 'date_part', 'column' => 'orders.created_at', 'part' => $value, 'alias' => 'k'];

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr([
            'operation'  => 'query',
            'table'      => 'orders',
            'fields'     => [['column' => 'orders.id', 'expression' => 'count', 'alias' => 'n']],
            'group_keys' => [$key],
        ], $driver);

        $q = $driver === 'mysql' ? '`' : '"';
        $this->assertSame(
            "SELECT COUNT({$q}orders{$q}.{$q}id{$q}) AS {$q}n{$q}, {$expr} AS {$q}k{$q} FROM {$q}orders{$q} GROUP BY {$expr}",
            $sql
        );
        $this->assertSame([], $params);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function groupKeysFullQueryProvider(): array
    {
        return [
            'mysql' => ['mysql',
                "SELECT `orders`.`status`, SUM(`orders`.`total_amount`) AS `revenue`, DATE(`orders`.`created_at`) AS `day`, (WEEKDAY(`orders`.`created_at`) + 1) AS `dow` "
                . "FROM `orders` WHERE `orders`.`status` != ? GROUP BY `orders`.`status`, DATE(`orders`.`created_at`), (WEEKDAY(`orders`.`created_at`) + 1) "
                . "ORDER BY `day` ASC LIMIT ? OFFSET ?"],
            'pgsql' => ['pgsql',
                'SELECT "orders"."status", SUM("orders"."total_amount") AS "revenue", date_trunc(\'day\', "orders"."created_at") AS "day", CAST(EXTRACT(ISODOW FROM "orders"."created_at") AS INTEGER) AS "dow" '
                . 'FROM "orders" WHERE "orders"."status" != ? GROUP BY "orders"."status", date_trunc(\'day\', "orders"."created_at"), CAST(EXTRACT(ISODOW FROM "orders"."created_at") AS INTEGER) '
                . 'ORDER BY "day" ASC LIMIT ? OFFSET ?'],
            'sqlite' => ['sqlite',
                'SELECT "orders"."status", SUM("orders"."total_amount") AS "revenue", date("orders"."created_at") AS "day", ((CAST(strftime(\'%w\', "orders"."created_at") AS INTEGER) + 6) % 7 + 1) AS "dow" '
                . 'FROM "orders" WHERE "orders"."status" != ? GROUP BY "orders"."status", date("orders"."created_at"), ((CAST(strftime(\'%w\', "orders"."created_at") AS INTEGER) + 6) % 7 + 1) '
                . 'ORDER BY "day" ASC LIMIT ? OFFSET ?'],
        ];
    }

    #[DataProvider('groupKeysFullQueryProvider')]
    public function testGroupKeysFullQuery(string $driver, string $expected): void
    {
        $result = IrBuilder::buildQueryFromIr([
            'operation'  => 'query',
            'table'      => 'orders',
            'fields'     => [
                ['column' => 'orders.status'],
                ['column' => 'orders.total_amount', 'expression' => 'sum', 'alias' => 'revenue'],
            ],
            'filters'    => [['column' => 'orders.status', 'operator' => 'neq', 'value' => 'cancelled']],
            'group_by'   => ['orders.status'],
            'group_keys' => [
                ['type' => 'date_bucket', 'column' => 'orders.created_at', 'interval' => 'day', 'alias' => 'day'],
                ['type' => 'date_part', 'column' => 'orders.created_at', 'part' => 'day_of_week', 'alias' => 'dow'],
            ],
            'order_by'   => ['day ASC'],
            'limit'      => 14,
            'offset'     => 0,
        ], $driver);

        $this->assertSame($expected, $result['sql']);
        $this->assertSame(['cancelled', 14, 0], $result['params']);
    }

    public function testGroupKeyBareColumnIsQualifiedWithBaseTable(): void
    {
        ['sql' => $sql] = IrBuilder::buildQueryFromIr([
            'operation'  => 'query',
            'table'      => 'orders',
            'fields'     => [['column' => 'id', 'expression' => 'count', 'alias' => 'n']],
            'group_keys' => [['type' => 'date_bucket', 'column' => 'created_at', 'interval' => 'month', 'alias' => 'm']],
        ], 'mysql');

        $this->assertSame(
            "SELECT COUNT(`id`) AS `n`, DATE_FORMAT(`orders`.`created_at`, '%Y-%m-01') AS `m` FROM `orders` GROUP BY DATE_FORMAT(`orders`.`created_at`, '%Y-%m-01')",
            $sql
        );
    }

    // ── relation_aggregates (contract 1.2) ────────────────────────────────

    private static function relationIr(): array
    {
        return [
            'operation' => 'query',
            'table'     => 'customers',
            'fields'    => [['column' => 'customers.id'], ['column' => 'customers.name']],
            'filters'   => [['column' => 'customers.country', 'operator' => '=', 'value' => 'NG']],
            'relation_aggregates' => [[
                'table'       => 'orders',
                'foreign_key' => 'customer_id',
                'references'  => 'id',
                'filters'     => [['column' => 'status', 'operator' => '=', 'value' => 'completed']],
                'aggregates'  => [
                    ['function' => 'COUNT', 'column' => '*', 'alias' => 'order_count'],
                    ['function' => 'SUM', 'column' => 'total_amount', 'alias' => 'lifetime_value'],
                ],
            ]],
            'order_by'  => ['lifetime_value DESC'],
            'limit'     => 10,
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function relationAggregatesProvider(): array
    {
        $dq = 'SELECT "customers"."id", "customers"."name", COALESCE("fp_rel_0"."order_count", 0) AS "order_count", "fp_rel_0"."lifetime_value" AS "lifetime_value" '
            . 'FROM "customers" LEFT JOIN (SELECT "orders"."customer_id" AS fk, COUNT(*) AS "order_count", SUM("orders"."total_amount") AS "lifetime_value" '
            . 'FROM "orders" WHERE "orders"."status" = ? GROUP BY "orders"."customer_id") AS "fp_rel_0" ON "fp_rel_0"."fk" = "customers"."id" '
            . 'WHERE "customers"."country" = ? ORDER BY "lifetime_value" DESC LIMIT ?';

        return [
            'pgsql'  => ['pgsql', $dq],
            'sqlite' => ['sqlite', $dq],
            'mysql'  => ['mysql', str_replace('"', '`', $dq)],
        ];
    }

    #[DataProvider('relationAggregatesProvider')]
    public function testRelationAggregates(string $driver, string $expected): void
    {
        $result = IrBuilder::buildQueryFromIr(self::relationIr(), $driver);

        $this->assertSame($expected, $result['sql']);
        // Relation filter params come first (they appear in the FROM clause), then WHERE, then LIMIT.
        $this->assertSame(['completed', 'NG', 10], $result['params']);
    }

    public function testMultipleRelationsAndAllFunctionsOnMysql(): void
    {
        $ir = [
            'operation' => 'query',
            'table'     => 'customers',
            'fields'    => [['column' => 'customers.id']],
            'relation_aggregates' => [
                [
                    'table' => 'orders', 'foreign_key' => 'orders.customer_id', 'references' => 'customers.id',
                    'filters' => [
                        ['column' => 'orders.created_at', 'operator' => 'gte', 'value' => "CURRENT_DATE - INTERVAL '30 days'"],
                        ['column' => 'status', 'operator' => 'in', 'value' => ['completed', 'shipped']],
                    ],
                    'aggregates' => [
                        ['function' => 'count distinct', 'column' => 'product_id', 'alias' => 'products'],
                        ['function' => 'AVG', 'column' => 'total_amount', 'alias' => 'avg_order'],
                        ['function' => 'MIN', 'column' => 'created_at', 'alias' => 'first_order'],
                        ['function' => 'MAX', 'column' => 'orders.created_at', 'alias' => 'last_order'],
                    ],
                ],
                [
                    'table' => 'tickets', 'foreign_key' => 'customer_id', 'references' => 'id',
                    'aggregates' => [['function' => 'COUNT', 'column' => 'id', 'alias' => 'ticket_count']],
                ],
            ],
            'offset' => 20,
        ];

        $result = IrBuilder::buildQueryFromIr($ir, 'mysql');

        $this->assertSame(
            'SELECT `customers`.`id`, COALESCE(`fp_rel_0`.`products`, 0) AS `products`, `fp_rel_0`.`avg_order` AS `avg_order`, '
            . '`fp_rel_0`.`first_order` AS `first_order`, `fp_rel_0`.`last_order` AS `last_order`, COALESCE(`fp_rel_1`.`ticket_count`, 0) AS `ticket_count` '
            . 'FROM `customers` '
            . 'LEFT JOIN (SELECT `orders`.`customer_id` AS fk, COUNT(DISTINCT `orders`.`product_id`) AS `products`, AVG(`orders`.`total_amount`) AS `avg_order`, '
            . 'MIN(`orders`.`created_at`) AS `first_order`, MAX(`orders`.`created_at`) AS `last_order` FROM `orders` '
            . 'WHERE `orders`.`created_at` >= CURRENT_DATE - INTERVAL 30 DAY AND `orders`.`status` IN (?, ?) GROUP BY `orders`.`customer_id`) AS `fp_rel_0` '
            . 'ON `fp_rel_0`.`fk` = `customers`.`id` '
            . 'LEFT JOIN (SELECT `tickets`.`customer_id` AS fk, COUNT(`tickets`.`id`) AS `ticket_count` FROM `tickets` GROUP BY `tickets`.`customer_id`) AS `fp_rel_1` '
            . 'ON `fp_rel_1`.`fk` = `customers`.`id` '
            . 'LIMIT 18446744073709551615 OFFSET ?',
            $result['sql']
        );
        $this->assertSame(['completed', 'shipped', 20], $result['params']);
    }

    public function testRelationColumnsQualifiedWithOtherTableAreRejected(): void
    {
        $ir = self::relationIr();
        $ir['relation_aggregates'][0]['filters'] = [['column' => 'customers.status', 'operator' => '=', 'value' => 'x']];
        $this->expectException(\InvalidArgumentException::class);
        IrBuilder::buildQueryFromIr($ir, 'pgsql');
    }

    public function testRelationStarOnlyAllowedWithCount(): void
    {
        $ir = self::relationIr();
        $ir['relation_aggregates'][0]['aggregates'] = [['function' => 'SUM', 'column' => '*', 'alias' => 's']];
        $this->expectException(\InvalidArgumentException::class);
        IrBuilder::buildQueryFromIr($ir, 'mysql');
    }

    // ── then_aggregate (contract 1.3) ─────────────────────────────────────

    private static function thenAggregateIr(): array
    {
        return [
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [
                ['column' => 'orders.customer_id'],
                ['column' => 'orders.total_amount', 'expression' => 'sum', 'alias' => 'revenue'],
            ],
            'filters'   => [['column' => 'orders.status', 'operator' => 'eq', 'value' => 'completed']],
            'group_by'  => ['orders.customer_id'],
            'having'    => [['column' => 'COUNT(orders.id)', 'operator' => 'gt', 'value' => 3]],
            'order_by'  => ['revenue DESC'],
            'limit'     => 5,
            'offset'    => 10,
            'then_aggregate' => [
                'group_count_alias' => 'customers',
                'reducers' => [
                    ['function' => 'SUM', 'of' => 'revenue', 'alias' => 'total_revenue'],
                    ['function' => 'AVG', 'of' => 'revenue', 'alias' => 'avg_revenue_per_customer'],
                ],
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function thenAggregateProvider(): array
    {
        $dq = 'SELECT COUNT(*) AS "customers", SUM("g"."revenue") AS "total_revenue", AVG("g"."revenue") AS "avg_revenue_per_customer" '
            . 'FROM (SELECT "orders"."customer_id", SUM("orders"."total_amount") AS "revenue" FROM "orders" WHERE "orders"."status" = ? '
            . 'GROUP BY "orders"."customer_id" HAVING COUNT("orders"."id") > ?) AS "g"';

        return [
            'pgsql'  => ['pgsql', $dq],
            'sqlite' => ['sqlite', $dq],
            'mysql'  => ['mysql', str_replace('"', '`', $dq)],
        ];
    }

    #[DataProvider('thenAggregateProvider')]
    public function testThenAggregateDropsOrderLimitOffset(string $driver, string $expected): void
    {
        $result = IrBuilder::buildQueryFromIr(self::thenAggregateIr(), $driver);

        $this->assertSame($expected, $result['sql']);
        $this->assertSame(['completed', 3], $result['params']);
    }

    public function testThenAggregateOverGroupKeysAndCountOnlyAndReducerCount(): void
    {
        $sql = IrBuilder::buildQueryFromIr([
            'operation'  => 'query',
            'table'      => 'orders',
            'fields'     => [['column' => 'orders.total_amount', 'expression' => 'sum', 'alias' => 'revenue']],
            'group_keys' => [['type' => 'date_bucket', 'column' => 'orders.created_at', 'interval' => 'week', 'alias' => 'week']],
            'then_aggregate' => ['reducers' => [
                ['function' => 'max', 'of' => 'revenue', 'alias' => 'best_week'],
                ['function' => 'COUNT', 'of' => 'week', 'alias' => 'weeks'],
            ]],
        ], 'mysql')['sql'];

        $this->assertSame(
            'SELECT MAX(`g`.`revenue`) AS `best_week`, COUNT(`g`.`week`) AS `weeks` FROM (SELECT SUM(`orders`.`total_amount`) AS `revenue`, '
            . 'DATE_SUB(DATE(`orders`.`created_at`), INTERVAL WEEKDAY(`orders`.`created_at`) DAY) AS `week` FROM `orders` '
            . 'GROUP BY DATE_SUB(DATE(`orders`.`created_at`), INTERVAL WEEKDAY(`orders`.`created_at`) DAY)) AS `g`',
            $sql
        );

        $sql = IrBuilder::buildQueryFromIr([
            'operation' => 'query', 'table' => 'orders',
            'fields'    => [['column' => 'orders.customer_id']],
            'group_by'  => ['orders.customer_id'],
            'then_aggregate' => ['group_count_alias' => 'n'],
        ], 'pgsql')['sql'];
        $this->assertSame(
            'SELECT COUNT(*) AS "n" FROM (SELECT "orders"."customer_id" FROM "orders" GROUP BY "orders"."customer_id") AS "g"',
            $sql
        );
    }

    public function testThenAggregateRequiresCountOrReducers(): void
    {
        $ir = self::thenAggregateIr();
        $ir['then_aggregate'] = ['reducers' => []];
        $this->expectException(\InvalidArgumentException::class);
        IrBuilder::buildQueryFromIr($ir, 'mysql');
    }

    public function testThenAggregateOfMustBeInnerAlias(): void
    {
        $ir = self::thenAggregateIr();
        $ir['then_aggregate']['reducers'] = [['function' => 'SUM', 'of' => 'password', 'alias' => 'x']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a column produced by the inner query');
        IrBuilder::buildQueryFromIr($ir, 'mysql');
    }

    // ── Unsupported features / dialects ───────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>, 1: string|null}> */
    public static function unsupportedProvider(): array
    {
        $base = ['operation' => 'query', 'table' => 'orders', 'fields' => [['column' => 'orders.id']]];
        $gk = static fn(array $k): array => $base + ['group_keys' => [$k + ['column' => 'orders.created_at', 'alias' => 'k']]];
        $rel = static fn(string $fn): array => $base + ['relation_aggregates' => [[
            'table' => 'items', 'foreign_key' => 'order_id', 'references' => 'id',
            'aggregates' => [['function' => $fn, 'column' => 'qty', 'alias' => 'a']],
        ]]];
        $then = $base + ['then_aggregate' => ['group_count_alias' => 'n']];

        return [
            'unknown type'          => [$gk(['type' => 'date_trunc', 'interval' => 'day']), 'mysql'],
            'unknown interval'      => [$gk(['type' => 'date_bucket', 'interval' => 'minute']), 'pgsql'],
            'unknown part'          => [$gk(['type' => 'date_part', 'part' => 'week_of_year']), 'sqlite'],
            'unknown rel function'  => [$rel('MEDIAN'), 'mysql'],
            'unknown reducer'       => [$base + ['then_aggregate' => ['reducers' => [['function' => 'STDDEV', 'of' => 'id', 'alias' => 's']]]], 'mysql'],
            'group_keys on sqlsrv'  => [$gk(['type' => 'date_bucket', 'interval' => 'day']), 'sqlsrv'],
            'relation on oci'       => [$rel('SUM'), 'oci'],
            'then_aggregate no drv' => [$then, null],
            'group_keys no driver'  => [$gk(['type' => 'date_bucket', 'interval' => 'day']), null],
        ];
    }

    #[DataProvider('unsupportedProvider')]
    public function testUnsupportedV2FeatureThrows(array $ir, ?string $driver): void
    {
        try {
            IrBuilder::buildQueryFromIr($ir, $driver);
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Unsupported IR v2 feature', $e->getMessage());
        }
    }

    public function testEmptyV2KeysLeaveV1SqlIdenticalOnAnyDriver(): void
    {
        $v1 = [
            'operation' => 'query', 'table' => 'orders',
            'fields'    => [['column' => 'orders.status'], ['column' => 'orders.id', 'expression' => 'count', 'alias' => 'n']],
            'group_by'  => ['orders.status'], 'order_by' => ['n DESC'], 'offset' => 5,
        ];
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', null] as $driver) {
            $expected = IrBuilder::buildQueryFromIr($v1, $driver);
            $withEmpty = IrBuilder::buildQueryFromIr(
                $v1 + ['group_keys' => [], 'relation_aggregates' => [], 'then_aggregate' => null],
                $driver
            );
            $this->assertSame($expected, $withEmpty);
        }
        $this->assertSame(
            'SELECT `orders`.`status`, COUNT(`orders`.`id`) AS `n` FROM `orders` GROUP BY `orders`.`status` ORDER BY `n` DESC LIMIT 18446744073709551615 OFFSET ?',
            IrBuilder::buildQueryFromIr($v1, 'mysql')['sql']
        );
    }

    // ── Identifier injection in v2 keys ───────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function injectionProvider(): array
    {
        $base = ['operation' => 'query', 'table' => 'orders', 'fields' => [['column' => 'orders.id', 'alias' => 'id']]];
        $gk = ['type' => 'date_bucket', 'column' => 'orders.created_at', 'interval' => 'day', 'alias' => 'day'];
        $rel = [
            'table' => 'items', 'foreign_key' => 'order_id', 'references' => 'id',
            'filters' => [['column' => 'qty', 'operator' => '>', 'value' => 0]],
            'aggregates' => [['function' => 'SUM', 'column' => 'qty', 'alias' => 'qty_total']],
        ];
        $relWith = static function (array $patch) use ($base, $rel): array {
            return $base + ['relation_aggregates' => [array_replace_recursive($rel, $patch)]];
        };
        $then = ['group_count_alias' => 'n', 'reducers' => [['function' => 'SUM', 'of' => 'id', 'alias' => 's']]];

        return [
            'group key column'   => [$base + ['group_keys' => [['column' => 'orders.created_at); DROP TABLE users; --'] + $gk]]],
            'group key table'    => [$base + ['group_keys' => [['column' => 'orders`.x'] + $gk]]],
            'group key alias'    => [$base + ['group_keys' => [['alias' => 'day" FROM users --'] + $gk]]],
            'group key function' => [$base + ['group_keys' => [['column' => 'MAX(orders.created_at)'] + $gk]]],
            'relation table'     => [$relWith(['table' => 'items; DROP TABLE orders'])],
            'relation fk'        => [$relWith(['foreign_key' => 'order_id) OR 1=1 --'])],
            'relation references' => [$relWith(['references' => 'id` OR 1'])],
            'relation agg column' => [$relWith(['aggregates' => [['column' => 'qty) FROM users --']]])],
            'relation agg alias' => [$relWith(['aggregates' => [['alias' => 'x"; DROP']]])],
            'relation filter col' => [$relWith(['filters' => [['column' => 'qty = 1 OR 1']]])],
            'then of'            => [$base + ['then_aggregate' => ['reducers' => [['function' => 'SUM', 'of' => 'id") FROM users --', 'alias' => 's']]]]],
            'then alias'         => [$base + ['then_aggregate' => ['reducers' => [['function' => 'SUM', 'of' => 'id', 'alias' => 's` --']]]]],
            'then count alias'   => [$base + ['then_aggregate' => ['group_count_alias' => 'n" --'] + $then]],
        ];
    }

    #[DataProvider('injectionProvider')]
    public function testInjectionInV2IdentifiersIsRejected(array $ir): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            try {
                $res = IrBuilder::buildQueryFromIr($ir, $driver);
                $this->fail("Injection accepted on {$driver}: {$res['sql']}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString('Unsupported IR v2 feature', $e->getMessage());
            }
        }
    }

    public function testRelationFilterValuesAreBoundNotInterpolated(): void
    {
        $ir = self::relationIr();
        $evil = "x' OR '1'='1";
        $ir['relation_aggregates'][0]['filters'][0]['value'] = $evil;
        $res = IrBuilder::buildQueryFromIr($ir, 'mysql');
        $this->assertStringNotContainsString($evil, $res['sql']);
        $this->assertSame($evil, $res['params'][0]);
    }

    // ── Policy (allowed tables) ───────────────────────────────────────────

    public function testPolicyRejectsDisallowedRelationTable(): void
    {
        $policy = new PolicyEngine(new Identity(name: 'ro', allowedTables: ['customers']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Access denied to table: orders');
        $policy->validateIrAccess(self::relationIr());
    }

    public function testPolicyAllowsPermittedRelationTable(): void
    {
        $policy = new PolicyEngine(new Identity(name: 'ro', allowedTables: ['customers', 'orders']));
        $policy->validateIrAccess(self::relationIr());
        $this->addToAssertionCount(1);
    }

    // ── End-to-end against in-memory sqlite ───────────────────────────────

    private function makeSdk(?Identity $identity = null): FeedpleSDK
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }
        $sdk = new FeedpleSDK(
            apiKey: 'k',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: $identity ?? new Identity(name: 'admin', allTables: true),
            transport: 'webhook',
        );
        $m = new \ReflectionMethod(FeedpleSDK::class, 'getDb');
        if (\PHP_VERSION_ID < 80500) {
            $m->setAccessible(true);
        }
        $pdo = $m->invoke($sdk);
        $pdo->exec(
            'CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT);'
            . 'CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, total_amount INTEGER, status TEXT, created_at TEXT);'
        );
        $pdo->exec("INSERT INTO customers VALUES (1, 'Ada'), (2, 'Bola'), (3, 'Chi')");
        // 2026-11-02 is a Monday, 2026-11-08 a Sunday
        $pdo->exec(
            "INSERT INTO orders VALUES "
            . "(1, 1, 100, 'completed', '2026-11-02 09:15:00'),"
            . "(2, 1, 50, 'completed', '2026-11-02 18:00:00'),"
            . "(3, 1, 70, 'pending', '2026-11-03 10:00:00'),"
            . "(4, 2, 200, 'completed', '2026-11-04 12:00:00'),"
            . "(5, 2, 30, 'completed', '2026-11-08 23:59:00')"
        );
        return $sdk;
    }

    public function testSqliteEndToEndDayBucketsAndDayOfWeek(): void
    {
        $sdk = $this->makeSdk();
        $res = $sdk->executeIr([
            'operation'  => 'query',
            'table'      => 'orders',
            'fields'     => [['column' => 'orders.total_amount', 'expression' => 'sum', 'alias' => 'revenue']],
            'filters'    => [['column' => 'status', 'operator' => '=', 'value' => 'completed']],
            'group_keys' => [
                ['type' => 'date_bucket', 'column' => 'orders.created_at', 'interval' => 'day', 'alias' => 'day'],
                ['type' => 'date_part', 'column' => 'orders.created_at', 'part' => 'day_of_week', 'alias' => 'dow'],
            ],
            'order_by'   => ['day ASC'],
        ]);

        $this->assertSame(2, $res['ir_version']);
        $this->assertSame(3, $res['count']);
        $this->assertEquals([
            ['revenue' => 150, 'day' => '2026-11-02', 'dow' => 1],
            ['revenue' => 200, 'day' => '2026-11-04', 'dow' => 3],
            ['revenue' => 30, 'day' => '2026-11-08', 'dow' => 7],
        ], $res['rows']);
    }

    public function testSqliteEndToEndWeekMonthQuarterHourBuckets(): void
    {
        $sdk = $this->makeSdk();
        $run = function (string $interval) use ($sdk): array {
            return $sdk->executeIr([
                'operation'  => 'query',
                'table'      => 'orders',
                'fields'     => [['column' => 'orders.id', 'expression' => 'count', 'alias' => 'n']],
                'group_keys' => [['type' => 'date_bucket', 'column' => 'created_at', 'interval' => $interval, 'alias' => 'b']],
                'order_by'   => ['b ASC'],
            ])['rows'];
        };

        $this->assertEquals([['n' => 5, 'b' => '2026-11-02']], $run('week'));
        $this->assertEquals([['n' => 5, 'b' => '2026-11-01']], $run('month'));
        $this->assertEquals([['n' => 5, 'b' => '2026-10-01']], $run('quarter'));
        $this->assertEquals([['n' => 5, 'b' => '2026-01-01']], $run('year'));
        $this->assertSame(['2026-11-02 09:00:00', '2026-11-02 18:00:00', '2026-11-03 10:00:00', '2026-11-04 12:00:00', '2026-11-08 23:00:00'],
            array_column($run('hour'), 'b'));
    }

    public function testSqliteEndToEndRelationCountsWithoutFanOut(): void
    {
        $sdk = $this->makeSdk();
        $ir = self::relationIr();
        unset($ir['filters']);
        $ir['order_by'] = ['customers.id ASC'];

        $res = $sdk->executeIr($ir);

        $this->assertSame(3, $res['count']); // base rows never multiplied
        $this->assertEquals([
            ['id' => 1, 'name' => 'Ada', 'order_count' => 2, 'lifetime_value' => 150],
            ['id' => 2, 'name' => 'Bola', 'order_count' => 2, 'lifetime_value' => 230],
            ['id' => 3, 'name' => 'Chi', 'order_count' => 0, 'lifetime_value' => null],
        ], $res['rows']);
    }

    public function testSqliteEndToEndThenAggregate(): void
    {
        $sdk = $this->makeSdk();
        // "How many customers placed more than 1 order, and their total/average revenue"
        $res = $sdk->executeIr([
            'operation' => 'query',
            'table'     => 'orders',
            'fields'    => [
                ['column' => 'orders.customer_id'],
                ['column' => 'orders.total_amount', 'expression' => 'sum', 'alias' => 'revenue'],
            ],
            'group_by'  => ['orders.customer_id'],
            'having'    => [['column' => 'COUNT(orders.id)', 'operator' => '>', 'value' => 1]],
            'order_by'  => ['revenue DESC'],
            'limit'     => 1,
            'then_aggregate' => [
                'group_count_alias' => 'customers',
                'reducers' => [
                    ['function' => 'SUM', 'of' => 'revenue', 'alias' => 'total_revenue'],
                    ['function' => 'AVG', 'of' => 'revenue', 'alias' => 'avg_revenue'],
                ],
            ],
        ]);

        $this->assertSame(1, $res['count']);
        $this->assertEquals([['customers' => 2, 'total_revenue' => 450, 'avg_revenue' => 225]], $res['rows']);

        // group_count_alias alone is a scalar count
        $res = $sdk->executeIr([
            'operation' => 'query', 'table' => 'orders',
            'fields'    => [['column' => 'orders.customer_id']],
            'group_by'  => ['orders.customer_id'],
            'then_aggregate' => ['group_count_alias' => 'n'],
        ]);
        $this->assertSame(2, $res['count']);
        $this->assertEquals([['n' => 2]], $res['rows']);
    }

    public function testExecuteIrPolicyRejectsRelationTable(): void
    {
        $sdk = $this->makeSdk(new Identity(name: 'ro', allowedTables: ['customers']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Access denied to table: orders');
        $sdk->executeIr(self::relationIr());
    }

    // ── ir_version in every transport payload ─────────────────────────────

    public function testV1ResultAlsoReportsIrVersion(): void
    {
        $res = $this->makeSdk()->executeIr(['operation' => 'query', 'table' => 'customers', 'fields' => []]);
        $this->assertSame(2, $res['ir_version']);
    }

    public function testWebhookPayloadIncludesIrVersion(): void
    {
        $secret = 'whsec_test_secret_key_1234567890';
        $bridge = new WebhookBridge($this->makeSdk(), $secret);
        $body = json_encode(['request_id' => 'r1', 'ir' => ['operation' => 'query', 'table' => 'customers', 'fields' => []]]);
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $response = $bridge->handle($body, ['X-Feedple-Signature' => "t={$ts},v1={$sig}"]);

        $this->assertSame('success', $response['body']['status']);
        $this->assertSame(2, $response['body']['ir_version']);
        $this->assertSame(3, $response['body']['count']);
    }

    public function testWebSocketIrResultIncludesIrVersion(): void
    {
        $ws = new FeedpleWebSocket('wss://example.invalid/ws', 'k', Loop::get(), new NullLogger());
        $ws->onIrRequest(static fn(array $ir): array => ['rows' => [['a' => 1]], 'count' => 1, 'duration_ms' => 3]);

        $sent = new \ArrayObject();
        $fakeConn = new class ($sent) {
            public function __construct(private \ArrayObject $sent)
            {
            }
            public function send(string $data): void
            {
                $this->sent[] = json_decode($data, true);
            }
        };
        $prop = new \ReflectionProperty(FeedpleWebSocket::class, 'ws');
        if (\PHP_VERSION_ID < 80500) {
            $prop->setAccessible(true);
        }
        $prop->setValue($ws, $fakeConn);

        $m = new \ReflectionMethod(FeedpleWebSocket::class, 'handleIrRequest');
        if (\PHP_VERSION_ID < 80500) {
            $m->setAccessible(true);
        }
        $m->invoke($ws, ['type' => 'ir.request', 'payload' => ['request_id' => 'req-1', 'ir' => ['operation' => 'query']]]);

        $result = null;
        foreach ($sent as $msg) {
            if (($msg['type'] ?? null) === 'ir.result') {
                $result = $msg;
            }
        }
        $this->assertNotNull($result, 'ir.result was not sent');
        $payload = $result['payload'] ?? $result;
        $this->assertSame(2, $payload['ir_version']);
        $this->assertSame(1, $payload['count']);
    }

    // ── Widget tokens (contract 2) ────────────────────────────────────────

    private static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public function testCreateWidgetTokenIsValidHs256WithClaims(): void
    {
        $before = time();
        $token = FeedpleSDK::createWidgetToken('s3cret', 'wpk_live_abc', 'admin@example.com');
        $after = time();

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        foreach ($parts as $p) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $p); // base64url, no padding
        }

        $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", 's3cret', true)), '+/', '-_'), '=');
        $this->assertTrue(hash_equals($expectedSig, $parts[2]));
        $this->assertFalse(hash_equals(
            rtrim(strtr(base64_encode(hash_hmac('sha256', "{$parts[0]}.{$parts[1]}", 'other', true)), '+/', '-_'), '='),
            $parts[2]
        ));

        $header = json_decode(self::b64urlDecode($parts[0]), true);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);

        $claims = json_decode(self::b64urlDecode($parts[1]), true);
        $this->assertSame('wpk_live_abc', $claims['wpk']);
        $this->assertSame('admin@example.com', $claims['sub']);
        $this->assertSame('admin', $claims['role']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function testCreateWidgetTokenCapsTtlAt24Hours(): void
    {
        $claims = json_decode(self::b64urlDecode(explode('.', FeedpleSDK::createWidgetToken('s', 'wpk_x', '42', 999999))[1]), true);
        $this->assertSame(86400, $claims['exp'] - $claims['iat']);

        $claims = json_decode(self::b64urlDecode(explode('.', FeedpleSDK::createWidgetToken('s', 'wpk_x', '42', 60))[1]), true);
        $this->assertSame(60, $claims['exp'] - $claims['iat']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function badTokenArgsProvider(): array
    {
        return [
            'empty secret'  => ['', 'wpk_x', 'sub', 3600],
            'empty key'     => ['s', '', 'sub', 3600],
            'empty subject' => ['s', 'wpk_x', '', 3600],
            'blank subject' => ['s', 'wpk_x', '   ', 3600],
            'zero ttl'      => ['s', 'wpk_x', 'sub', 0],
            'negative ttl'  => ['s', 'wpk_x', 'sub', -5],
        ];
    }

    #[DataProvider('badTokenArgsProvider')]
    public function testCreateWidgetTokenRejectsBadArguments(string $secret, string $key, string $sub, int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedpleSDK::createWidgetToken($secret, $key, $sub, $ttl);
    }
}
