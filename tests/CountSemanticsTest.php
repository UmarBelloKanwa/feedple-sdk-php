<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\FeedpleSDK;
use PHPUnit\Framework\TestCase;

class CountSemanticsTest extends TestCase
{
    private FeedpleSDK $sdk;

    protected function setUp(): void
    {
        $this->sdk = new FeedpleSDK(
            apiKey: 'k',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'a', allTables: true),
            transport: 'webhook',
        );
        $m = new \ReflectionMethod(FeedpleSDK::class, 'getDb');
        $pdo = $m->invoke($this->sdk);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7,'Al'),(8,'Bo')");
    }

    private function exec(array $ir): array
    {
        return $this->sdk->executeIr(['operation' => 'query', 'table' => 'users'] + $ir);
    }

    public function testSingleNumericColumnIsNotACount(): void
    {
        foreach ([7, 8] as $id) {
            $r = $this->exec(['fields' => [['column' => 'id']], 'filters' => [['column' => 'id', 'operator' => 'eq', 'value' => $id]]]);
            $this->assertSame(1, $r['count']); // used to report the id value itself
        }
    }

    public function testLoneCountReportsItsValue(): void
    {
        $this->assertSame(2, $this->exec(['fields' => [['column' => 'id', 'expression' => 'count']]])['count']);
    }

    public function testCountWithGroupByOrSumIsRowCount(): void
    {
        $r = $this->exec(['fields' => [['column' => 'id', 'expression' => 'count']], 'group_by' => ['name'],
            'filters' => [['column' => 'id', 'operator' => 'eq', 'value' => 7]]]);
        $this->assertSame(1, $r['count']);
        $this->assertSame(1, $this->exec(['fields' => [['column' => 'id', 'expression' => 'sum']]])['count']);
    }
}
