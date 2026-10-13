<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\WebhookBridge;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\FeedpleSDK;
use PHPUnit\Framework\TestCase;

class WebhookBridgeTest extends TestCase
{
    private ?FeedpleSDK $sdk = null;
    private string $secret = 'whsec_test_secret_key_1234567890';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required.');
        }

        // Initialize SDK in stateless webhook mode
        $this->sdk = new FeedpleSDK(
            apiKey: 'test_api_key',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'admin', allTables: true),
            transport: 'webhook',
        );

        // Populate test table
        $pdo = $this->sdk->buildCompiler(); // test compiler access
        $method = new \ReflectionMethod(FeedpleSDK::class, 'getDb');
        $method->setAccessible(true);
        $rawPdo = $method->invoke($this->sdk);
        $rawPdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT);');
        $rawPdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com');");
        $rawPdo->exec("INSERT INTO users (id, name, email) VALUES (2, 'Bob', 'bob@example.com');");
    }

    public function testStatelessWebhookModeDoesNotSpawnWorker(): void
    {
        $this->assertEquals('webhook', $this->sdk->getTransport());
        // Verify no worker PID was set
        $pidProp = new \ReflectionProperty(FeedpleSDK::class, 'workerPid');
        $this->assertNull($pidProp->getValue($this->sdk));
    }

    public function testVerifySignatureValid(): void
    {
        $payload = json_encode(['request_id' => 'req-1', 'ir' => ['table' => 'users']]);
        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        $header = "t={$timestamp},v1={$sig}";

        $result = WebhookBridge::verifySignature($payload, $header, $this->secret);
        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
        $this->assertEquals($timestamp, $result['timestamp']);
    }

    public function testVerifySignatureRejectsTamperedBody(): void
    {
        $payload = json_encode(['request_id' => 'req-1', 'ir' => ['table' => 'users']]);
        $tampered = json_encode(['request_id' => 'req-1', 'ir' => ['table' => 'secrets']]);
        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        $header = "t={$timestamp},v1={$sig}";

        $result = WebhookBridge::verifySignature($tampered, $header, $this->secret);
        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid webhook signature', $result['reason']);
    }

    public function testVerifySignatureRejectsStaleTimestamp(): void
    {
        $payload = json_encode(['request_id' => 'req-1']);
        $staleTimestamp = time() - 350; // older than 300s tolerance
        $sig = hash_hmac('sha256', $staleTimestamp . '.' . $payload, $this->secret);
        $header = "t={$staleTimestamp},v1={$sig}";

        $result = WebhookBridge::verifySignature($payload, $header, $this->secret, 300);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Timestamp outside tolerance', (string) $result['reason']);
    }

    public function testVerifySignatureRejectsFutureTimestampBeyondSkew(): void
    {
        $payload = json_encode(['request_id' => 'req-1']);
        $futureTimestamp = time() + 350; // future beyond 300s tolerance
        $sig = hash_hmac('sha256', $futureTimestamp . '.' . $payload, $this->secret);
        $header = "t={$futureTimestamp},v1={$sig}";

        $result = WebhookBridge::verifySignature($payload, $header, $this->secret, 300);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Timestamp outside tolerance', (string) $result['reason']);
    }

    public function testVerifySignatureRejectsMissingOrMalformedHeader(): void
    {
        $payload = json_encode(['request_id' => 'req-1']);

        $res1 = WebhookBridge::verifySignature($payload, null, $this->secret);
        $this->assertFalse($res1['valid']);

        $res2 = WebhookBridge::verifySignature($payload, 'invalid-header', $this->secret);
        $this->assertFalse($res2['valid']);

        $res3 = WebhookBridge::verifySignature($payload, 't=notanumber,v1=123', $this->secret);
        $this->assertFalse($res3['valid']);
    }

    public function testHandleExecutesValidIrQuery(): void
    {
        $bridge = new WebhookBridge($this->sdk, $this->secret);

        $payload = json_encode([
            'request_id' => 'req-12345',
            'tenant_id'  => 'tenant-abc',
            'ir'         => [
                'operation' => 'query',
                'table'     => 'users',
                'fields'    => [],
            ],
        ]);

        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        $headers = [
            'X-Feedple-Signature'  => "t={$timestamp},v1={$sig}",
            'X-Feedple-Request-ID' => 'req-12345',
        ];

        $response = $bridge->handle($payload, $headers);

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['body']['status']);
        $this->assertEquals('req-12345', $response['body']['request_id']);
        $this->assertEquals(2, $response['body']['count']);
        $this->assertCount(2, $response['body']['rows']);
        $this->assertEquals('Alice', $response['body']['rows'][0]['name']);
        $this->assertEquals('Bob', $response['body']['rows'][1]['name']);
    }

    public function testHandleRejectsUnauthorizedRequest(): void
    {
        $bridge = new WebhookBridge($this->sdk, $this->secret);

        $payload = json_encode(['request_id' => 'req-1', 'ir' => ['table' => 'users']]);
        $headers = [
            'X-Feedple-Signature' => 't=' . time() . ',v1=bad_signature',
        ];

        $response = $bridge->handle($payload, $headers);
        $this->assertEquals(401, $response['status_code']);
        $this->assertEquals('error', $response['body']['status']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['code']);
    }

    public function testHandleRejectsMalformedJson(): void
    {
        $bridge = new WebhookBridge($this->sdk, $this->secret);

        $payload = '{invalid_json:';
        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        $headers = [
            'X-Feedple-Signature' => "t={$timestamp},v1={$sig}",
        ];

        $response = $bridge->handle($payload, $headers);
        $this->assertEquals(400, $response['status_code']);
        $this->assertEquals('INVALID_JSON', $response['body']['code']);
    }

    public function testHandleRejectsMissingIr(): void
    {
        $bridge = new WebhookBridge($this->sdk, $this->secret);

        $payload = json_encode(['request_id' => 'req-1']);
        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        $headers = [
            'X-Feedple-Signature' => "t={$timestamp},v1={$sig}",
        ];

        $response = $bridge->handle($payload, $headers);
        $this->assertEquals(422, $response['status_code']);
        $this->assertEquals('MISSING_IR', $response['body']['code']);
    }

    public function testHandleCatchesPolicyViolation(): void
    {
        // SDK restricted to only 'orders' table
        $restrictedSdk = new FeedpleSDK(
            apiKey: 'test_api_key',
            dbConfig: DbConfig::sqlite(':memory:'),
            identity: new Identity(name: 'viewer', allowedTables: ['orders']),
            transport: 'webhook',
        );

        $bridge = new WebhookBridge($restrictedSdk, $this->secret);

        $payload = json_encode([
            'request_id' => 'req-restricted',
            'ir'         => ['table' => 'secret_passwords'],
        ]);
        $timestamp = time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
        $headers = [
            'X-Feedple-Signature' => "t={$timestamp},v1={$sig}",
        ];

        $response = $bridge->handle($payload, $headers);
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('error', $response['body']['status']);
        $this->assertEquals('POLICY_VIOLATION', $response['body']['code']);
        $this->assertStringContainsString('Access denied', $response['body']['reason']);
    }

    public function testVerifySignatureSupportsSecretRotationWithMultipleSecrets(): void
    {
        $oldSecret = 'whsec_old_secret_111';
        $newSecret = 'whsec_new_secret_222';
        $commaDelimited = "{$newSecret},{$oldSecret}";

        $payload = json_encode(['action' => 'select', 'table' => 'users']);
        $timestamp = time();

        // Sign with old secret
        $oldSig = hash_hmac('sha256', "{$timestamp}.{$payload}", $oldSecret);
        $headerOld = "t={$timestamp},v1={$oldSig}";

        // Should verify successfully against comma-delimited list
        $resOld = WebhookBridge::verifySignature($payload, $headerOld, $commaDelimited);
        $this->assertTrue($resOld['valid']);

        // Sign with new secret
        $newSig = hash_hmac('sha256', "{$timestamp}.{$payload}", $newSecret);
        $headerNew = "t={$timestamp},v1={$newSig}";

        // Should also verify successfully against comma-delimited list
        $resNew = WebhookBridge::verifySignature($payload, $headerNew, $commaDelimited);
        $this->assertTrue($resNew['valid']);

        // Array of secrets also supported
        $resArr = WebhookBridge::verifySignature($payload, $headerNew, [$newSecret, $oldSecret]);
        $this->assertTrue($resArr['valid']);

        // Unknown secret rejected
        $badSig = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_unknown_333');
        $resBad = WebhookBridge::verifySignature($payload, "t={$timestamp},v1={$badSig}", $commaDelimited);
        $this->assertFalse($resBad['valid']);
    }

    public function testHandleAcceptsServerSuperglobalHeaders(): void
    {
        $bridge = new WebhookBridge($this->sdk, $this->secret);

        $payload = json_encode([
            'request_id' => 'req-server-superglobal',
            'ir'         => ['operation' => 'query', 'table' => 'users'],
        ]);
        $timestamp = time();
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        // Typical PHP $_SERVER headers (HTTP_X_... format)
        $serverHeaders = [
            'HTTP_HOST'                 => 'example.com',
            'HTTP_X_FEEDPLE_SIGNATURE'  => "t={$timestamp},v1={$sig}",
            'HTTP_X_FEEDPLE_REQUEST_ID' => 'req-server-superglobal',
            'CONTENT_TYPE'              => 'application/json',
        ];

        $response = $bridge->handle($payload, $serverHeaders);
        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['body']['status']);
        $this->assertEquals('req-server-superglobal', $response['body']['request_id']);
    }
}
