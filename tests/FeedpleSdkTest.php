<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\SqlCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FeedpleSDK construction and validation.
 *
 * Mirrors the usage pattern in Python's test_sdk.py.
 */
class FeedpleSdkTest extends TestCase
{
    private function hasSqlite(): bool
    {
        return in_array('sqlite', \PDO::getAvailableDrivers(), strict: true);
    }

    private function checkSqliteAvailable(): void
    {
        if (!$this->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }
    }

    // ── Constructor validation ─────────────────────────────────────────────

    public function testConstructorThrowsWhenApiKeyIsEmpty(): void
    {
        $this->checkSqliteAvailable();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/api_key is required/');

        new FeedpleSDK(
            apiKey:   '',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
        );
    }

    public function testConstructorThrowsWhenApiKeyIsWhiteSpaceOnly(): void
    {
        $this->checkSqliteAvailable();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/api_key is required/');

        new FeedpleSDK(
            apiKey:   '   ',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
        );
    }

    public function testConstructorThrowsWhenDbConnectionFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not connect to the database/i');

        // Invalid DSN that fails connection
        new FeedpleSDK(
            apiKey:   'test_api_key',
            dbConfig: DbConfig::raw('mysql:host=invalid_host_name_should_fail;dbname=invalid;connect_timeout=1'),
            identity: new Identity(name: 'admin', allTables: true),
        );
    }

    // ── Full construction (requires SQLite) ────────────────────────────────

    public function testConstructorSucceedsWithValidArguments(): void
    {
        $this->checkSqliteAvailable();

        $sdk = new FeedpleSDK(
            apiKey:   'test_api_key',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
        );

        $sdk->stop();
        $this->addToAssertionCount(1);
    }

    public function testConstructorAcceptsAllConnectionOptions(): void
    {
        $this->checkSqliteAvailable();

        $sdk = new FeedpleSDK(
            apiKey:             'test_api_key',
            dbConfig:           DbConfig::sqlite(':memory:'),
            identity:           new Identity(name: 'admin', allTables: true),
            autoSync:           false,
            syncInterval:       120,
            reconnectEnabled:   false,
            maxRetries:         3,
            probeBeforeConnect: true,
        );

        $sdk->stop();
        $this->addToAssertionCount(1);
    }

    // ── buildCompiler ──────────────────────────────────────────────────────

    public function testBuildCompilerReturnsInstance(): void
    {
        $this->checkSqliteAvailable();

        $sdk      = new FeedpleSDK(
            apiKey:   'test_key',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
        );
        $compiler = $sdk->buildCompiler();
        $this->assertInstanceOf(SqlCompiler::class, $compiler);

        $sdk->stop();
    }

    // ── syncSchema inspection path ─────────────────────────────────────────

    public function testSyncSchemaInspectsEmptyDatabase(): void
    {
        $this->checkSqliteAvailable();

        $sdk = new FeedpleSDK(
            apiKey:   'test_key',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
        );

        // An empty SQLite DB should produce an empty schema.
        // sendSchema() is a no-op when not authenticated (not connected).
        // We expect no DB-level exception here.
        try {
            $sdk->syncSchema();
        } catch (\RuntimeException $e) {
            // Only a connection error to the WS is acceptable — not a DB error
            $this->assertStringNotContainsStringIgnoringCase('database', $e->getMessage());
        }

        $sdk->stop();
        $this->addToAssertionCount(1);
    }
}
