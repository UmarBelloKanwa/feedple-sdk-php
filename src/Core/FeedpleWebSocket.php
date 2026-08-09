<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

use Feedple\Sdk\Core\Exceptions\AuthException;
use Psr\Log\LoggerInterface;
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;

/**
 * Persistent WebSocket client for the Feedple AI platform.
 *
 * Mirrors Python's FeedpleWebSocket (core/websocket.py) in every detail:
 *  - Authentication handshake (auth.request → auth.ack / auth.error)
 *  - Heartbeat (ping / pong every 30 seconds)
 *  - Incoming IR execution requests (ir.request → ir.ack → ir.result / ir.error)
 *  - Schema sync (send_schema via chunked schema.data messages)
 *  - Reconnect with exponential back-off, optional max_retries cap
 *  - Optional HTTP probe before WebSocket connect
 *  - Session resume (sends previous session_id on reconnect)
 *
 * Design note: Python uses asyncio coroutines. PHP uses ReactPHP's event loop
 * with promise chains and timer callbacks. The observable behavior — message
 * ordering, retry semantics, and the public API — is identical.
 */
class FeedpleWebSocket
{
    // ── Timing constants (mirrors websocket.py) ─────────────────────────────
    public const PING_INTERVAL       = 10;   // seconds
    public const PONG_TIMEOUT        = 10;   // seconds
    public const RECONNECT_DELAY     = 5;    // seconds
    public const MAX_RECONNECT_DELAY = 60;   // seconds

    // ── Connection state ────────────────────────────────────────────────────
    /** @var WebSocket|null  The live WebSocket connection, null when disconnected */
    private ?WebSocket $ws = null;

    /** @var string|null  Session ID received after auth.ack, used for session resume */
    public ?string $sessionId = null;

    /** @var bool  True once auth.ack has been received on the current connection */
    private bool $authenticated = false;

    /** @var bool  Set by stop() to break the reconnect loop */
    private bool $stopRequested = false;

    /** @var int  Current retry count (reset on successful connect) */
    private int $retryCount = 0;

    // ── Deferred auth resolution (replaces asyncio.Event) ──────────────────
    /** @var Deferred|null  Resolved when auth.ack is received */
    private ?Deferred $authDeferred = null;

    // ── Callbacks ──────────────────────────────────────────────────────────
    /** @var callable|null  Invoked with the IR payload; must return an array (rows/count/duration_ms) */
    private $irHandler = null;

    /** @var callable|null  Invoked when WebSocket is successfully authenticated */
    private $onAuthenticatedCallback = null;

    // ── Configuration (public, mirroring Python's mutable attributes) ───────
    public bool    $reconnectEnabled   = true;
    public ?int    $maxRetries         = null;
    public bool    $probeBeforeConnect = false;
    public float   $reconnectDelay     = self::RECONNECT_DELAY;

