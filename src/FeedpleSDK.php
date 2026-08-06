<?php

declare(strict_types=1);

namespace Feedple\Sdk;

use Feedple\Sdk\Core\FeedpleWebSocket;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\IrBuilder;
use Feedple\Sdk\Core\PolicyEngine;
use Feedple\Sdk\Core\SchemaServices;
use Feedple\Sdk\Core\SqlCompiler;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

// ── URL constants (mirrors request.py) ──────────────────────────────────────

/** @internal */
const FEEDPLE_DEV_ENV = true;

const FEEDPLE_API_BASE_URL = (FEEDPLE_DEV_ENV ? 'http://localhost:8000' : 'https://feedple-ai.onrender.com') . '/api/v1';
const FEEDPLE_WS_URL       = (FEEDPLE_DEV_ENV ? 'ws://localhost:8000'  : 'wss://feedple-ai.onrender.com')  . '/api/v1/tenants/ws';

/**
 * Main entry point for the Feedple PHP SDK — framework-agnostic, OS-agnostic.
 *
 * Unlike the earlier pcntl_fork()-based version, this design has NO Linux/macOS
 * dependency and NO Laravel dependency:
 *
 *  - Background execution is done via `proc_open()`, a core PHP function
 *    available on Windows, Linux, and macOS alike (no pcntl/posix extension).
 *  - `proc_open()` starts a genuinely separate PHP process (not a fork of the
 *    current one), so the constructor returns immediately — the same
 *    observable behaviour as Python's `threading.Thread(daemon=True).start()`.
 *  - Because it's a separate process, it cannot inherit your open `\PDO`
 *    handle or any `\Closure`. Instead, you point it at a small PHP script
 *    that returns a fresh `\PDO` when `require`d — plain, portable PHP with
 *    no framework coupling required.
 *
 * Requires: nothing beyond core PHP + Composer (react/event-loop). No
 * pcntl, no posix, no spatie/fork. Works from a plain PHP script, a Laravel
 * app, or anything else that can `new FeedpleSDK(...)`.
 *
 * Usage (plain PHP, no framework):
 * ```php
 * require __DIR__ . '/vendor/autoload.php';
 *
 * use Feedple\Sdk\FeedpleSDK;
 * use Feedple\Sdk\DbConfig;
 * use Feedple\Sdk\Core\Identity;
 *
 * $sdk = new FeedpleSDK(
 *     apiKey:   'sk_live_...',
 *     dbConfig: DbConfig::mysql(
 *         host:     '127.0.0.1',
 *         database: 'myapp',
 *         username: 'db_user',
 *         password: 'db_password',
 *     ),
 *     identity: new Identity(name: 'admin', allTables: true),
 * );
 * // returns immediately — the SDK is now running in a background process.
 * // ... rest of your script continues here ...
 * $sdk->stop();
 * ```
 *
 * For SQLite: `DbConfig::sqlite('/path/to/database.sqlite')` (no credentials
 * needed). For anything else: `DbConfig::pgsql(...)` or the escape hatch
 * `DbConfig::raw($dsn, $username, $password, $options)`.
 *
 * Why DbConfig instead of an already-built \PDO or loose dsn/user/pass
 * strings: a \PDO object doesn't expose its own connection string or
 * credentials (intentionally — PDO has no getter for these), so it can't be
 * introspected to rebuild an equivalent connection in the worker process.
 * DbConfig also marks the password with #[\SensitiveParameter] so it's
 * redacted from PHP's exception stack traces (which loose constructor
 * parameters are not), and its __debugInfo()/jsonSerialize() never expose
 * the raw password either.
 */
class FeedpleSDK
{
    private ?FeedpleWebSocket $ws = null;
    private ?LoopInterface    $loop = null;
    private ?string           $previousHash = null;
    private readonly \PDO     $db;

    /** PID of the detached background worker process (set only in the launching process). */
    private ?int $workerPid = null;

    /** Where control/pid/log files for this instance live. */
    private readonly string $runtimeDir;

