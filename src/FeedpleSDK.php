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
const FEEDPLE_DEV_ENV = false;

const FEEDPLE_API_BASE_URL = (FEEDPLE_DEV_ENV ? 'http://localhost:8000' : 'https://feedple-ai-614817435356.us-central1.run.app') . '/api/v1';
const FEEDPLE_WS_URL       = (FEEDPLE_DEV_ENV ? 'ws://localhost:8000'  : 'wss://feedple-ai-614817435356.us-central1.run.app')  . '/api/v1/tenants/ws';

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
    public const DEFAULT_TRANSPORT = 'webhook';
    public const DEFAULT_BRIDGE_PATH = '/api/feedple/bridge';
    public const DEFAULT_API_BASE_URL = FEEDPLE_API_BASE_URL;
    public const DEFAULT_WS_URL = FEEDPLE_WS_URL;
    /** @var FeedpleWebSocket|null */
    private $ws = null;
    /** @var LoopInterface|null */
    private $loop = null;
    /** @var string|null */
    private $previousHash = null;
    /** @var \PDO */
    private $db;

    /** PID of the detached background worker process (set only in the launching process). */
    /** @var int|null */
    private $workerPid = null;

    /** Where control/pid/log files for this instance live. */
    /** @var string */
    private $runtimeDir;

    /** @var string */
    private $apiKey;
    /** @var DbConfig */
    private $dbConfig;
    /** @var Identity */
    private $identity;
    /** @var bool */
    private $autoSync;
    /** @var int */
    private $syncInterval;
    /** @var bool */
    private $reconnectEnabled;
    /** @var int|null */
    private $maxRetries;
    /** @var bool */
    private $probeBeforeConnect;
    /** @var LoggerInterface|null */
    private $logger;
    /** @var resource|null Lock file resource kept open for the lifetime of the worker process */
    private static $workerLockHandle = null;

    /** @var bool */
    private $isWorkerProcess;
    /** @var string */
    private $transport;

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
     * @param  bool     $isWorkerProcess Internal flag set only by runWorker().
     * @param  string|null $transport    Transport mode: 'webhook' (stateless, default for web/shared hosting)
     *                                    or 'websocket' (persistent daemon).
     *
     * @throws \InvalidArgumentException if $apiKey is empty
     * @throws \RuntimeException         if the database connection cannot be established
     * @throws \RuntimeException         if the background worker process fails to start
     */
    public function __construct(
        string                     $apiKey,
        DbConfig                   $dbConfig,
        Identity                   $identity,
        ?string                    $autoloadPath        = null,
        bool                       $autoSync            = true,
        int                        $syncInterval        = 60,
        bool                       $reconnectEnabled    = true,
        ?int                       $maxRetries          = null,
        bool                       $probeBeforeConnect  = false,
        ?LoggerInterface           $logger              = null,
        ?string                    $runtimeDir          = null,
        /** @internal set only by runWorker(); skips re-spawning a child process. */
        bool                       $isWorkerProcess     = false,
        ?string                    $transport           = null
    ) {
        $this->apiKey             = $apiKey;
        $this->dbConfig           = $dbConfig;
        $this->identity           = $identity;
        $this->autoSync           = $autoSync;
        $this->syncInterval       = $syncInterval;
        $this->reconnectEnabled   = $reconnectEnabled;
        $this->maxRetries         = $maxRetries;
        $this->probeBeforeConnect = $probeBeforeConnect;
        $this->logger             = $logger;
        $this->isWorkerProcess    = $isWorkerProcess;
        $this->transport          = $transport ?? self::getDefaultTransport();

        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'api_key is required. Please provide a valid Feedple API key.'
            );
        }

        $this->runtimeDir = $runtimeDir ?? sys_get_temp_dir();

        $this->log('info', 'Feedple: initializing SDK...');

        // Build our own connection from this config — used by this process,
        // and the same config is handed to the worker process so it builds
        // an equivalent connection independently.
        try {
            $this->db = $dbConfig->toPdo();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Feedple SDK could not connect to the database: " . $e->getMessage(),
                0,
                $e
            );
        }

        // ── Verify DB connectivity using the caller's PDO ───────────────────
        $this->verifyDbConnection();

        // ── In stateless webhook mode, return immediately without spawning worker daemon ──
        if ($this->transport === 'webhook' && !$isWorkerProcess) {
            $this->log('info', 'Feedple: SDK initialized successfully (stateless webhook mode)');
            return;
        }

        if ($this->autoSync) {
            $this->log('info', "Feedple: auto-sync enabled (interval: {$this->syncInterval}s)");
        }

        if ($isWorkerProcess) {
            if (!$this->acquireWorkerLock()) {
                $this->log('warning', 'Feedple: another background worker process is already running; exiting duplicate worker process.');
                fwrite(STDERR, "Feedple worker: duplicate worker instance detected. Exiting.\n");
                exit(0);
            }

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
     * Checks whether a worker process from a previous run is still alive.
     * Uses kernel-level file locking (flock) backed by PID checks.
     */
    private function isWorkerAlreadyRunning(): bool
    {
        $lockFile = $this->lockFilePath();
        if (is_file($lockFile)) {
            $fp = @fopen($lockFile, 'c+');
            if ($fp) {
                $gotLock = flock($fp, LOCK_EX | LOCK_NB);
                if ($gotLock) {
                    // Lock was available -> no active worker holds the lock!
                    flock($fp, LOCK_UN);
                    fclose($fp);
                } else {
                    // Lock is actively held by a running worker process!
                    fclose($fp);
                    return true;
                }
            }
        }

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
            return $output !== null && strpos($output, (string) $pid) !== false;
        }

        // `kill -0` is a POSIX shell built-in that checks whether a process
        // exists without actually signaling it — no posix PHP extension
        // required, so this works the same on Linux and macOS.
        $dummy = [];
        $exitCode = 1;
        exec(sprintf('kill -0 %d 2>/dev/null', $pid), $dummy, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Attempt to acquire an exclusive lock file handle for this worker process.
     * Returns true if lock was acquired, false if another worker is running.
     */
    private function acquireWorkerLock(): bool
    {
        $lockFile = $this->lockFilePath();
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        @ftruncate($fp, 0);
        @rewind($fp);
        @fwrite($fp, (string) getmypid());
        @fflush($fp);

        @file_put_contents($this->pidFilePath(), (string) getmypid());

        self::$workerLockHandle = $fp;
        return true;
    }

    /**
     * Determine if the *client application* (the app embedding this SDK) is
     * running in a development/local environment.
     *
     * NOTE: this is about the client app, NOT about which Feedple backend is
     * targeted. Backend selection is controlled by FEEDPLE_DEV_ENV via
     * {@see isDevEnv()} / {@see getApiBaseUrl()}.
     *
     * Checks in order:
     * 1. APP_ENV (standard Laravel/Symfony environment: local, development, dev, test)
     * 2. ENVIRONMENT or ENV
     * 3. APP_URL pointing to localhost or 127.0.0.1
     * 4. FEEDPLE_DEV_ENV (fallback for plain-PHP scripts with no APP_* vars)
     */
    public static function isDevelopment(): bool
    {
        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? null);
        if (!empty($appEnv)) {
            $lower = strtolower(trim((string) $appEnv));
            if (in_array($lower, ['local', 'development', 'dev', 'testing', 'test'], true)) {
                return true;
            }
            if (in_array($lower, ['production', 'prod', 'staging', 'live'], true)) {
                return false;
            }
        }

        $genericEnv = getenv('ENVIRONMENT') ?: ($_ENV['ENVIRONMENT'] ?? getenv('ENV') ?: ($_ENV['ENV'] ?? null));
        if (!empty($genericEnv)) {
            $lower = strtolower(trim((string) $genericEnv));
            if (in_array($lower, ['local', 'development', 'dev', 'testing', 'test'], true)) {
                return true;
            }
            if (in_array($lower, ['production', 'prod', 'staging', 'live'], true)) {
                return false;
            }
        }

        $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? null);
        if (!empty($appUrl) && (str_contains((string) $appUrl, 'localhost') || str_contains((string) $appUrl, '127.0.0.1'))) {
            return true;
        }

        $override = getenv('FEEDPLE_DEV_ENV') ?: ($_ENV['FEEDPLE_DEV_ENV'] ?? null);
        if ($override !== null && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * Determine if the current application environment is production.
     */
    public static function isProduction(): bool
    {
        return !self::isDevelopment();
    }

    /**
     * Resolve the transport mode.
     *
     * 1. Requested mode = FEEDPLE_TRANSPORT if set, otherwise
     *    'websocket' in development and 'webhook' in production.
     * 2. Reachability guard: webhook requires the Feedple backend to make an
     *    inbound HTTP call to the app. If the app is local (APP_URL is
     *    localhost/127.0.0.1) but the backend is remote (Cloud Run), that call
     *    can never succeed, so the mode is forced to 'websocket' (outbound).
     *
     * Resulting matrix:
     *   local app  + local backend  → webhook or websocket (your choice)
     *   local app  + cloud backend  → websocket (forced)
     *   public app + cloud backend  → webhook (default) or websocket
     */
    public static function getDefaultTransport(): string
    {
        $override  = getenv('FEEDPLE_TRANSPORT') ?: ($_ENV['FEEDPLE_TRANSPORT'] ?? null);
        $requested = !empty($override)
            ? strtolower(trim((string) $override))
            : (self::isDevelopment() ? 'websocket' : 'webhook');

        if ($requested === 'webhook' && !self::isWebhookReachable()) {
            return 'websocket';
        }

        return $requested;
    }

    /**
     * Whether the Feedple backend can reach this app's webhook bridge over HTTP.
     * False only when we positively know the app is local and the backend is remote.
     */
    public static function isWebhookReachable(): bool
    {
        return !(self::isLocalApp() && !self::isLocalBackend());
    }

    /** True when APP_URL points to a loopback / local-only host. */
    public static function isLocalApp(): bool
    {
        $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? null);
        return !empty($appUrl) && self::isLocalUrl((string) $appUrl);
    }

    /** True when the resolved Feedple API base URL is a local server. */
    public static function isLocalBackend(): bool
    {
        return self::isLocalUrl(self::getApiBaseUrl());
    }

    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }
        return $host === 'localhost'
            || $host === '0.0.0.0'
            || $host === '::1'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }

    public static function getDefaultBridgePath(): string
    {
        $override = getenv('FEEDPLE_BRIDGE_PATH') ?: ($_ENV['FEEDPLE_BRIDGE_PATH'] ?? null);
        return !empty($override) ? (string) $override : self::DEFAULT_BRIDGE_PATH;
    }

    public static function isDevEnv(): bool
    {
        $override = getenv('FEEDPLE_DEV_ENV') ?: ($_ENV['FEEDPLE_DEV_ENV'] ?? null);
        if ($override !== null && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }
        if (defined('FEEDPLE_DEV_ENV')) {
            return (bool) constant('FEEDPLE_DEV_ENV');
        }
        return (bool) (defined(__NAMESPACE__ . '\FEEDPLE_DEV_ENV') ? constant(__NAMESPACE__ . '\FEEDPLE_DEV_ENV') : false);
    }

    public static function getWsUrl(): string
    {
        $override = getenv('FEEDPLE_WS_URL') ?: ($_ENV['FEEDPLE_WS_URL'] ?? null);
        if (!empty($override)) {
            return (string) $override;
        }
        if (self::isDevEnv()) {
            return 'ws://localhost:8000/api/v1/tenants/ws';
        }
        return self::DEFAULT_WS_URL;
    }

    public static function getApiBaseUrl(): string
    {
        $override = getenv('FEEDPLE_API_BASE_URL') ?: ($_ENV['FEEDPLE_API_BASE_URL'] ?? null);
        if (!empty($override)) {
            return rtrim((string) $override, '/');
        }
        if (self::isDevEnv()) {
            return 'http://localhost:8000/api/v1';
        }
        return self::DEFAULT_API_BASE_URL;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Build the WebSocket client + event loop. Only ever called inside the
     * worker process (isWorkerProcess = true).
     */
    private function initRuntime(?string $autoloadPath)
    {
        $this->loop = Loop::get();

        $this->ws = new FeedpleWebSocket(
            self::getWsUrl(),
            $this->apiKey,
            $this->loop,
            $this->buildLogger()
        );
        $this->ws->reconnectEnabled   = $this->reconnectEnabled;
        $this->ws->maxRetries         = $this->maxRetries;
        $this->ws->probeBeforeConnect = $this->probeBeforeConnect;
        $this->ws->onIrRequest(function (array $ir): array {
            return $this->executeIr($ir);
        });

        $this->log('info', 'Feedple: connection client configured');
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
            'runtime_dir'          => $this->runtimeDir,
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

        // Redirect standard descriptors to files/null device instead of pipes,
        // so the parent process exit never closes pipes or sends signals to the child.
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptorSpec = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            throw new \RuntimeException('Feedple: could not start background worker process');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

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
    public static function runWorker(string $controlFilePath)
    {
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $config = json_decode((string) file_get_contents($controlFilePath), true, 512, JSON_THROW_ON_ERROR);

        $identity = unserialize(base64_decode($config['identity']));
        $dbConfig = DbConfig::fromArray($config['db_config']);

        $sdk = new self(
            $config['api_key'],
            $dbConfig,
            $identity,
            $config['autoload_path'],
            $config['auto_sync'],
            $config['sync_interval'],
            $config['reconnect_enabled'],
            $config['max_retries'],
            $config['probe_before_connect'],
            null,
            $config['runtime_dir'] ?? null,
            true,
            'websocket'
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
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->log('info', 'Feedple: starting background sync worker...');

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
        $this->log('info', 'Feedple: sync worker started, waiting for connection authentication...');

        // Register callback to run sync immediately upon authentication
        $this->ws->onAuthenticated(function (): void {
            $this->log('info', 'Feedple: session authenticated, triggering initial schema sync...');
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

        // Run garbage collection after sync cycle to reclaim memory
        if (function_exists('gc_collect_cycles')) {
            @gc_collect_cycles();
        }

        $this->log('info', "Feedple: next sync in {$this->syncInterval}s");
    }

    /**
     * Inspect the database schema, hash it, and send to Feedple if changed.
     *
     * Schema sync ALWAYS goes over HTTP (POST /api/v1/tenants/schema), in every
     * scenario. The transport ('webhook' | 'websocket') only governs runtime
     * query delivery; it is reported to the backend on each sync so the
     * backend routes queries correctly.
     *
     * Mirrors: async def sync_schema(self) -> None
     */
    public function syncSchema(?string $webhookUrl = null): void
    {
        $this->ws?->sendInspectingStatus();
        $result = $this->syncSchemaHttp(null, false, $webhookUrl);
        if ($result['skipped']) {
            $this->ws?->sendSchemaUnchanged();
        }
    }

    /**
     * One-shot schema synchronization over HTTPS.
     * Inspects the database, hashes the schema, and sends it to Feedple Cloud API via POST /api/v1/tenants/schema.
     *
     * @param  string|null $apiBaseUrl Custom API base URL (defaults to getApiBaseUrl())
     * @param  bool $force Whether to force sync even if schema appears unchanged locally
     * @param  string|null $webhookUrl Optional bridge webhook URL to register for autonomous queries
     * @return array{status: string, schema_version: int, skipped: bool, tables_count: int, registered_webhook_url: ?string, webhook_secret: ?string}
     *
     * @throws \RuntimeException on HTTP failure or database inspection failure
     */
    public function syncSchemaHttp(?string $apiBaseUrl = null, bool $force = false, ?string $webhookUrl = null): array
    {
        $this->log('info', 'Feedple: inspecting database schema for HTTP sync...');
        $schema = SchemaServices::getSchema($this->getDb(), $this->identity);
        $tablesCount = count($schema);
        $this->log('info', sprintf('Feedple: schema inspected (%d tables found)', $tablesCount));

        $newHash = SchemaServices::generateSchemaHash($schema);
        if (!$force && $this->previousHash !== null && $this->previousHash === $newHash) {
            $this->log('info', 'Feedple: schema unchanged locally, skipping HTTP upload');
            return [
                'status'                 => 'success',
                'schema_version'         => 0,
                'skipped'                => true,
                'tables_count'           => $tablesCount,
                'registered_webhook_url' => null,
                'webhook_secret'         => null,
            ];
        }

        $baseUrl = $apiBaseUrl ?? self::getApiBaseUrl();
        $endpoint = rtrim($baseUrl, '/') . '/tenants/schema';

        // transport_mode tells the backend how to deliver runtime queries.
        // In websocket mode we never register a webhook URL.
        $payloadData = ['schema' => $schema, 'transport_mode' => $this->transport];
        if ($this->transport === 'webhook' && $webhookUrl !== null && $webhookUrl !== '') {
            $payloadData['webhook_url'] = $webhookUrl;
        }
        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: Feedple-PHP-SDK/1.0',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || !empty($curlError)) {
            $msg = "HTTP schema sync failed: " . ($curlError ?: 'unknown network error');
            if (self::isLocalUrl($baseUrl)) {
                $msg .= PHP_EOL . "  → The local Feedple server at {$baseUrl} is not reachable. Start it (e.g. `uvicorn` on port 8000),"
                      . PHP_EOL . "    or remove FEEDPLE_DEV_ENV=true from .env to target the production Feedple Cloud.";
            }
            $this->log('error', "Feedple: {$msg}");
            throw new \RuntimeException($msg);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = "HTTP schema sync returned status {$httpCode}: {$responseBody}";
            $this->log('error', "Feedple: {$msg}");
            throw new \RuntimeException($msg);
        }

        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON response from schema endpoint: {$responseBody}");
        }

        $this->previousHash = $newHash;
        $this->log('info', sprintf(
            'Feedple: schema synced via HTTP (status: %s, version: %d)',
            $decoded['status'] ?? 'success',
            $decoded['schema_version'] ?? 1
        ));

        return [
            'status'                 => (string) ($decoded['status'] ?? 'success'),
            'schema_version'         => (int) ($decoded['schema_version'] ?? 1),
            'skipped'                => (bool) ($decoded['skipped'] ?? false),
            'tables_count'           => (int) ($decoded['tables_count'] ?? $tablesCount),
            'registered_webhook_url' => $decoded['registered_webhook_url'] ?? null,
            'webhook_secret'         => $decoded['webhook_secret'] ?? null,
        ];
    }

    /**
     * Get active database connection, automatically reconnecting if connection was lost.
     */
    private function getDb(): \PDO
    {
        if ($this->db === null) {
            $this->db = $this->dbConfig->toPdo();
            return $this->db;
        }
        try {
            $this->db->query('SELECT 1');
        } catch (\Throwable) {
            $this->log('info', 'Feedple: database connection lost or timed out, reconnecting...');
            $this->db = $this->dbConfig->toPdo();
        }
        return $this->db;
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
            $this->ws?->stop();
            $this->loop?->stop();
            @unlink($this->pidFilePath());
            @unlink($this->lockFilePath());
            if (self::$workerLockHandle !== null && is_resource(self::$workerLockHandle)) {
                @flock(self::$workerLockHandle, LOCK_UN);
                @fclose(self::$workerLockHandle);
                self::$workerLockHandle = null;
            }
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
        @unlink($this->lockFilePath());
        if (self::$workerLockHandle !== null && is_resource(self::$workerLockHandle)) {
            @flock(self::$workerLockHandle, LOCK_UN);
            @fclose(self::$workerLockHandle);
            self::$workerLockHandle = null;
        }
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
    public function executeIr(array $ir): array
    {
        $this->log('info', 'Feedple: validating IR request...');

        $policy = new PolicyEngine($this->identity);
        $policy->validateIrAccess($ir);

        $this->log('info', 'Feedple: executing IR request...');

        $driver = null;
        try {
            $driver = (string) $this->getDb()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            // Driver name couldn't be detected
        }

        ['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir, $driver);

        $this->log('info', "Feedple: executing query against database: {$sql}");
        $start = microtime(true);

        try {
            $stmt = $this->getDb()->prepare($sql);
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
        } catch (\Throwable $e) {
            $this->log('error', "Feedple: database query failed ({$e->getMessage()}) | SQL: {$sql} | params: " . json_encode($params));
            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $this->log('info', sprintf('Feedple: IR executed (%d rows, %dms)', count($rows), $durationMs));

        // If the query returned a single aggregate row (e.g. COUNT(*), COUNT(col)),
        // the actual "count" should be the aggregate value in that row, not the
        // number of PHP rows returned (which would always be 1 for aggregates).
        $rowCount = count($rows);
        if ($rowCount === 1) {
            $onlyRow = reset($rows);
            if (is_array($onlyRow)) {
                $vals = array_values($onlyRow);
                // Single-column result whose value is numeric → it's an aggregate scalar
                if (count($vals) === 1 && is_numeric($vals[0])) {
                    $rowCount = (int) $vals[0];
                }
            }
        }

        return [
            'rows'        => $rows,
            'count'       => $rowCount,
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
            throw new \RuntimeException("Feedple SDK could not connect to the database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Log a message via the injected PSR-3 logger or fall back to a plain
     * file. No stdio dependency, so it survives the process boundary and
     * any request lifecycle cleanly, on any OS.
     */
    public function log(string $level, string $message)
    {
        if ($this->logger !== null) {
            $this->logger->$level($message);
            return;
        }

        $line = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), strtoupper($level), $message, PHP_EOL);

        try {
            $logPath = $this->defaultLogPath();
            // Rotate log if larger than 10MB to prevent memory exhaustion and disk fill
            if (is_file($logPath) && @filesize($logPath) > 10 * 1024 * 1024) {
                @rename($logPath, $logPath . '.1');
            }
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
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

    private function lockFilePath(): string
    {
        return $this->runtimeDir . DIRECTORY_SEPARATOR . 'feedple-sdk.lock';
    }

    private function buildLogger(): LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        return new class($this) extends \Psr\Log\AbstractLogger {
            /** @var FeedpleSDK */
            private $sdk;
            public function __construct(FeedpleSDK $sdk) { $this->sdk = $sdk; }
            #[\ReturnTypeWillChange]
            public function log($level, $message, array $context = []): void {
                $this->sdk->log((string) $level, (string) $message);
            }
        };
    }
}