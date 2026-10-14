<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\FeedpleSDK;
use PHPUnit\Framework\TestCase;

class SslAndCaBundleTest extends TestCase
{
    private string $tempDb;

    protected function setUp(): void
    {
        $this->tempDb = sys_get_temp_dir() . '/feedple_ssl_test_' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->tempDb);
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            @unlink($this->tempDb);
        }
        putenv('FEEDPLE_VERIFY_SSL');
        putenv('FEEDPLE_CA_BUNDLE');
        unset($_ENV['FEEDPLE_VERIFY_SSL'], $_ENV['FEEDPLE_CA_BUNDLE']);
    }

    public function testResolveCaBundleFindsBundledCertificate(): void
    {
        $bundlePath = FeedpleSDK::resolveCaBundle();

        $this->assertNotNull($bundlePath, 'A valid CA bundle path should be resolved');
        $this->assertFileExists($bundlePath, 'Resolved CA bundle must exist on disk');
        $this->assertStringEndsWith('.pem', $bundlePath);
    }

    public function testResolveCaBundleRespectsCustomEnvironmentVariable(): void
    {
        $dummyCa = sys_get_temp_dir() . '/dummy_ca_' . bin2hex(random_bytes(4)) . '.pem';
        file_put_contents($dummyCa, '-----BEGIN CERTIFICATE-----');

        try {
            putenv("FEEDPLE_CA_BUNDLE={$dummyCa}");
            $_ENV['FEEDPLE_CA_BUNDLE'] = $dummyCa;

            $resolved = FeedpleSDK::resolveCaBundle();
            $this->assertSame($dummyCa, $resolved);
        } finally {
            @unlink($dummyCa);
        }
    }

    public function testIsSslVerificationEnabled(): void
    {
        putenv('FEEDPLE_VERIFY_SSL');
        unset($_ENV['FEEDPLE_VERIFY_SSL']);
        $this->assertTrue(FeedpleSDK::isSslVerificationEnabled());

        putenv('FEEDPLE_VERIFY_SSL=false');
        $_ENV['FEEDPLE_VERIFY_SSL'] = 'false';
        $this->assertFalse(FeedpleSDK::isSslVerificationEnabled());

        putenv('FEEDPLE_VERIFY_SSL=0');
        $_ENV['FEEDPLE_VERIFY_SSL'] = '0';
        $this->assertFalse(FeedpleSDK::isSslVerificationEnabled());

        putenv('FEEDPLE_VERIFY_SSL=true');
        $_ENV['FEEDPLE_VERIFY_SSL'] = 'true';
        $this->assertTrue(FeedpleSDK::isSslVerificationEnabled());
    }

    public function testSdkInstancePreservesSslConfiguration(): void
    {
        $sdk = new FeedpleSDK(
            apiKey: 'test-api-key',
            dbConfig: DbConfig::sqlite($this->tempDb),
            identity: new Identity(name: 'test', allTables: true),
            transport: 'webhook',
            verifySsl: false,
            caBundlePath: '/custom/ca.pem'
        );

        $this->assertFalse($sdk->isSslVerified());
        $this->assertSame('/custom/ca.pem', $sdk->getCaBundlePath());
    }
}
