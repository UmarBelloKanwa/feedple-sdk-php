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
            webhookSecret: 'whsec_custom_secret',
            verifySsl: false,
            caBundlePath: '/custom/ca.pem'
        );

        $this->assertFalse($sdk->isSslVerified());
        $this->assertSame('/custom/ca.pem', $sdk->getCaBundlePath());
        $this->assertSame('whsec_custom_secret', $sdk->getWebhookSecret());
    }

    public function testStringBooleansInConstructor(): void
    {
        $sdkFalseString = new FeedpleSDK(
            apiKey: 'test-api-key',
            dbConfig: DbConfig::sqlite($this->tempDb),
            identity: new Identity(name: 'test', allTables: true),
            transport: 'webhook',
            verifySsl: 'false'
        );
        $this->assertFalse($sdkFalseString->isSslVerified(), 'String "false" must evaluate to false');

        $sdkZeroString = new FeedpleSDK(
            apiKey: 'test-api-key',
            dbConfig: DbConfig::sqlite($this->tempDb),
            identity: new Identity(name: 'test', allTables: true),
            transport: 'webhook',
            verifySsl: '0'
        );
        $this->assertFalse($sdkZeroString->isSslVerified(), 'String "0" must evaluate to false');

        $sdkOffString = new FeedpleSDK(
            apiKey: 'test-api-key',
            dbConfig: DbConfig::sqlite($this->tempDb),
            identity: new Identity(name: 'test', allTables: true),
            transport: 'webhook',
            verifySsl: 'off'
        );
        $this->assertFalse($sdkOffString->isSslVerified(), 'String "off" must evaluate to false');

        $sdkTrueString = new FeedpleSDK(
            apiKey: 'test-api-key',
            dbConfig: DbConfig::sqlite($this->tempDb),
            identity: new Identity(name: 'test', allTables: true),
            transport: 'webhook',
            verifySsl: 'true'
        );
        $this->assertTrue($sdkTrueString->isSslVerified(), 'String "true" must evaluate to true');
    }

    public function testResolveCaBundleSkipsEmptyOrUnreadableFile(): void
    {
        $emptyCa = sys_get_temp_dir() . '/empty_ca_' . bin2hex(random_bytes(4)) . '.pem';
        file_put_contents($emptyCa, ''); // 0 bytes

        try {
            putenv("FEEDPLE_CA_BUNDLE={$emptyCa}");
            $_ENV['FEEDPLE_CA_BUNDLE'] = $emptyCa;

            $resolved = FeedpleSDK::resolveCaBundle();
            // Should NOT return the empty file; falls through to bundled or system CA
            $this->assertNotSame($emptyCa, $resolved);
        } finally {
            @unlink($emptyCa);
        }
    }

    public function testWebSocketRecoversFromFailedCafileStream(): void
    {
        $loop = \React\EventLoop\Loop::get();
        $logger = new \Psr\Log\NullLogger();

        $ws = new \Feedple\Sdk\Core\FeedpleWebSocket(
            wsUrl: 'wss://test.feedple.com',
            apiKey: 'test-key',
            loop: $loop,
            logger: $logger,
            verifySsl: true,
            caBundle: '/non/existent/cacert.pem'
        );

        $ref = new \ReflectionClass($ws);
        $caProp = $ref->getProperty('caBundle');
        $this->assertSame('/non/existent/cacert.pem', $caProp->getValue($ws));

        $connProp = $ref->getProperty('connector');
        // Fake connector presence
        $connProp->setValue($ws, new \Ratchet\Client\Connector($loop));
        $this->assertNotNull($connProp->getValue($ws));

        $method = $ref->getMethod('handleConnectFailure');
        $errorMessage = "Connection to tls://feedple-ai:443 failed during TLS handshake: failed loading cafile stream: `/non/existent/cacert.pem', reconnecting in 1s";
        $method->invoke($ws, new \RuntimeException($errorMessage), 1.0);

        // Assert caBundle and connector were reset to null so fallback to system store happens
        $this->assertNull($caProp->getValue($ws), 'caBundle must be reset to null when cafile stream fails');
        $this->assertNull($connProp->getValue($ws), 'connector must be reset to null to rebuild with system store');

        $ws->stop();
    }
}

