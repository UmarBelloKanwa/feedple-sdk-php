<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

use Feedple\Sdk\FeedpleSDK;

/**
 * Autonomous HTTP Webhook Bridge for stateless query execution.
 *
 * Verifies HMAC-SHA256 signatures, validates incoming IR requests,
 * executes queries via FeedpleSDK, and returns structured JSON responses.
 *
 * Zero background daemons, zero event loops, zero persistent workers.
 */
class WebhookBridge
{
    /** @var FeedpleSDK */
    private $sdk;

    /** @var string|null */
    private $webhookSecret;

    /** @var int Clock skew tolerance in seconds (default: 300s / 5min) */
    private $tolerance;

    public function __construct(
        FeedpleSDK $sdk,
        ?string    $webhookSecret = null,
        int        $tolerance     = 300
    ) {
        $this->sdk = $sdk;
        $this->webhookSecret = $webhookSecret
            ?? getenv('FEEDPLE_WEBHOOK_SECRET')
            ?: ($_ENV['FEEDPLE_WEBHOOK_SECRET'] ?? null);
        $this->tolerance = $tolerance;
    }

    /**
     * Verify HMAC-SHA256 signature against raw request body.
     * Supports multiple secrets (comma-separated string or array) for zero-downtime rotation.
     *
     * Format: X-Feedple-Signature: t={timestamp},v1={signature}
     * Canonical string: {timestamp}.{rawBody}
     *
     * @param  string                    $rawBody         Exact raw HTTP request body
     * @param  string|null               $signatureHeader Value of X-Feedple-Signature header
     * @param  string|array<string>|null $secret          Webhook signing secret(s) (whsec_...)
     * @param  int                       $tolerance       Maximum permitted clock skew in seconds
     * @return array{valid: bool, reason: string|null, timestamp: int|null}
     */
    public static function verifySignature(
        string $rawBody,
        ?string $signatureHeader,
        $secret = null,
        int $tolerance = 300
    ): array {
        $candidateSecrets = [];
        if (is_array($secret)) {
            $candidateSecrets = $secret;
        } elseif (is_string($secret)) {
            $candidateSecrets = explode(',', $secret);
        }

        $secrets = array_values(array_filter(array_map('trim', $candidateSecrets), function ($s) {
            return $s !== '';
        }));

        if (empty($secrets)) {
            return [
                'valid'     => false,
                'reason'    => 'Webhook signing secret is not configured',
                'timestamp' => null,
            ];
        }

        if (empty($signatureHeader)) {
            return [
                'valid'     => false,
                'reason'    => 'Missing X-Feedple-Signature header',
                'timestamp' => null,
            ];
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $item) {
            $sub = explode('=', trim($item), 2);
            if (count($sub) === 2) {
                $parts[$sub[0]] = $sub[1];
            }
        }

        if (!isset($parts['t']) || !isset($parts['v1'])) {
            return [
                'valid'     => false,
                'reason'    => 'Malformed X-Feedple-Signature header',
                'timestamp' => null,
            ];
        }

        $timestampStr = $parts['t'];
        $signature = $parts['v1'];

        if (!is_numeric($timestampStr)) {
            return [
                'valid'     => false,
                'reason'    => 'Invalid timestamp in signature header',
                'timestamp' => null,
            ];
        }

        $timestamp = (int) $timestampStr;
        $currentTime = time();

        if (abs($currentTime - $timestamp) > $tolerance) {
            return [
                'valid'     => false,
                'reason'    => "Timestamp outside tolerance window of {$tolerance}s (clock skew or replay attack)",
                'timestamp' => $timestamp,
            ];
        }

        $signedData = $timestamp . '.' . $rawBody;
        $matched = false;

        foreach ($secrets as $candSecret) {
            $expectedSignature = hash_hmac('sha256', $signedData, $candSecret);
            if (hash_equals($expectedSignature, $signature)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return [
                'valid'     => false,
                'reason'    => 'Invalid webhook signature',
                'timestamp' => $timestamp,
            ];
        }

        return [
            'valid'     => true,
            'reason'    => null,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Process an incoming raw HTTP webhook request.
     * Automatically normalizes headers from $_SERVER, getallheaders(), PSR-7, or framework requests.
     *
     * @param  string               $rawBody Raw HTTP request body
     * @param  array<string, mixed> $headers HTTP request headers
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function handle(string $rawBody, array $headers): array
    {
        $signatureHeader = null;
        $requestIdHeader = null;

        foreach ($headers as $k => $v) {
            $norm = strtolower(str_replace('_', '-', (string) $k));
            if (str_starts_with($norm, 'http-')) {
                $norm = substr($norm, 5);
            }

            if ($norm === 'x-feedple-signature') {
                $signatureHeader = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            } elseif ($norm === 'x-feedple-request-id') {
                $requestIdHeader = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }

        // 1. Verify signature
        $verification = self::verifySignature(
            $rawBody,
            $signatureHeader,
            $this->webhookSecret,
            $this->tolerance
        );

        if (!$verification['valid']) {
            $this->sdk->log('warning', "Feedple Bridge: signature verification failed: {$verification['reason']}");
            return [
                'status_code' => 401,
                'body' => [
                    'status'     => 'error',
                    'code'       => 'UNAUTHORIZED',
                    'reason'     => $verification['reason'],
                    'request_id' => $requestIdHeader,
                ],
            ];
        }

        // 2. Parse JSON payload
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->sdk->log('warning', "Feedple Bridge: invalid JSON payload: {$e->getMessage()}");
            return [
                'status_code' => 400,
                'body' => [
                    'status'     => 'error',
                    'code'       => 'INVALID_JSON',
                    'reason'     => 'Malformed JSON request body: ' . $e->getMessage(),
                    'request_id' => $requestIdHeader,
                ],
            ];
        }

        if (!is_array($data)) {
            return [
                'status_code' => 400,
                'body' => [
                    'status'     => 'error',
                    'code'       => 'INVALID_PAYLOAD',
                    'reason'     => 'Request payload must be a JSON object',
                    'request_id' => $requestIdHeader,
                ],
            ];
        }

        $requestId = (string) ($data['request_id'] ?? $requestIdHeader ?? '');
        $ir = $data['ir'] ?? null;

        if (!is_array($ir) || empty($ir)) {
            $this->sdk->log('warning', "Feedple Bridge: missing IR object in payload (request_id: {$requestId})");
            return [
                'status_code' => 422,
                'body' => [
                    'status'     => 'error',
                    'code'       => 'MISSING_IR',
                    'reason'     => "Payload missing valid 'ir' query object",
                    'request_id' => $requestId,
                ],
            ];
        }

        // 3. Execute IR query via SDK
        try {
            $result = $this->sdk->executeIr($ir);

            return [
                'status_code' => 200,
                'body' => [
                    'status'      => 'success',
                    'data'        => $result['rows'],
                    'rows'        => $result['rows'],
                    'count'       => $result['count'],
                    'duration_ms' => $result['duration_ms'],
                    'request_id'  => $requestId,
                ],
            ];
        } catch (\Throwable $e) {
            $safeReason = $e->getMessage();
            $code = 'EXECUTION_ERROR';

            if (strpos($safeReason, 'Access denied to table') !== false) {
                $code = 'POLICY_VIOLATION';
            }

            $this->sdk->log('error', "Feedple Bridge: IR execution error [{$code}]: {$safeReason} (request_id: {$requestId})");

            return [
                'status_code' => 200,
                'body' => [
                    'status'     => 'error',
                    'code'       => $code,
                    'reason'     => $safeReason,
                    'request_id' => $requestId,
                ],
            ];
        }
    }
}
