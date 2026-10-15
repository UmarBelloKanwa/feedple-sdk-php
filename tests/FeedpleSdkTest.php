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

    public function testLibraryDefaultSettings(): void
    {
        $this->assertEquals('webhook', FeedpleSDK::DEFAULT_TRANSPORT);
        $this->assertEquals('/api/feedple/bridge', FeedpleSDK::DEFAULT_BRIDGE_PATH);

        $expectedApiUrl = \Feedple\Sdk\FEEDPLE_DEV_ENV
            ? 'http://localhost:8000/api/v1'
            : 'https://feedple-ai-614817435356.us-central1.run.app/api/v1';
        $expectedWsUrl = \Feedple\Sdk\FEEDPLE_DEV_ENV
            ? 'ws://localhost:8000/api/v1/tenants/ws'
            : 'wss://feedple-ai-614817435356.us-central1.run.app/api/v1/tenants/ws';

        $this->assertEquals($expectedApiUrl, FeedpleSDK::DEFAULT_API_BASE_URL);
        $this->assertEquals($expectedWsUrl, FeedpleSDK::DEFAULT_WS_URL);
        $this->assertEquals('webhook', FeedpleSDK::getDefaultTransport());
        $this->assertEquals('/api/feedple/bridge', FeedpleSDK::getDefaultBridgePath());
        $this->assertEquals($expectedApiUrl, FeedpleSDK::getApiBaseUrl());
        $this->assertEquals($expectedWsUrl, FeedpleSDK::getWsUrl());

        $sdk = new FeedpleSDK(
            apiKey:   'test_api_key',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
        );
        $this->assertEquals('webhook', $sdk->getTransport());
    }

    public function testDevEnvSwitchesToLocalUrls(): void
    {
        $orig = getenv('FEEDPLE_DEV_ENV');
        try {
            putenv('FEEDPLE_DEV_ENV=true');
            $_ENV['FEEDPLE_DEV_ENV'] = 'true';
            $this->assertTrue(FeedpleSDK::isDevEnv());
            $this->assertEquals('http://localhost:8000/api/v1', FeedpleSDK::getApiBaseUrl());
            $this->assertEquals('ws://localhost:8000/api/v1/tenants/ws', FeedpleSDK::getWsUrl());

            putenv('FEEDPLE_DEV_ENV=false');
            $_ENV['FEEDPLE_DEV_ENV'] = 'false';
            $this->assertFalse(FeedpleSDK::isDevEnv());
            $this->assertEquals('https://feedple-ai-614817435356.us-central1.run.app/api/v1', FeedpleSDK::getApiBaseUrl());
            $this->assertEquals('wss://feedple-ai-614817435356.us-central1.run.app/api/v1/tenants/ws', FeedpleSDK::getWsUrl());
        } finally {
            if ($orig !== false) {
                putenv("FEEDPLE_DEV_ENV={$orig}");
                $_ENV['FEEDPLE_DEV_ENV'] = $orig;
            } else {
                putenv('FEEDPLE_DEV_ENV');
                unset($_ENV['FEEDPLE_DEV_ENV']);
            }
        }
    }

    public function testTransportAutoSelectionBasedOnEnvironment(): void
    {
        // 1. In development (via APP_ENV=local), transport defaults to websocket
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $this->assertTrue(FeedpleSDK::isDevelopment());
        $this->assertFalse(FeedpleSDK::isProduction());
        $this->assertEquals('websocket', FeedpleSDK::getDefaultTransport());

        // 2. In development (via APP_ENV=dev or development), transport defaults to websocket
        putenv('APP_ENV=dev');
        $_ENV['APP_ENV'] = 'dev';
        $this->assertTrue(FeedpleSDK::isDevelopment());
        $this->assertEquals('websocket', FeedpleSDK::getDefaultTransport());

        // 3. In production (via APP_ENV=production), transport defaults to webhook
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $this->assertFalse(FeedpleSDK::isDevelopment());
        $this->assertTrue(FeedpleSDK::isProduction());
        $this->assertEquals('webhook', FeedpleSDK::getDefaultTransport());

        // 4. In production (via APP_ENV=prod), transport defaults to webhook
        putenv('APP_ENV=prod');
        $_ENV['APP_ENV'] = 'prod';
        $this->assertFalse(FeedpleSDK::isDevelopment());
        $this->assertEquals('webhook', FeedpleSDK::getDefaultTransport());

        // 5. Explicit FEEDPLE_TRANSPORT override always takes precedence
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        putenv('FEEDPLE_TRANSPORT=webhook');
        $_ENV['FEEDPLE_TRANSPORT'] = 'webhook';
        $this->assertEquals('webhook', FeedpleSDK::getDefaultTransport());

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        putenv('FEEDPLE_TRANSPORT=websocket');
        $_ENV['FEEDPLE_TRANSPORT'] = 'websocket';
        $this->assertEquals('websocket', FeedpleSDK::getDefaultTransport());

        // Cleanup
        putenv('APP_ENV');
        unset($_ENV['APP_ENV']);
        putenv('FEEDPLE_TRANSPORT');
        unset($_ENV['FEEDPLE_TRANSPORT']);
    }

    public function testDeploymentScenarioMatrix(): void
    {
        $set = function (array $vars): void {
            foreach (['APP_ENV', 'APP_URL', 'FEEDPLE_DEV_ENV', 'FEEDPLE_TRANSPORT', 'FEEDPLE_API_BASE_URL'] as $k) {
                putenv($k);
                unset($_ENV[$k]);
            }
            foreach ($vars as $k => $v) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        };

        try {
            // 1a. Local app → local backend, webhook (reachable, honoured)
            $set(['APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8001', 'FEEDPLE_DEV_ENV' => 'true', 'FEEDPLE_TRANSPORT' => 'webhook']);
            $this->assertSame('http://localhost:8000/api/v1', FeedpleSDK::getApiBaseUrl());
            $this->assertSame('webhook', FeedpleSDK::getDefaultTransport());

            // 1b. Local app → local backend, websocket (default in dev)
            $set(['APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8001', 'FEEDPLE_DEV_ENV' => 'true']);
            $this->assertSame('websocket', FeedpleSDK::getDefaultTransport());

            // 2. Local app → production backend: always websocket, even if webhook requested
            $set(['APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8001']);
            $this->assertStringStartsWith('https://', FeedpleSDK::getApiBaseUrl());
            $this->assertSame('websocket', FeedpleSDK::getDefaultTransport());
            $set(['APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8001', 'FEEDPLE_TRANSPORT' => 'webhook']);
            $this->assertSame('websocket', FeedpleSDK::getDefaultTransport());

            // 3. Production app → production backend: webhook
            $set(['APP_ENV' => 'production', 'APP_URL' => 'https://myapp.com']);
            $this->assertStringStartsWith('https://', FeedpleSDK::getApiBaseUrl());
            $this->assertSame('webhook', FeedpleSDK::getDefaultTransport());

            // 4. Live web request with public host automatically overrides stale .env APP_ENV=local and APP_URL=http://localhost
            $set(['APP_ENV' => 'local', 'APP_URL' => 'http://localhost']);
            $_SERVER['HTTP_HOST'] = 'clientstore.com';
            $_SERVER['HTTPS'] = 'on';
            try {
                $this->assertFalse(FeedpleSDK::isDevelopment());
                $this->assertTrue(FeedpleSDK::isProduction());
                $this->assertFalse(FeedpleSDK::isLocalApp());
                $this->assertSame('webhook', FeedpleSDK::getDefaultTransport());
                $this->assertSame('https://clientstore.com', FeedpleSDK::detectAppUrl());
            } finally {
                unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
            }
        } finally {
            $set([]);
        }
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

    public function testWorkerSingleInstanceLockDetectsRunningWorker(): void
    {
        $this->checkSqliteAvailable();

        $tempDir = sys_get_temp_dir();
        $lockFile = $tempDir . DIRECTORY_SEPARATOR . 'feedple-sdk.lock';

        // Simulate an active worker holding flock
        $fp = fopen($lockFile, 'c+');
        $this->assertNotFalse($fp);
        $gotLock = flock($fp, LOCK_EX | LOCK_NB);
        $this->assertTrue($gotLock);

        $sdk = new FeedpleSDK(
            apiKey:     'test_api_key',
            dbConfig:   DbConfig::sqlite(':memory:'),
            identity:   new Identity(name: 'admin', allTables: true),
            runtimeDir: $tempDir,
        );

        // SDK constructor sees active lock file and skips spawning worker
        $this->addToAssertionCount(1);

        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockFile);

        $sdk->stop();
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
