<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\FeedpleWebSocket;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\FeedpleSDK;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

class ResilienceAndReconnectionTest extends TestCase
{
    private function checkSqliteAvailable(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), strict: true)) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }
    }

    public function testGetWsUrlRespectsEnvironmentVariable(): void
    {
        $originalEnv = getenv('FEEDPLE_WS_URL');

        try {
            putenv('FEEDPLE_WS_URL=');
            unset($_ENV['FEEDPLE_WS_URL']);
            $defaultUrl = FeedpleSDK::getWsUrl();
            $this->assertStringContainsString('tenants/ws', $defaultUrl);

            putenv('FEEDPLE_WS_URL=ws://custom-backend.local:8080/custom/ws');
            $customUrl = FeedpleSDK::getWsUrl();
            $this->assertSame('ws://custom-backend.local:8080/custom/ws', $customUrl);
        } finally {
            if ($originalEnv !== false) {
                putenv("FEEDPLE_WS_URL={$originalEnv}");
            } else {
                putenv('FEEDPLE_WS_URL=');
            }
        }
    }

    public function testPongTimeoutConstantIsThirtySeconds(): void
    {
        $this->assertSame(30, FeedpleWebSocket::PONG_TIMEOUT);
    }

    public function testStopCancelsPendingReconnectTimer(): void
    {
        $loop = Loop::get();
        $ws = new FeedpleWebSocket(
            'ws://localhost:12345/ws',
            'test_key',
            $loop,
            new NullLogger()
        );

        // Use reflection to schedule a reconnect timer
        $scheduleMethod = new \ReflectionMethod(FeedpleWebSocket::class, 'scheduleReconnect');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($ws, 5.0);

        $timerProp = new \ReflectionProperty(FeedpleWebSocket::class, 'reconnectTimer');
        $timerProp->setAccessible(true);
        $this->assertNotNull($timerProp->getValue($ws), 'Reconnect timer should be active');

        // Calling stop() must cancel and null out the timer
        $ws->stop();
        $this->assertNull($timerProp->getValue($ws), 'Reconnect timer must be null after stop()');
    }

    public function testScheduleReconnectDoesNotStackDuplicateTimers(): void
    {
        $loop = Loop::get();
        $ws = new FeedpleWebSocket(
            'ws://localhost:12345/ws',
            'test_key',
            $loop,
            new NullLogger()
        );

        $scheduleMethod = new \ReflectionMethod(FeedpleWebSocket::class, 'scheduleReconnect');
        $scheduleMethod->setAccessible(true);

        $timerProp = new \ReflectionProperty(FeedpleWebSocket::class, 'reconnectTimer');
        $timerProp->setAccessible(true);

        // Schedule first timer
        $scheduleMethod->invoke($ws, 5.0);
        $timer1 = $timerProp->getValue($ws);
        $this->assertNotNull($timer1);

        // Schedule second timer — should replace and cancel timer1, never stacking
        $scheduleMethod->invoke($ws, 10.0);
        $timer2 = $timerProp->getValue($ws);
        $this->assertNotNull($timer2);
        $this->assertNotSame($timer1, $timer2, 'Timer was replaced with a single fresh timer');

        $ws->stop();
    }

    public function testGetDbReconnectsWhenConnectionFails(): void
    {
        $this->checkSqliteAvailable();

        $tempDb = sys_get_temp_dir() . '/test_reconnect_' . uniqid() . '.sqlite';
        touch($tempDb);

        try {
            $dbConfig = DbConfig::sqlite($tempDb);
            $sdk = new FeedpleSDK(
                apiKey: 'test_key',
                dbConfig: $dbConfig,
                identity: new Identity(name: 'admin', allTables: true),
                autoSync: false,
            );

            $getDbMethod = new \ReflectionMethod(FeedpleSDK::class, 'getDb');
            $getDbMethod->setAccessible(true);

            $initialPdo = $getDbMethod->invoke($sdk);
            $this->assertInstanceOf(\PDO::class, $initialPdo);

            // Intentionally replace the internal PDO handle with one pointing to a closed/invalid state
            $dbProp = new \ReflectionProperty(FeedpleSDK::class, 'db');
            $dbProp->setAccessible(true);

            // We simulate a dropped connection by closing/breaking the sqlite file or nulling it
            // Let's create an in-memory SQLite and close it or replace with mock-like broken PDO
            $brokenPdo = new \PDO('sqlite::memory:');
            // Execute a query to confirm it works
            $brokenPdo->query('SELECT 1');
            // Now force it to throw on SELECT 1 by pointing $dbProp to an invalid dummy subclass that throws on query
            $failingPdo = new class('sqlite::memory:') extends \PDO {
                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
                {
                    throw new \PDOException('MySQL server has gone away (simulated drop)');
                }
            };

            $dbProp->setValue($sdk, $failingPdo);

            // Calling getDb() should catch the simulated drop and reconnect using DbConfig!
            $reconnectedPdo = $getDbMethod->invoke($sdk);
            $this->assertInstanceOf(\PDO::class, $reconnectedPdo);
            $this->assertNotSame($failingPdo, $reconnectedPdo, 'getDb() should have recreated the PDO connection');

            // Verify the reconnected PDO is fully functioning
            $stmt = $reconnectedPdo->query('SELECT 1');
            $this->assertNotFalse($stmt);

            $sdk->stop();
        } finally {
            @unlink($tempDb);
        }
    }

    public function testLoopGuardianIsInitializedAndCancelledOnStop(): void
    {
        $loop = Loop::get();
        $ws = new FeedpleWebSocket(
            'ws://localhost:12345/ws',
            'test_key',
            $loop,
            new NullLogger()
        );

        $guardianProp = new \ReflectionProperty(FeedpleWebSocket::class, 'guardianTimer');
        $guardianProp->setAccessible(true);
        $this->assertNull($guardianProp->getValue($ws), 'Guardian timer should be null before connect()');

        $ensureMethod = new \ReflectionMethod(FeedpleWebSocket::class, 'ensureLoopGuardian');
        $ensureMethod->setAccessible(true);
        $ensureMethod->invoke($ws);

        $this->assertNotNull($guardianProp->getValue($ws), 'Guardian timer should be initialized');

        $ws->stop();
        $this->assertNull($guardianProp->getValue($ws), 'Guardian timer must be null after stop()');
    }

    public function testLogRotationWhenExceeding10MB(): void
    {
        $this->checkSqliteAvailable();

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'feedple_test_' . uniqid();
        @mkdir($tempDir, 0777, true);

        try {
            $sdk = new FeedpleSDK(
                apiKey: 'test_key',
                dbConfig: DbConfig::sqlite(':memory:'),
                identity: new Identity(name: 'admin', allTables: true),
                runtimeDir: $tempDir,
            );

            $logPath = $tempDir . DIRECTORY_SEPARATOR . 'feedple-sdk.log';
            $rotatedPath = $logPath . '.1';

            // Create a fake 11MB file
            $fp = fopen($logPath, 'wb');
            fseek($fp, 11 * 1024 * 1024, SEEK_SET);
            fwrite($fp, "\n");
            fclose($fp);

            $this->assertGreaterThan(10 * 1024 * 1024, filesize($logPath));

            // Calling log() must rotate the file
            $sdk->log('info', 'Rotation test entry');

            $this->assertFileExists($rotatedPath, 'Rotated log file .1 must exist');
            $this->assertFileExists($logPath, 'New log file must exist');
            $this->assertLessThan(1024, filesize($logPath), 'New log file should only contain the new line');
        } finally {
            @unlink($tempDir . DIRECTORY_SEPARATOR . 'feedple-sdk.log');
            @unlink($tempDir . DIRECTORY_SEPARATOR . 'feedple-sdk.log.1');
            @rmdir($tempDir);
        }
    }
}