    /**
     * @param  string   $apiKey          Feedple API key (required, non-empty).
     * @param  DbConfig $dbConfig        Connection config, built via DbConfig::mysql()/pgsql()/
     *                                    sqlite()/raw(). Used to build a \PDO for THIS process
     *                                    (initial connectivity check, and any direct calls like
     *                                    syncSchema()/buildCompiler() you make yourself), and the
     *                                    same config is passed to the background worker so it
     *                                    builds an equivalent connection independently.
     * @param  Identity $identity        RBAC identity controlling which tables are exposed.
     * @param  string|null $autoloadPath Path to composer's vendor/autoload.php. If omitted, the SDK
     *                                    tries a couple of common relative locations and throws a
     *                                    clear error if none are found.
     * @param  bool     $autoSync        When true, periodically re-syncs the schema.
     * @param  int      $syncInterval    Seconds between schema sync cycles.
     * @param  bool     $reconnectEnabled Whether the WebSocket reconnects on disconnect.
     * @param  int|null $maxRetries      Maximum reconnect attempts (null = unlimited).
     * @param  bool     $probeBeforeConnect Perform an HTTP probe before the WS handshake.
     * @param  LoggerInterface|null $logger PSR-3 logger; a plain log file is used by default.
     * @param  string|null $runtimeDir   Directory for this instance's control/pid/log files.
     *                                    Defaults to sys_get_temp_dir().
     *
     * @throws \InvalidArgumentException if $apiKey is empty
     * @throws \RuntimeException         if the database connection cannot be established
     * @throws \RuntimeException         if the background worker process fails to start
     */
    public function __construct(
        private readonly string     $apiKey,
        private readonly DbConfig   $dbConfig,
        private readonly Identity   $identity,
        ?string                     $autoloadPath        = null,
        private readonly bool       $autoSync            = true,
        private readonly int        $syncInterval        = 60,
        private readonly bool       $reconnectEnabled    = true,
        private readonly ?int       $maxRetries          = null,
        private readonly bool       $probeBeforeConnect  = false,
        private readonly ?LoggerInterface $logger        = null,
        ?string                     $runtimeDir          = null,
        /** @internal set only by runWorker(); skips re-spawning a child process. */
        private readonly bool       $isWorkerProcess     = false,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'api_key is required. Please provide a valid Feedple API key.'
            );
        }

        // Build our own connection from this config — used by this process,
        // and the same config is handed to the worker process so it builds
        // an equivalent connection independently.
        try {
            $this->db = $dbConfig->toPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Feedple SDK could not connect to the database: " . $e->getMessage(),
                previous: $e
            );
        }

        $this->runtimeDir = $runtimeDir ?? sys_get_temp_dir();

        $this->log('info', 'Feedple: initializing SDK...');

        // ── Verify DB connectivity using the caller's PDO ───────────────────
        $this->verifyDbConnection();

        if ($this->autoSync) {
            $this->log('info', "Feedple: auto-sync enabled (interval: {$this->syncInterval}s)");
        }

        if ($isWorkerProcess) {
            // We're inside the spawned background process already — set up
            // the WebSocket client and event loop here instead of spawning
            // another one.
            $this->initRuntime($autoloadPath);
            $this->log('info', 'Feedple: SDK initialized successfully (worker process)');
            return;
        }

        // ── Idempotency: skip spawning if a worker is already running ──────
        // Safe to call `new FeedpleSDK(...)` multiple times (e.g. re-running
        // a deploy command, or a @reboot cron entry) without stacking up
        // duplicate background processes.
        if ($this->isWorkerAlreadyRunning()) {
            $this->log('info', 'Feedple: background worker already running, skipping spawn');
            return;
        }

        // ── Spawn the background worker and return immediately ─────────────
        $this->startBackgroundWorker($autoloadPath);

        $this->log('info', 'Feedple: SDK initialized successfully');
    }

    /**
     * Checks whether a worker process from a previous run is still alive,
     * using the pid file. Uses cross-platform techniques (no posix
     * extension) so this works on Windows too.
     */
    private function isWorkerAlreadyRunning(): bool
    {
        $pidFile = $this->pidFilePath();
        if (!is_file($pidFile)) {
            return false;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec(sprintf('tasklist /FI "PID eq %d" 2>NUL', $pid));
            return $output !== null && str_contains($output, (string) $pid);
        }

        // `kill -0` is a POSIX shell built-in that checks whether a process
        // exists without actually signaling it — no posix PHP extension
        // required, so this works the same on Linux and macOS.
        exec(sprintf('kill -0 %d 2>/dev/null', $pid), result_code: $exitCode);
        return $exitCode === 0;
    }

    /**
     * Build the WebSocket client + event loop. Only ever called inside the
     * worker process (isWorkerProcess = true).
     */
    private function initRuntime(?string $autoloadPath): void
    {
        $this->loop = Loop::get();

        $this->ws = new FeedpleWebSocket(
            wsUrl:  FEEDPLE_WS_URL,
            apiKey: $this->apiKey,
            loop:   $this->loop,
            logger: $this->buildLogger(),
        );
        $this->ws->reconnectEnabled   = $this->reconnectEnabled;
        $this->ws->maxRetries         = $this->maxRetries;
        $this->ws->probeBeforeConnect = $this->probeBeforeConnect;
        $this->ws->onIrRequest(fn(array $ir): array => $this->executeIr($ir));

        $this->log('info', 'Feedple: WebSocket client configured');
    }

    /**
     * Spawn a detached background PHP process running the worker entry
     * point (worker.php), then return immediately. Uses proc_open(), which
     * is core PHP and works on Windows, Linux, and macOS — no pcntl/posix
     * required, unlike a pcntl_fork()-based approach.
     *
     * Mirrors: threading.Thread(target=self._run_loop, daemon=True).start()
     *
     * @throws \RuntimeException if the worker process fails to start
     */
    private function startBackgroundWorker(?string $autoloadPath): void
    {
        $autoloadPath ??= $this->guessAutoloadPath();

        $controlFile = $this->runtimeDir . DIRECTORY_SEPARATOR . 'feedple-control-' . bin2hex(random_bytes(8)) . '.json';
        $logFile     = $this->defaultLogPath();
        $pidFile     = $this->pidFilePath();

        $config = [
            'api_key'              => $this->apiKey,
            'identity'             => base64_encode(serialize($this->identity)),
            'db_config'            => $this->dbConfig->toArray(),
            'autoload_path'        => $autoloadPath,
            'auto_sync'            => $this->autoSync,
            'sync_interval'        => $this->syncInterval,
            'reconnect_enabled'    => $this->reconnectEnabled,
            'max_retries'          => $this->maxRetries,
            'probe_before_connect' => $this->probeBeforeConnect,
            'log_path'             => $logFile,
        ];

        // Control file contains DB credentials in plaintext JSON — it's
        // deleted immediately once the worker reads it (see runWorker()),
        // but restrict its permissions in the meantime.
        if (file_put_contents($controlFile, json_encode($config, JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException("Feedple: could not write control file to {$controlFile}");
        }
        @chmod($controlFile, 0600);

        $workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'worker.php';
        if (!is_file($workerScript)) {
            throw new \RuntimeException("Feedple: worker.php not found at {$workerScript}");
        }

        $command = [PHP_BINARY, $workerScript, $controlFile];

        // Redirect all standard descriptors to files/null instead of pipes,
        // so the parent never blocks waiting to read from the child and the
        // resource can be safely discarded right after launch.
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            throw new \RuntimeException('Feedple: could not start background worker process');
        }

        // We never write to the child's stdin — close it immediately so the
        // child doesn't block waiting on input.
        fclose($pipes[0]);

        $status = proc_get_status($process);
        $pid    = $status['pid'] ?? null;

        if ($pid === null) {
            throw new \RuntimeException('Feedple: background worker started but PID could not be determined');
        }

        file_put_contents($pidFile, (string) $pid);
        $this->workerPid = $pid;

        // Intentionally not calling proc_close()/proc_terminate() here — we
        // want the worker to keep running independently of this process.
        // Dropping the resource reference lets PHP's garbage collector
        // release the handle without killing the child on any of the three
        // major OSes.
        unset($process);

        $this->log('info', "Feedple: background worker started (pid {$pid})");
    }

    /**
     * Best-effort guess at vendor/autoload.php relative to this file's
     * location. Works for the common `vendor/feedple/sdk-php/src/...`
     * install layout; pass $autoloadPath explicitly if your layout differs.
     */
    private function guessAutoloadPath(): string
    {
        $candidates = [
            dirname(__DIR__, 3) . '/autoload.php',        // vendor/feedple/sdk-php/src/.. -> vendor/autoload.php
            dirname(__DIR__, 2) . '/vendor/autoload.php',  // sdk-php/src/.. -> sdk-php/vendor/autoload.php
            dirname(__DIR__)    . '/vendor/autoload.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Feedple: could not locate vendor/autoload.php automatically. Pass $autoloadPath '
            . 'explicitly to the FeedpleSDK constructor.'
        );
    }

    /**
     * Entry point used by worker.php to build and run the SDK inside the
     * spawned background process.
     *
     * @internal
     */
    public static function runWorker(string $controlFilePath): void
    {
        $config = json_decode((string) file_get_contents($controlFilePath), true, flags: JSON_THROW_ON_ERROR);

        $identity = unserialize(base64_decode($config['identity']));
        $dbConfig = DbConfig::fromArray($config['db_config']);

        $sdk = new self(
            apiKey:              $config['api_key'],
            dbConfig:            $dbConfig,
            identity:            $identity,
            autoloadPath:        $config['autoload_path'],
            autoSync:            $config['auto_sync'],
            syncInterval:        $config['sync_interval'],
            reconnectEnabled:    $config['reconnect_enabled'],
            maxRetries:          $config['max_retries'],
            probeBeforeConnect:  $config['probe_before_connect'],
            isWorkerProcess:     true,
        );

        // Clean up the control file — it's only needed once, at startup.
        @unlink($controlFilePath);

        $sdk->run();
    }

    /**
     * Runs the WebSocket connection and the periodic schema-sync loop on a
     * single ReactPHP event loop. Only meant to be called from within the
     * worker process (via runWorker()) — this call blocks forever (until
     * the loop is stopped), which is correct there since nothing is waiting
     * on it.
     *
     * Mirrors: await asyncio.gather(self._ws.connect(), self._sync_worker())
     */
    public function run(): void
    {
        $this->log('info', 'Feedple: starting WebSocket and sync worker...');

        $this->ws->connect();

        if ($this->autoSync) {
            $this->scheduleSyncWorker();
        }

        $this->log('info', 'Feedple: event loop running');
        $this->loop->run();
        $this->log('info', 'Feedple: event loop stopped');
    }

    /**
     * Schedule the periodic schema sync worker on the event loop.
     *
     * Mirrors: async def _sync_worker(self) -> None
     */
    private function scheduleSyncWorker(): void
    {
        $this->log('info', 'Feedple: sync worker started, waiting for WebSocket authentication...');

        // Register callback to run sync immediately upon authentication
        $this->ws->onAuthenticated(function (): void {
            $this->log('info', 'Feedple: WebSocket authenticated, triggering initial schema sync...');
            try {
                $this->syncSchema();
            } catch (\Throwable $e) {
                $this->log('warning', "Feedple: schema sync failed: {$e->getMessage()}");
            }
        });

        // Also check if already authenticated
        if ($this->ws->isAuthenticated()) {
            $this->log('info', 'Feedple: already authenticated, running initial sync...');
            try {
                $this->syncSchema();
            } catch (\Throwable $e) {
                $this->log('warning', "Feedple: schema sync failed: {$e->getMessage()}");
            }
        }

        $this->loop->addPeriodicTimer((float) $this->syncInterval, function (): void {
            $this->runSyncCycle();
        });
    }

    private function runSyncCycle(): void
    {
        if (!$this->ws->isAuthenticated()) {
            $this->log('warning', 'Feedple: sync worker not authenticated yet, skipping periodic sync...');
            return;
        }

        $this->log('info', 'Feedple: sync worker authenticated, running periodic sync...');

        try {
            $this->syncSchema();
        } catch (\Throwable $e) {
            $this->log('warning', "Feedple: schema sync failed: {$e->getMessage()}");
        }

        $this->log('info', "Feedple: next sync in {$this->syncInterval}s");
    }

    /**
     * Inspect the database schema, hash it, and send to Feedple if changed.
     * Can be called either from the worker process (as part of the periodic
     * loop) or directly from your own code — both use a connection built
     * from the same $dbConfig.
     *
     * Mirrors: async def sync_schema(self) -> None
     */
    public function syncSchema(): void
    {
        $this->log('info', 'Feedple: inspecting database schema...');

        $schema = SchemaServices::getSchema($this->db, $this->identity);

        $this->log('info', sprintf('Feedple: schema inspected (%d tables found)', count($schema)));

        $newHash = SchemaServices::generateSchemaHash($schema);
        if ($this->previousHash !== null && $this->previousHash === $newHash) {
            $this->log('info', 'Feedple: schema unchanged, skipping sync');
            return;
        }

        if ($this->ws === null) {
            $this->log('info', 'Feedple: skipping schema transmission (main process has no WebSocket connection; the background worker handles this)');
            $this->previousHash = $newHash;
            return;
        }

        $this->log('info', 'Feedple: sending schema via WebSocket...');
        $this->ws->sendSchema($schema);
        $this->previousHash = $newHash;
        $this->log('info', 'Feedple: schema sent successfully');
    }

    /**
     * Stop the background worker. Can be called from a different PHP
     * process/request than the one that started it — it just needs to know
     * where the pid file lives (same $runtimeDir).
     *
     * Uses exec()-based kill commands rather than posix_kill(), since posix
     * is a Unix-only extension and this SDK targets any OS.
     */
    public function stop(): void
    {
        $this->log('info', 'Feedple: stopping SDK...');

        if ($this->isWorkerProcess) {
            // Called from inside the worker itself (e.g. a signal handler).
            $this->loop?->stop();
            $this->log('info', 'Feedple: SDK stopped');
            return;
        }

        $pidFile = $this->pidFilePath();
        $pid     = $this->workerPid ?? (is_file($pidFile) ? (int) file_get_contents($pidFile) : null);

        if ($pid === null) {
            $this->log('warning', 'Feedple: no known worker PID to stop');
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec(sprintf('taskkill /F /T /PID %d 2>NUL', $pid));
        } else {
            exec(sprintf('kill -TERM %d 2>/dev/null', $pid));
        }

        @unlink($pidFile);
        $this->workerPid = null;

        $this->log('info', 'Feedple: SDK stopped');
    }

    /**
     * Build a SQLCompiler for direct SQL validation/compilation.
     * Note: Only use for raw SQL cases — prefer IR-based queries.
     */
    public function buildCompiler(): SqlCompiler
    {
        $policy = new PolicyEngine($this->identity);
        return new SqlCompiler($policy);
    }

    /**
     * Execute an IR query against the database and return results.
     *
     * @param  array<string, mixed> $ir
     * @return array{rows: list<array<string, mixed>>, count: int, duration_ms: int}
     */
    private function executeIr(array $ir): array
    {
        $this->log('info', 'Feedple: validating IR request...');

        $policy = new PolicyEngine($this->identity);
        $policy->validateIrAccess($ir);

        $this->log('info', 'Feedple: executing IR request...');

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

        $this->log('info', 'Feedple: executing query against database...');
        $start = microtime(true);

        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $value) {
            $paramIndex = $index + 1;
            if (is_int($value)) {
                $stmt->bindValue($paramIndex, $value, \PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($paramIndex, $value, \PDO::PARAM_BOOL);
            } elseif (is_null($value)) {
                $stmt->bindValue($paramIndex, $value, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($paramIndex, $value, \PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $this->log('info', sprintf('Feedple: IR executed (%d rows, %dms)', count($rows), $durationMs));

        return [
            'rows'        => $rows,
            'count'       => count($rows),
            'duration_ms' => $durationMs,
        ];
    }

    private function verifyDbConnection(): void
    {
        $this->log('info', 'Feedple: verifying database connection...');
        try {
            $this->db->query('SELECT 1');
            $this->log('info', 'Feedple: database connection verified');
        } catch (\Throwable $e) {
            throw new \RuntimeException("Feedple SDK could not connect to the database: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Log a message via the injected PSR-3 logger or fall back to a plain
     * file. No stdio dependency, so it survives the process boundary and
     * any request lifecycle cleanly, on any OS.
     */
    public function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->$level($message);
            return;
        }

        $line = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), strtoupper($level), $message, PHP_EOL);

        try {
            file_put_contents($this->defaultLogPath(), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // logging must never take down the caller or the worker
        }
    }

    private function defaultLogPath(): string
    {
        return $this->runtimeDir . DIRECTORY_SEPARATOR . 'feedple-sdk.log';
    }

    private function pidFilePath(): string
    {
        return $this->runtimeDir . DIRECTORY_SEPARATOR . 'feedple-sdk.pid';
    }

    private function buildLogger(): LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        return new class($this) implements LoggerInterface {
            public function __construct(private readonly FeedpleSDK $sdk) {}
            public function emergency(\Stringable|string $message, array $context = []): void { $this->write('emergency', $message); }
            public function alert(\Stringable|string $message, array $context = []): void     { $this->write('alert',     $message); }
            public function critical(\Stringable|string $message, array $context = []): void  { $this->write('critical',  $message); }
            public function error(\Stringable|string $message, array $context = []): void     { $this->write('error',     $message); }
            public function warning(\Stringable|string $message, array $context = []): void   { $this->write('warning',   $message); }
            public function notice(\Stringable|string $message, array $context = []): void    { $this->write('notice',    $message); }
            public function info(\Stringable|string $message, array $context = []): void      { $this->write('info',      $message); }
            public function debug(\Stringable|string $message, array $context = []): void     { $this->write('debug',     $message); }
            public function log($level, \Stringable|string $message, array $context = []): void { $this->write((string) $level, $message); }
            private function write(string $level, \Stringable|string $message): void {
                $this->sdk->log($level, (string) $message);
            }
        };
    }
}