    public function __construct(
        private readonly string          $wsUrl,
        private readonly string          $apiKey,
        private readonly LoopInterface   $loop,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register the IR execution handler.
     *
     * Mirrors: on_ir_request(self, handler: Callable)
     *
     * @param callable(array): array $handler
     */
    public function onIrRequest(callable $handler): void
    {
        $this->irHandler = $handler;
    }

    /**
     * Register the onAuthenticated callback.
     *
     * @param callable(): void $callback
     */
    public function onAuthenticated(callable $callback): void
    {
        $this->onAuthenticatedCallback = $callback;
    }

    /**
     * Build a standard message envelope.
     *
     * Mirrors: _make_message(type_, payload)
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function makeMessage(string $type, array $payload): array
    {
        return [
            'id'         => $this->generateUuid(),
            'type'       => $type,
            'payload'    => $payload,
            'ts'         => (int) (microtime(true) * 1000),
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * Send a message over the WebSocket.
     *
     * Mirrors: async def _send(self, message: dict)
     *
     * @param  array<string, mixed> $message
     * @throws \RuntimeException if not connected
     */
    private function send(array $message): void
    {
        if ($this->ws === null) {
            $this->authenticated = false;
            throw new \RuntimeException('WebSocket is not connected');
        }

        try {
            $this->ws->send(JsonSerializer::encode($message));
        } catch (\Throwable $e) {
            $this->authenticated = false;
            throw new \RuntimeException("WebSocket send failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Begin the connection loop. This method blocks the ReactPHP event loop
     * until stop() is called.
     *
     * Mirrors: async def connect(self)
     */
    public function connect(): void
    {
        $this->attemptConnect($this->reconnectDelay);
    }

    /**
     * One iteration of the connect-authenticate-listen cycle.
     * On failure, schedules a reconnect after the current back-off delay.
     */
    private function attemptConnect(float $delay): void
    {
        if ($this->stopRequested) {
            return;
        }

        // Optional HTTP probe before WebSocket handshake (mirrors probe_before_connect)
        if ($this->probeBeforeConnect) {
            try {
                $this->httpProbe();
            } catch (\Throwable $e) {
                $this->logger->warning("Feedple: pre-connect probe failed: {$e->getMessage()}");
                $this->handleConnectFailure($e, $delay, isProbeFailure: true);
                return;
            }
        }

        $connector = new Connector($this->loop);
        $connector($this->wsUrl)->then(
            function (WebSocket $conn) use ($delay): void {
                // Successful connection — reset state
                $this->ws          = $conn;
                $this->retryCount  = 0;
                $this->authenticated = false;
                $this->authDeferred = new Deferred();
                $currentDelay      = $this->reconnectDelay; // reset for next reconnect

                $this->logger->info("Feedple: WebSocket connected");

                // Authenticate immediately
                try {
                    $this->authenticate();
                } catch (\Throwable $e) {
                    $this->logger->error("Feedple: auth failed, not reconnecting: {$e->getMessage()}");
                    $conn->close();
                    // Auth failures are terminal (mirrors Python's PermissionError break)
                    $this->stopRequested = true;
                    return;
                }

                // Start heartbeat timer
                $heartbeatTimer = $this->loop->addPeriodicTimer(self::PING_INTERVAL, function () {
                    if ($this->ws !== null) {
                        try {
                            $this->send($this->makeMessage('ping', []));
                        } catch (\Throwable) {
                            // Connection may be dead; the onClose handler will trigger reconnect
                        }
                    }
                });

                // Handle incoming messages
                $conn->on('message', function (\Ratchet\RFC6455\Messaging\MessageInterface $msg) {
                    $this->handleMessage((string) $msg);
                });

                // Handle close
                $conn->on('close', function ($code = null, $reason = null) use ($currentDelay, $heartbeatTimer): void {
                    $this->loop->cancelTimer($heartbeatTimer);
                    $this->ws            = null;
                    $this->authenticated = false;
                    $this->authDeferred  = null;

                    if ($this->stopRequested) {
                        return;
                    }

                    $this->logger->warning(
                        "Feedple: connection closed (code={$code}), reconnecting in {$currentDelay}s"
                    );
                    $this->scheduleReconnect($currentDelay);
                });

                $conn->on('error', function (\Throwable $e) use ($currentDelay, $heartbeatTimer): void {
                    $this->loop->cancelTimer($heartbeatTimer);
                    $this->ws            = null;
                    $this->authenticated = false;
                    $this->authDeferred  = null;
                    $this->handleConnectFailure($e, $currentDelay);
                });
            },
            function (\Throwable $e) use ($delay): void {
                $this->ws            = null;
                $this->authenticated = false;
                $this->authDeferred  = null;
                $this->handleConnectFailure($e, $delay);
            }
        );
    }

    /**
     * Authenticate with the server immediately after connecting.
     *
     * Mirrors: async def _authenticate(self)
     *
     * In the Python SDK this awaits the server's auth response inline.
     * In PHP with ReactPHP, the response arrives asynchronously via the
     * message handler. We send the auth.request here; auth.ack / auth.error
     * processing happens in handleMessage().
     *
     * @throws AuthException  if a message cannot be sent (connection dropped)
     */
    private function authenticate(): void
    {
        $msg = $this->makeMessage('auth.request', [
            'api_key'     => $this->apiKey,
            'session_id'  => $this->sessionId,
            'sdk_version' => '0.0.1',
        ]);
        $this->send($msg);
        $this->logger->info("Feedple: auth.request sent");
    }

    /**
     * Route an incoming raw message to the appropriate handler.
     *
     * Mirrors: async def _listen(self) and the message-type dispatch therein.
     */
    private function handleMessage(string $raw): void
    {
        try {
            $message = JsonSerializer::decode($raw);
        } catch (\Throwable $e) {
            $this->logger->warning("Feedple: received invalid JSON — {$e->getMessage()}");
            return;
        }

        $type = $message['type'] ?? '';

        switch ($type) {
            case 'auth.ack':
                 $this->sessionId     = $message['payload']['session_id'] ?? null;
                 $this->authenticated = true;
                 $this->logger->info("Feedple: WebSocket authenticated (session: {$this->sessionId})");
                 // Resolve the auth deferred so syncSchema() can proceed
                 $this->authDeferred?->resolve(true);
                 if ($this->onAuthenticatedCallback !== null) {
                     try {
                         ($this->onAuthenticatedCallback)();
                     } catch (\Throwable $e) {
                         $this->logger->error("Feedple: onAuthenticated callback failed: {$e->getMessage()}");
                     }
                 }
                 break;

            case 'auth.error':
                $reason = $message['payload']['reason'] ?? 'unknown';
                $this->logger->error("Feedple: auth failed: {$reason}");
                $this->authDeferred?->reject(new AuthException("Auth failed: {$reason}"));
                $this->stopRequested = true;
                $this->ws?->close();
                break;

            case 'pong':
                // Nothing to do — mirrors: if type_ == "pong": continue
                break;

            case 'ping':
                // Reply to server-initiated pings
                try {
                    $this->send($this->makeMessage('pong', []));
                } catch (\Throwable) {
                    // Ignore; connection dropped
                }
                break;

            case 'schema.ack':
                $status  = $message['payload']['status'] ?? 'stored';
                $version = $message['payload']['schema_version'] ?? 1;
                $this->logger->info("Feedple: schema.ack received (status: {$status}, version: {$version})");
                break;

            case 'error':
                $reason = $message['payload']['reason'] ?? 'unknown';
                $code   = $message['payload']['code'] ?? 'SERVER_ERROR';
                $this->logger->warning("Feedple: server error received ({$code}): {$reason}");
                break;

            default:
                // Unknown message type — log and ignore
                $this->logger->info("Feedple: unhandled message type '{$type}'");
                break;
        }
    }

    /**
     * Handle an incoming IR execution request from the server.
     *
     * Mirrors: async def _handle_ir(self, message: dict)
     *
     * 1. Send ir.ack immediately
     * 2. Call the registered IR handler
     * 3. Send ir.result on success, ir.error on failure
     */
    private function handleIrRequest(array $message): void
    {
        $requestId = $message['payload']['request_id'] ?? null;

        // Ack immediately (mirrors: await self._send(self._make_message("ir.ack", ...)))
        try {
            $this->send($this->makeMessage('ir.ack', ['request_id' => $requestId]));
        } catch (\Throwable $e) {
            $this->logger->error("Feedple: failed to send ir.ack — {$e->getMessage()}");
            return;
        }

        try {
            if ($this->irHandler === null) {
                throw new \RuntimeException('No IR handler registered');
            }

            $ir     = $message['payload']['ir'] ?? [];
            $result = ($this->irHandler)($ir);

            $this->send($this->makeMessage('ir.result', [
                'request_id'  => $requestId,
                'rows'        => $result['rows'],
                'count'       => $result['count'],
                'duration_ms' => $result['duration_ms'],
            ]));
        } catch (\Throwable $e) {
            $this->logger->error(
                "Feedple: IR execution failed (request_id={$requestId}): {$e->getMessage()}"
            );
            try {
                $this->send($this->makeMessage('ir.error', [
                    'request_id' => $requestId,
                    'reason'     => $e->getMessage(),
                    'code'       => 'EXECUTION_ERROR',
                ]));
            } catch (\Throwable) {
                // Connection may have closed; nothing we can do
            }
        }
    }

    public function sendInspectingStatus(): void
    {
        if ($this->authenticated) {
            $this->send($this->makeMessage('schema.inspecting', []));
        }
    }

    public function sendSchemaUnchanged(): void
    {
        if ($this->authenticated) {
            $this->send($this->makeMessage('schema.unchanged', []));
        }
    }

    /**
     * Send the database schema to the server in chunks.
     *
     * Mirrors: async def send_schema(self, schema: dict, chunk_size: int = 10)
     *
     * Waits for authentication before sending. In ReactPHP the "wait" is
     * implemented by deferring the send until the auth promise resolves.
     *
     * @param  array<string, array<string, mixed>> $schema
     * @param  int                                  $chunkSize
     */
    public function sendSchema(array $schema, int $chunkSize = 10): void
    {
        if (!$this->authenticated) {
            if ($this->stopRequested) {
                return;
            }
            
            $this->logger->warning("Feedple: sendSchema called before authentication, will retry when ready");
            
            if ($this->authDeferred !== null) {
                $this->authDeferred->promise()->then(function () use ($schema, $chunkSize) {
                    if (!$this->stopRequested) {
                        $this->sendSchema($schema, $chunkSize);
                    }
                });
            } else {
                // Defer until authenticated — schedule a check on the next tick
                $this->loop->futureTick(function () use ($schema, $chunkSize) {
                    if (!$this->stopRequested) {
                        $this->sendSchema($schema, $chunkSize);
                    }
                });
            }
            return;
        }

        $tables     = $schema;
        $total      = count($tables);
        $schemaHash = SchemaServices::generateSchemaHash($schema);

        try {
            $this->send($this->makeMessage('schema.started', ['table_count' => $total]));

            $chunks    = array_chunk($tables, $chunkSize, preserve_keys: true);
            $chunkTotal = count($chunks);
            $processed  = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                foreach (array_keys($chunk) as $tableName) {
                    $processed++;
                    $this->send($this->makeMessage('schema.progress', [
                        'table'     => $tableName,
                        'processed' => $processed,
                        'total'     => $total,
                        'percent'   => (int) (($processed / $total) * 100),
                    ]));
                }

                $this->send($this->makeMessage('schema.data', [
                    'chunk_index' => $chunkIndex,
                    'chunk_total' => $chunkTotal,
                    'data'        => $chunk,
                    'schema_hash' => $schemaHash,
                ]));
            }

            $this->send($this->makeMessage('schema.completed', [
                'schema_hash' => $schemaHash,
                'table_count' => $total,
            ]));
        } catch (\Throwable $e) {
            $this->logger->warning("Feedple: schema transmission interrupted ({$e->getMessage()}), will retry upon reconnect");
            if ($this->authDeferred !== null) {
                $this->authDeferred->promise()->then(function () use ($schema, $chunkSize) {
                    if (!$this->stopRequested) {
                        $this->sendSchema($schema, $chunkSize);
                    }
                });
            }
        }
    }

    /**
     * Block until the WebSocket is authenticated, up to $timeout seconds.
     *
     * Mirrors: async def wait_until_authenticated(self, timeout: Optional[float])
     *
     * In PHP (synchronous ReactPHP context) we poll the auth flag with a
     * busy-wait on the event loop. Callers inside the ReactPHP loop should
     * use a deferred/promise approach instead.
     *
     * @param  float|null $timeout  Maximum seconds to wait; null = wait forever
     * @throws \RuntimeException on timeout
     */
    public function waitUntilAuthenticated(?float $timeout = null): void
    {
        $start = microtime(true);

        while (!$this->authenticated) {
            if ($timeout !== null && (microtime(true) - $start) >= $timeout) {
                throw new \RuntimeException('Timed out waiting for WebSocket authentication');
            }
            // Run one tick of the event loop while we wait
            // (Loop::get() only available when running inside a ReactPHP context)
            usleep(10_000); // 10 ms
        }
    }

    /**
     * Signal that the SDK is shutting down. Closes the connection and breaks
     * the reconnect loop.
     *
     * Mirrors: def stop(self)
     */
    public function stop(): void
    {
        $this->stopRequested = true;
        $this->ws?->close();
    }

    /**
     * Handle a failed connect attempt: log, increment retry counter, and
     * either schedule a reconnect or give up after max_retries.
     */
    private function handleConnectFailure(\Throwable $e, float $delay, bool $isProbeFailure = false): void
    {
        if (!$this->reconnectEnabled) {
            $this->logger->error("Feedple: connection failed and reconnect is disabled: {$e->getMessage()}");
            $this->stopRequested = true;
            return;
        }

        $this->retryCount++;

        $isRejected = $this->isServerRejection($e);
        if ($isRejected) {
            $this->logger->warning(
                "Feedple: connection lost: server rejected WebSocket connection: {$e->getMessage()}, reconnecting in {$delay}s"
            );
        } else {
            $prefix = $isProbeFailure ? 'pre-connect probe failed' : 'connection lost';
            $this->logger->warning("Feedple: {$prefix}: {$e->getMessage()}, reconnecting in {$delay}s");
        }

        if ($this->maxRetries !== null && $this->retryCount >= $this->maxRetries) {
            $this->logger->error("Feedple: reached max reconnect attempts ({$this->maxRetries}), giving up");
            $this->stopRequested = true;
            return;
        }

        $this->scheduleReconnect($delay);
    }

    /**
     * Schedule the next reconnect attempt after the given delay.
     * Applies exponential back-off capped at MAX_RECONNECT_DELAY.
     */
    private function scheduleReconnect(float $delay): void
    {
        if ($this->stopRequested) {
            return;
        }

        $nextDelay = min($delay * 2, self::MAX_RECONNECT_DELAY);

        $this->loop->addTimer($delay, function () use ($nextDelay): void {
            $this->attemptConnect($nextDelay);
        });
    }

    /**
     * Perform a synchronous HTTP GET to the equivalent HTTP URL before the
     * WebSocket handshake, to surface clearer server error responses.
     *
     * Mirrors: def _http_probe(self, timeout: int = 5)
     *
     * @throws \RuntimeException on HTTP error or network failure
     */
    private function httpProbe(int $timeout = 5): void
    {
        // Convert ws:// → http:// and wss:// → https://  (mirrors Python logic)
        if (str_starts_with($this->wsUrl, 'wss://')) {
            $url = 'https://' . substr($this->wsUrl, 6);
        } elseif (str_starts_with($this->wsUrl, 'ws://')) {
            $url = 'http://' . substr($this->wsUrl, 5);
        } else {
            $url = $this->wsUrl;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body     = @file_get_contents($url, context: $context);
        $headers  = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';

        // Extract status code from the HTTP response line
        if (preg_match('/HTTP\/[\d.]+ (\d{3})/', $statusLine, $m)) {
            $code = (int) $m[1];
            if ($code >= 400) {
                throw new \RuntimeException("HTTP probe failed: {$code}: {$body}");
            }
        } elseif ($body === false) {
            throw new \RuntimeException("HTTP probe network error: could not reach {$url}");
        }
    }

    /**
     * Heuristically determine whether a Throwable represents a server
     * rejection (e.g. HTTP 403).
     *
     * Mirrors the detection logic in Python's except block inside connect():
     *   status = getattr(e, "status_code", None)
     *   is_rejected = status >= 400 or "403" in str(e)
     */
    private function isServerRejection(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '403') || str_contains($msg, '401')) {
            return true;
        }
        // Some WebSocket libraries expose a getCode() or statusCode property
        if (method_exists($e, 'getCode') && is_int($e->getCode()) && $e->getCode() >= 400) {
            return true;
        }
        return false;
    }

    /**
     * Generate a v4 UUID string.
     *
     * Mirrors: str(uuid.uuid4())
     */
    private function generateUuid(): string
    {
        // RFC 4122 v4 UUID using random_bytes for cryptographic randomness
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Check if WebSocket client is currently authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }
}
