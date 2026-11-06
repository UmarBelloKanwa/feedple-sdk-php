<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\IrBuilder;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\FeedpleSDK;
use PHPUnit\Framework\TestCase;

class SecurityHardeningTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/feedple-sec-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        putenv('FEEDPLE_API_BASE_URL=http://127.0.0.1:1/api/v1');
        putenv('FEEDPLE_WEBHOOK_SECRET');
        unset($_ENV['FEEDPLE_WEBHOOK_SECRET']);
    }

    protected function tearDown(): void
    {
        putenv('FEEDPLE_API_BASE_URL');
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) || is_link($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function sdk(): FeedpleSDK
    {
        return new FeedpleSDK(
            apiKey: 'k',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'a', allTables: true),
            runtimeDir: $this->dir,
            transport: 'webhook',
        );
    }

    private function cache(): string
    {
        return $this->dir . '/feedple-webhook-secret.json';
    }

    // ── IrBuilder datetime expressions ──────────────────────────────────────

    private function whereFor(string $value, string $driver = 'sqlite'): array
    {
        return IrBuilder::buildQueryFromIr([
            'operation' => 'query',
            'table'     => 'orders',
            'filters'   => [['column' => 'created_at', 'operator' => 'gte', 'value' => $value]],
        ], $driver);
    }

    public function testSafeDatetimeExpressionIsInlined(): void
    {
        $q = $this->whereFor("NOW() - INTERVAL '30 days'");
        $this->assertStringContainsString("NOW() - INTERVAL '30 days'", $q['sql']);
        $this->assertSame([], $q['params']);
    }

    public function testMysqlIntervalStillNormalised(): void
    {
        $q = $this->whereFor("CURRENT_TIMESTAMP - INTERVAL '30 days'", 'mysql');
        $this->assertStringContainsString('INTERVAL 30 DAY', $q['sql']);
    }

    public function testSmuggledSqlAfterNowIsBoundNotInlined(): void
    {
        $evil = 'NOW(); DROP TABLE orders';
        $q = $this->whereFor($evil);
        $this->assertStringNotContainsString('DROP', $q['sql']);
        $this->assertSame([$evil], $q['params']);
    }

    public function testSmuggledSqlAfterCurrentTimestampIsBoundNotInlined(): void
    {
        // The old prefix-only regex matched this and inlined it as raw SQL.
        $evil = 'CURRENT_TIMESTAMP; DROP TABLE orders';
        $q = $this->whereFor($evil);
        $this->assertStringNotContainsString('DROP', $q['sql']);
        $this->assertSame([$evil], $q['params']);
    }

    public function testBareNowIsInlinedNotBoundAsString(): void
    {
        $q = $this->whereFor('NOW()');
        $this->assertStringContainsString('>= NOW()', $q['sql']);
        $this->assertSame([], $q['params']);
    }

    public function testPlainStringIsBound(): void
    {
        $q = $this->whereFor('2024-01-01');
        $this->assertSame(['2024-01-01'], $q['params']);
    }

    // ── Webhook secret cache ────────────────────────────────────────────────

    public function testOwnerOnlyCacheRoundTrips(): void
    {
        $this->sdk()->saveWebhookSecret('whsec_ok');
        $this->assertSame('whsec_ok', $this->sdk()->getWebhookSecret());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->cache())), -4));
    }

    public function testWorldWritableCacheIsRejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions');
        }
        file_put_contents($this->cache(), json_encode(['webhook_secret' => 'ATTACKER']));
        chmod($this->cache(), 0666);
        $this->assertNull($this->sdk()->getWebhookSecret());
    }

    public function testSymlinkedCacheIsRejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlinks');
        }
        $real = $this->dir . '/real.json';
        file_put_contents($real, json_encode(['webhook_secret' => 'ATTACKER']));
        symlink($real, $this->cache());
        $this->assertNull($this->sdk()->getWebhookSecret());
        @unlink($real);
    }

    public function testSaveDoesNotFollowSymlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX symlinks');
        }
        $victim = $this->dir . '/victim';
        file_put_contents($victim, 'PRECIOUS');
        symlink($victim, $this->cache());

        $this->sdk()->saveWebhookSecret('whsec_x');

        $this->assertSame('PRECIOUS', file_get_contents($victim));
        $this->assertFalse(is_link($this->cache()));
        $this->assertStringContainsString('whsec_x', (string) file_get_contents($this->cache()));
        @unlink($victim);
    }

    public function testFailedFetchBacksOffInsteadOfRetryingEveryRequest(): void
    {
        $sdk = $this->sdk();
        $this->assertNull($sdk->getWebhookSecret());
        $this->assertFileExists($this->cache() . '.miss');

        // A fresh instance (= next web request) must not hit the network again.
        $start = microtime(true);
        $this->assertNull($this->sdk()->getWebhookSecret());
        $this->assertLessThan(0.5, microtime(true) - $start);
    }
}
