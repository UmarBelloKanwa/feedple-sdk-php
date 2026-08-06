<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\SchemaServices;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Tests for SchemaServices — schema inspection, hashing, sync detection,
 * and sensitive column filtering.
 *
 * Uses an in-memory SQLite database for full end-to-end introspection tests.
 * Tests requiring a live DB connection are skipped when pdo_sqlite is not
 * available in the test environment.
 */
class SchemaServicesTest extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        if (in_array('sqlite', \PDO::getAvailableDrivers(), strict: true)) {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            // Create a minimal schema
            $this->pdo->exec(
                'CREATE TABLE users (
                    id       INTEGER PRIMARY KEY,
                    name     TEXT    NOT NULL,
                    email    TEXT    UNIQUE,
                    password TEXT
                )'
            );
            $this->pdo->exec(
                'CREATE TABLE orders (
                    id      INTEGER PRIMARY KEY,
                    user_id INTEGER,
                    amount  REAL,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )'
            );
        }
    }

    // ── generateSchemaHash (no DB required) ───────────────────────────────

    public function testHashIsDeterministicSha256(): void
    {
        $schema = ['users' => ['columns' => [['name' => 'id', 'type' => 'INTEGER']]]];
        $hash1  = SchemaServices::generateSchemaHash($schema);
        $hash2  = SchemaServices::generateSchemaHash($schema);

        $this->assertSame($hash1, $hash2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1);
    }

    public function testDifferentSchemasProduceDifferentHashes(): void
    {
        $schema1 = ['users' => ['columns' => [['name' => 'id', 'type' => 'INTEGER']]]];
        $schema2 = ['users' => ['columns' => [['name' => 'id', 'type' => 'TEXT']]]];

        $this->assertNotSame(
            SchemaServices::generateSchemaHash($schema1),
            SchemaServices::generateSchemaHash($schema2)
        );
    }

    public function testSameSchemaAlwaysProducesSameHash(): void
    {
        $schema = ['users' => ['a' => 1, 'b' => 2]];
        $this->assertSame(
            SchemaServices::generateSchemaHash($schema),
            SchemaServices::generateSchemaHash($schema)
        );
    }

    // ── shouldSyncSchema (no DB required) ─────────────────────────────────

    public function testShouldSyncReturnsFalseForIdenticalSchemas(): void
    {
        $schema = ['users' => ['columns' => [['name' => 'id', 'type' => 'INTEGER']]]];
        $this->assertFalse(SchemaServices::shouldSyncSchema($schema, $schema));
    }

    public function testShouldSyncReturnsTrueWhenSchemasdiffer(): void
    {
        $old = ['users' => ['columns' => [['name' => 'id', 'type' => 'INTEGER']]]];
        $new = ['users' => ['columns' => [['name' => 'id', 'type' => 'TEXT']]]];
        $this->assertTrue(SchemaServices::shouldSyncSchema($old, $new));
    }

    // ── filterSensitiveColumns (no DB required) ───────────────────────────

    public function testFilterRemovesPasswordColumn(): void
    {
        $schema = [
            'users' => [
                'columns' => [
                    ['name' => 'id',       'type' => 'INTEGER'],
                    ['name' => 'email',    'type' => 'TEXT'],
                    ['name' => 'password', 'type' => 'TEXT'],
                    ['name' => 'token',    'type' => 'TEXT'],
                ],
            ],
        ];

        $filtered = SchemaServices::filterSensitiveColumns($schema);
        $names    = array_column($filtered['users']['columns'], 'name');

        $this->assertContains('id',    $names);
        $this->assertContains('email', $names);
        $this->assertNotContains('password', $names);
        $this->assertNotContains('token',    $names);
    }

    public function testFilterIsCaseInsensitive(): void
    {
        $schema = [
            'accounts' => [
                'columns' => [
                    ['name' => 'PASSWORD', 'type' => 'TEXT'],
                    ['name' => 'Secret',   'type' => 'TEXT'],
                    ['name' => 'name',     'type' => 'TEXT'],
                ],
            ],
        ];

        $filtered = SchemaServices::filterSensitiveColumns($schema);
        $names    = array_column($filtered['accounts']['columns'], 'name');

        $this->assertNotContains('PASSWORD', $names);
        $this->assertNotContains('Secret',   $names);
        $this->assertContains('name',        $names);
    }

    public function testFilterPreservesTablesWithNoSensitiveColumns(): void
    {
        $schema = [
            'products' => [
                'columns' => [
                    ['name' => 'id',    'type' => 'INTEGER'],
                    ['name' => 'title', 'type' => 'TEXT'],
                    ['name' => 'price', 'type' => 'REAL'],
                ],
            ],
        ];

        $filtered = SchemaServices::filterSensitiveColumns($schema);
        $this->assertCount(3, $filtered['products']['columns']);
    }

    // ── getSchema (live SQLite introspection — skipped without pdo_sqlite) ─

    /** @return bool */
    private function hasSqlite(): bool
    {
        return $this->pdo !== null;
    }

    public function testGetSchemaReturnsAllTablesWhenAllTablesTrue(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $identity = new Identity(name: 'admin', allTables: true);
        $schema   = SchemaServices::getSchema($this->pdo, $identity);

        $this->assertArrayHasKey('users',  $schema);
        $this->assertArrayHasKey('orders', $schema);
    }

    public function testGetSchemaReturnsOnlyAllowedTables(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $identity = new Identity(name: 'reader', allowedTables: ['users']);
        $schema   = SchemaServices::getSchema($this->pdo, $identity);

        $this->assertArrayHasKey('users',  $schema);
        $this->assertArrayNotHasKey('orders', $schema);
    }

    public function testGetSchemaThrowsForNonExistentAllowedTable(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessageMatches("/Table 'nonexistent' does not exist/");

        $identity = new Identity(name: 'reader', allowedTables: ['nonexistent']);
        SchemaServices::getSchema($this->pdo, $identity);
    }

    public function testGetSchemaIncludesColumnMetadata(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $identity = new Identity(name: 'admin', allTables: true);
        $schema   = SchemaServices::getSchema($this->pdo, $identity);

        $columns = $schema['users']['columns'];
        $names   = array_column($columns, 'name');

        $this->assertContains('id',       $names);
        $this->assertContains('name',     $names);
        $this->assertContains('email',    $names);
        $this->assertContains('password', $names);

        foreach ($columns as $col) {
            $this->assertArrayHasKey('name',     $col);
            $this->assertArrayHasKey('type',     $col);
            $this->assertArrayHasKey('nullable', $col);
            $this->assertArrayHasKey('default',  $col);
        }
    }

    public function testGetSchemaIncludesPrimaryKey(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $identity = new Identity(name: 'admin', allTables: true);
        $schema   = SchemaServices::getSchema($this->pdo, $identity);

        $this->assertContains('id', $schema['users']['primary_key']);
    }

    public function testGetSchemaIncludesForeignKeys(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $identity = new Identity(name: 'admin', allTables: true);
        $schema   = SchemaServices::getSchema($this->pdo, $identity);

        $fks = $schema['orders']['foreign_keys'];
        $this->assertNotEmpty($fks);

        $fk = $fks[0];
        $this->assertArrayHasKey('columns',            $fk);
        $this->assertArrayHasKey('references_table',   $fk);
        $this->assertArrayHasKey('references_columns', $fk);
        $this->assertContains('user_id', $fk['columns']);
        $this->assertSame('users', $fk['references_table']);
    }

    public function testGetSchemaResultHasExpectedStructure(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $identity = new Identity(name: 'admin', allTables: true);
        $schema   = SchemaServices::getSchema($this->pdo, $identity);

        foreach ($schema as $tableName => $tableData) {
            $this->assertIsString($tableName);
            $this->assertArrayHasKey('columns',            $tableData);
            $this->assertArrayHasKey('primary_key',        $tableData);
            $this->assertArrayHasKey('foreign_keys',       $tableData);
            $this->assertArrayHasKey('indexes',            $tableData);
            $this->assertArrayHasKey('unique_constraints', $tableData);
        }
    }
}
