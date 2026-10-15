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

const FEEDPLE_API_BASE_URL = 'https://feedple-ai-614817435356.us-central1.run.app/api/v1';
const FEEDPLE_WS_URL       = 'wss://feedple-ai-614817435356.us-central1.run.app/api/v1/tenants/ws';

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
    /** @var string|null */
    private $webhookSecret = null;
    /** @var bool */
    private $verifySsl = true;
    /** @var string|null */
    private $caBundlePath = null;

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
     * @param  string|null $webhookSecret Webhook signing secret (optional; auto-fetched/cached).
     * @param  bool|null   $verifySsl    Whether to verify SSL certificates (default: true, set false via FEEDPLE_VERIFY_SSL=false).
     * @param  string|null $caBundlePath Custom CA bundle path (optional; auto-resolves if omitted).
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
        ?string                    $transport           = null,
        ?string                    $webhookSecret       = null,
        bool|string|null           $verifySsl           = null,
        ?string                    $caBundlePath        = null
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
        $this->webhookSecret      = $webhookSecret;

        if ($verifySsl !== null) {
            $filtered = filter_var($verifySsl, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->verifySsl = $filtered !== null ? $filtered : self::isSslVerificationEnabled();
        } else {
            $this->verifySsl = self::isSslVerificationEnabled();
        }

        $this->caBundlePath       = $caBundlePath ?? self::resolveCaBundle();

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
    /**
     * Check if running under a local CLI development server (e.g. `php artisan serve` or PHP built-in server).
     */
    public static function isCliDevServer(): bool
    {
        if (php_sapi_name() === 'cli-server') {
            return true;
        }
        $argv = $_SERVER['argv'] ?? [];
        return is_array($argv) && in_array('serve', $argv, true);
    }

    /**
     * Check if running in a known local development desktop tool (Herd, Valet, Laragon, XAMPP, etc.).
     */
    public static function isLocalDevTool(): bool
    {
        if (!empty($_SERVER['HERD_HOME']) || !empty(getenv('HERD_HOME')) || !empty($_SERVER['VALET_HOME_PATH'])) {
            return true;
        }
        if (!empty($_SERVER['LARAGON_HOME']) || !empty(getenv('LARAGON_HOME'))) {
            return true;
        }
        $cwd = getcwd() ?: '';
        return str_contains($cwd, '/xampp/') || str_contains($cwd, '\\xampp\\')
            || str_contains($cwd, '/wamp/')  || str_contains($cwd, '\\wamp\\');
    }

    /**
     * Check if running inside a production server environment
     * (cPanel shared hosting, Apache, Nginx, PHP-FPM, LiteSpeed, or Linux /var/www, /home/[user]/public_html).
     */
    public static function isServerEnvironment(): bool
    {
        // 1. SAPI check for real web servers
        $sapi = php_sapi_name();
        if (in_array($sapi, ['fpm-fcgi', 'apache2handler', 'litespeed', 'cgi-fcgi'], true)) {
            return true;
        }

        // 2. Cloud platform container flags
        if (!empty($_SERVER['K_SERVICE']) || !empty(getenv('K_SERVICE'))
            || !empty($_SERVER['AWS_EXECUTION_ENV']) || !empty(getenv('AWS_EXECUTION_ENV'))
            || !empty($_SERVER['DYNO']) || !empty(getenv('DYNO'))
            || !empty($_SERVER['RENDER']) || !empty(getenv('RENDER'))
            || !empty($_SERVER['RAILWAY_ENVIRONMENT']) || !empty(getenv('RAILWAY_ENVIRONMENT'))
        ) {
            return true;
        }

        // 3. Shared hosting & server directory structure check
        $pathsToCheck = array_filter([
            getcwd() ?: null,
            function_exists('base_path') ? base_path() : null,
            $_SERVER['DOCUMENT_ROOT'] ?? null,
        ]);
        foreach ($pathsToCheck as $path) {
            if (preg_match('#/(home|home\d+|var|srv)/[^/]+/(public_html|domains|www)#', $path)
                || str_starts_with($path, '/var/www')
                || str_starts_with($path, '/srv/www')
                || str_starts_with($path, '/var/app/current')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if currently running within a PHPUnit or automated testing runner.
     */
    public static function isTesting(): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || (getenv('APP_ENV') === 'testing' || ($_ENV['APP_ENV'] ?? null) === 'testing')
            || (function_exists('app') && method_exists(app(), 'runningUnitTests') && app()->runningUnitTests());
    }

    /**
     * Cache the last known public application URL to a runtime file.
     */
    public static function cacheKnownAppUrl(string $url): void
    {
        if (self::isTesting() || empty($url) || self::isLocalUrl($url)) {
            return;
        }
        $key = substr(md5(getcwd() ?: 'feedple'), 0, 10);
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "feedple-app-url-{$key}.cache";
        @file_put_contents($cacheFile, trim($url));
    }

    /**
     * Retrieve the last known public application URL from cache.
     */
    public static function getCachedAppUrl(): ?string
    {
        if (self::isTesting()) {
            return null;
        }
        $key = substr(md5(getcwd() ?: 'feedple'), 0, 10);
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "feedple-app-url-{$key}.cache";
        if (@file_exists($cacheFile) && @is_readable($cacheFile)) {
            $cached = trim((string) @file_get_contents($cacheFile));
            if (!empty($cached) && filter_var($cached, FILTER_VALIDATE_URL) && !self::isLocalUrl($cached)) {
                return $cached;
            }
        }
        return null;
    }

    /**
     * Attempt to detect the application's public URL from server configuration,
     * virtual host settings, or filesystem domain paths.
     */
    public static function detectServerAppUrl(): ?string
    {
        // 1. Check $_SERVER['SERVER_NAME'] or $_SERVER['HTTP_HOST']
        $serverName = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? null);
        if (!empty($serverName)) {
            $cleanName = trim((string) $serverName);
            if (str_contains($cleanName, ':')) {
                $cleanName = explode(':', $cleanName)[0];
            }
            if (!self::isLocalHost($cleanName) && str_contains($cleanName, '.') && preg_match('/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $cleanName)) {
                $scheme = self::detectHttpScheme();
                return "{$scheme}://{$cleanName}";
            }
        }

        // 2. Check hostname if it is a FQDN
        $hostname = @gethostname();
        if (!empty($hostname) && !self::isLocalHost($hostname) && str_contains($hostname, '.')) {
            if (preg_match('/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $hostname)) {
                return "https://{$hostname}";
            }
        }

        // 3. Check directory path on cPanel or Apache/Nginx (e.g. /home/user/mysite.com/public_html)
        $pathsToCheck = array_filter([
            getcwd() ?: null,
            function_exists('base_path') ? base_path() : null,
            $_SERVER['DOCUMENT_ROOT'] ?? null,
        ]);
        foreach ($pathsToCheck as $path) {
            $segments = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
            foreach ($segments as $segment) {
                if (preg_match('/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $segment) && !self::isLocalHost($segment)) {
                    return "https://{$segment}";
                }
            }
        }

        return null;
    }

    public static function isDevelopment(): bool
    {
        // 1. Live web requests with a real public HTTP host are ALWAYS production!
        // A request arriving at https://mysite.com is NEVER local development,
        // regardless of any unconfigured or stale APP_ENV in .env.
        $host = self::detectHttpHost();
        if ($host !== null && !self::isLocalHost($host)) {
            return false;
        }

        // 2. Active CLI dev server (`php artisan serve` or `php -S`) is always local development
        if (self::isCliDevServer()) {
            return true;
        }

        // 3. Explicit dev env override (for local developers testing against local backend)
        $override = getenv('FEEDPLE_DEV_ENV') ?: ($_ENV['FEEDPLE_DEV_ENV'] ?? ($_SERVER['FEEDPLE_DEV_ENV'] ?? null));
        if ($override !== null && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }
        if (defined('FEEDPLE_DEV_ENV') && constant('FEEDPLE_DEV_ENV') === true) {
            return true;
        }

        // 4. Known local development desktop tools (Herd, Valet, Laragon, XAMPP)
        if (self::isLocalDevTool()) {
            return true;
        }

        // 5. If we have a cached public host from live traffic, we are on a production server
        if (self::getCachedAppUrl() !== null) {
            return false;
        }

        // 6. Explicit Framework or Environment setting
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app && method_exists($app, 'environment')) {
                    if ($app->environment('production', 'prod', 'staging', 'live')) {
                        return false;
                    }
                    if ($app->environment('local', 'development', 'dev', 'testing', 'test')) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // Ignore if container not booted
            }
        }

        if (function_exists('config')) {
            try {
                $cfgEnv = config('app.env');
                if (!empty($cfgEnv)) {
                    $lower = strtolower(trim((string) $cfgEnv));
                    if (in_array($lower, ['production', 'prod', 'staging', 'live'], true)) {
                        return false;
                    }
                    if (in_array($lower, ['local', 'development', 'dev', 'testing', 'test'], true)) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ($_SERVER['APP_ENV'] ?? null));
        if (!empty($appEnv)) {
            $lower = strtolower(trim((string) $appEnv));
            if (in_array($lower, ['production', 'prod', 'staging', 'live'], true)) {
                return false;
            }
            if (in_array($lower, ['local', 'development', 'dev', 'testing', 'test'], true)) {
                return true;
            }
        }

        $genericEnv = getenv('ENVIRONMENT') ?: ($_ENV['ENVIRONMENT'] ?? getenv('ENV') ?: ($_ENV['ENV'] ?? null));
        if (!empty($genericEnv)) {
            $lower = strtolower(trim((string) $genericEnv));
            if (in_array($lower, ['production', 'prod', 'staging', 'live'], true)) {
                return false;
            }
            if (in_array($lower, ['local', 'development', 'dev', 'testing', 'test'], true)) {
                return true;
            }
        }

        // 7. Check if running on a live server environment (cPanel, Apache, FPM, LiteSpeed, /var/www, /home/*/public_html)
        if (self::isServerEnvironment()) {
            return false;
        }

        // 8. If APP_URL is explicitly set to a public URL, it is production
        $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? null));
        if (!empty($appUrl) && !self::isLocalUrl((string) $appUrl)) {
            return false;
        }

        // 9. If APP_URL is explicitly set to localhost/127.0.0.1, it is local development
        if (!empty($appUrl) && self::isLocalUrl((string) $appUrl)) {
            return true;
        }

        // 10. Default to production when no local development signals are present
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
        $override  = getenv('FEEDPLE_TRANSPORT') ?: ($_ENV['FEEDPLE_TRANSPORT'] ?? ($_SERVER['FEEDPLE_TRANSPORT'] ?? null));
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
        // 1. If a real non-local HTTP host is serving the request, it is NOT local
        $host = self::detectHttpHost();
        if ($host !== null && !self::isLocalHost($host)) {
            return false;
        }

        // 2. If a cached public URL exists from live traffic, it is NOT local
        if (self::getCachedAppUrl() !== null) {
            return false;
        }

        // 3. Active CLI dev server (`php artisan serve`) is definitely local
        if (self::isCliDevServer()) {
            return true;
        }

        // 4. Detected app URL
        $appUrl = self::detectAppUrl();
        if (!empty($appUrl) && !self::isLocalUrl($appUrl)) {
            return false;
        }

        // 5. If running in a server environment (cPanel / Apache / FPM) and NOT artisan serve,
        // it is not a local app even if APP_URL was left as localhost in boilerplate .env
        if (self::isServerEnvironment() && !self::isCliDevServer()) {
            return false;
        }

        $rawAppUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? null));
        return !empty($rawAppUrl) && self::isLocalUrl((string) $rawAppUrl);
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
        return self::isLocalHost($host);
    }

    public static function isLocalHost(string $host): bool
    {
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }
        $host = strtolower(trim($host, '[]'));
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

    /**
     * Automatically detect the incoming or configured HTTP host.
     */
    public static function detectHttpHost(): ?string
    {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
            ?? $_SERVER['SERVER_NAME']
            ?? null;

        if (empty($host)) {
            return null;
        }

        if (str_contains((string) $host, ',')) {
            $parts = explode(',', (string) $host);
            $host = trim($parts[0]);
        }

        return trim((string) $host);
    }

    /**
     * Automatically detect the HTTP scheme ('https' or 'http').
     */
    public static function detectHttpScheme(): string
    {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https' || $proto === 'http') {
            return $proto;
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return 'https';
        }

        return self::isProduction() ? 'https' : 'http';
    }

    /**
     * Automatically detect the application base URL (e.g. 'https://example.com').
     *
     * @param string|null $explicitUrl Optional explicit application URL passed directly from SDK code/config.
     */
    public static function detectAppUrl(?string $explicitUrl = null): ?string
    {
        $url = null;

        if (!empty($explicitUrl) && filter_var($explicitUrl, FILTER_VALIDATE_URL)) {
            $url = (string) $explicitUrl;
        } else {
            // 1. Web server / reverse proxy host header (automatic on any web request)
            $host = self::detectHttpHost();
            if ($host !== null && $host !== '') {
                $scheme = self::detectHttpScheme();
                $url = "{$scheme}://{$host}";
                if (!self::isLocalHost($host)) {
                    self::cacheKnownAppUrl($url);
                }
            }

            // 2. Cached public URL from prior web requests
            if ($url === null) {
                $cached = self::getCachedAppUrl();
                if (!empty($cached)) {
                    $url = $cached;
                }
            }

            // 3. Explicit env var (standard Laravel/framework APP_URL)
            if ($url === null) {
                $envUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? null));
                if (!empty($envUrl) && filter_var($envUrl, FILTER_VALIDATE_URL)) {
                    // Only use local APP_URL if we are truly in a local development environment
                    if (!self::isLocalUrl((string) $envUrl) || !self::isServerEnvironment()) {
                        $url = (string) $envUrl;
                    }
                }
            }

            // 4. Framework config (Laravel / Symfony)
            if ($url === null && function_exists('config')) {
                try {
                    $configUrl = config('app.url');
                    if (!empty($configUrl) && filter_var($configUrl, FILTER_VALIDATE_URL)) {
                        if (!self::isLocalUrl((string) $configUrl) || !self::isServerEnvironment()) {
                            $url = (string) $configUrl;
                        }
                    }
                } catch (\Throwable) {
                    // Ignore if not a framework environment
                }
            }

            // 5. Common cloud platform environment variables
            if ($url === null) {
                $cloudVars = [
                    'RENDER_EXTERNAL_URL',
                    'RAILWAY_PUBLIC_DOMAIN',
                    'VERCEL_URL',
                    'FLY_APP_NAME',
                    'HEROKU_APP_DEFAULT_DOMAIN_NAME',
                ];
                foreach ($cloudVars as $var) {
                    $val = getenv($var) ?: ($_ENV[$var] ?? ($_SERVER[$var] ?? null));
                    if (!empty($val)) {
                        $val = (string) $val;
                        if (!str_starts_with($val, 'http://') && !str_starts_with($val, 'https://')) {
                            $val = 'https://' . $val;
                        }
                        if (filter_var($val, FILTER_VALIDATE_URL)) {
                            $url = $val;
                            break;
                        }
                    }
                }
            }

            // 6. Server environment auto-detection (e.g. cPanel /home/user/domain.com, $_SERVER['SERVER_NAME'])
            if ($url === null) {
                $serverUrl = self::detectServerAppUrl();
                if (!empty($serverUrl)) {
                    $url = $serverUrl;
                }
            }

            // 7. Fallback to local envUrl if available
            if ($url === null) {
                $rawEnvUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? ($_SERVER['APP_URL'] ?? null));
                if (!empty($rawEnvUrl) && filter_var($rawEnvUrl, FILTER_VALIDATE_URL)) {
                    $url = (string) $rawEnvUrl;
                }
            }
        }

        if (empty($url)) {
            return null;
        }

        $url = rtrim($url, '/');

        // In production, ensure public URLs use HTTPS for secure webhook delivery
        if (self::isProduction() && str_starts_with($url, 'http://') && !self::isLocalUrl($url)) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }

    /**
     * Automatically detect the full webhook bridge URL (e.g. 'https://example.com/api/feedple/bridge').
     *
     * @param string|null $bridgePath Optional custom bridge path (defaults to '/api/feedple/bridge').
     * @param string|null $appUrl     Optional base app URL passed directly from SDK code/config.
     */
    public static function detectWebhookUrl(?string $bridgePath = null, ?string $appUrl = null): ?string
    {
        $baseUrl = self::detectAppUrl($appUrl);
        if (empty($baseUrl)) {
            return null;
        }

        $path = $bridgePath ?? self::getDefaultBridgePath();
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Check if a path is a readable, non-empty CA certificate file without triggering
     * open_basedir warnings or throwing on restricted systems.
     */
    public static function isValidCaFile(?string $path): bool
    {
        if (empty($path)) {
            return false;
        }
        try {
            return @file_exists($path) && @is_readable($path) && (@filesize($path) ?: 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolves the best available Certificate Authority (CA) root bundle path.
     * Checks custom bundle, php.ini settings, Composer CaBundle, bundled resources/cacert.pem,
     * and common OS certificate stores.
     */
    public static function resolveCaBundle(?string $customPath = null): ?string
    {
        // 1. Explicit path parameter or FEEDPLE_CA_BUNDLE environment variable
        $configuredPath = $customPath
            ?? getenv('FEEDPLE_CA_BUNDLE')
            ?: ($_ENV['FEEDPLE_CA_BUNDLE'] ?? null);

        if (self::isValidCaFile($configuredPath)) {
            return (string) $configuredPath;
        }

        // 2. Check if php.ini already specifies a valid curl.cainfo
        $curlCainfo = ini_get('curl.cainfo');
        if (self::isValidCaFile($curlCainfo)) {
            return $curlCainfo;
        }

        // 3. Check if php.ini already specifies a valid openssl.cafile
        $opensslCafile = ini_get('openssl.cafile');
        if (self::isValidCaFile($opensslCafile)) {
            return $opensslCafile;
        }

        // 4. Check if Composer\CaBundle\CaBundle is installed (very common in Composer/Laravel projects)
        if (class_exists('Composer\CaBundle\CaBundle')) {
            try {
                $composerBundle = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
                if (self::isValidCaFile($composerBundle)) {
                    return $composerBundle;
                }
            } catch (\Throwable) {
                // Ignore and fall through
            }
        }

        // 5. Check bundled resources/cacert.pem in our own package
        $bundledPem = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (self::isValidCaFile($bundledPem)) {
            return $bundledPem;
        }

        // 6. Common OS certificate bundles on Linux/BSD/macOS
        $commonPaths = [
            '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Alpine
            '/etc/pki/tls/certs/ca-bundle.crt', // RHEL, CentOS, Fedora
            '/etc/ssl/ca-bundle.pem', // OpenSUSE
            '/etc/pki/tls/cacert.pem', // OpenELEC
            '/etc/ssl/cert.pem', // macOS, FreeBSD, OpenBSD
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
        ];

        foreach ($commonPaths as $path) {
            if (self::isValidCaFile($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Determine whether SSL peer verification is enabled.
     * Returns false if FEEDPLE_VERIFY_SSL is set to 'false' or '0'.
     */
    public static function isSslVerificationEnabled(): bool
    {
        $val = getenv('FEEDPLE_VERIFY_SSL');
        if ($val === false || $val === null || $val === '') {
            $val = $_ENV['FEEDPLE_VERIFY_SSL'] ?? null;
        }
        if ($val !== null) {
            $filtered = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }
        }
        return true;
    }

    /**
     * Returns whether SSL certificate verification is enabled for this instance.
     */
    public function isSslVerified(): bool
    {
        return $this->verifySsl;
    }

    /**
     * Returns the CA bundle file path used for SSL certificate verification, if resolved.
     */
    public function getCaBundlePath(): ?string
    {
        return $this->caBundlePath;
    }

    /**
     * Get the directory where runtime cache and lock files live.
     */
    public function getRuntimeDir(): string
    {
        return $this->runtimeDir;
    }

    /**
     * Get the webhook signing secret, auto-fetching and caching it if not yet configured.
     */
    public function getWebhookSecret(): ?string
    {
        if ($this->webhookSecret !== null && $this->webhookSecret !== '') {
            return $this->webhookSecret;
        }

        $envSecret = getenv('FEEDPLE_WEBHOOK_SECRET') ?: ($_ENV['FEEDPLE_WEBHOOK_SECRET'] ?? null);
        if (!empty($envSecret)) {
            $this->webhookSecret = trim((string) $envSecret);
            return $this->webhookSecret;
        }

        $cacheFile = $this->webhookSecretCachePath();
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if (!empty($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && !empty($decoded['webhook_secret'])) {
                    $this->webhookSecret = (string) $decoded['webhook_secret'];
                    return $this->webhookSecret;
                }
            }
        }

        // Auto-fetch from Feedple Cloud using the API key
        try {
            return $this->fetchWebhookSecret();
        } catch (\Throwable $e) {
            $this->log('debug', 'Feedple: auto-fetch webhook secret failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cache the webhook signing secret to disk and in-memory.
     */
    public function saveWebhookSecret(string $secret): void
    {
        $this->webhookSecret = trim($secret);
        $cacheFile = $this->webhookSecretCachePath();
        @file_put_contents($cacheFile, json_encode([
            'webhook_secret' => $this->webhookSecret,
            'updated_at'     => date('c'),
        ], JSON_PRETTY_PRINT));
        @chmod($cacheFile, 0600);
    }

    /**
     * Fetch the webhook signing secret from Feedple Cloud using the API key.
     */
    public function fetchWebhookSecret(?string $apiBaseUrl = null): ?string
    {
        $baseUrl = $apiBaseUrl ?? self::getApiBaseUrl();
        $endpoint = rtrim($baseUrl, '/') . '/tenants/webhook/secret';

        $ch = curl_init($endpoint);
        $curlOptions = [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: Feedple-PHP-SDK/1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];

        if ($this->verifySsl && !empty($this->caBundlePath)) {
            $curlOptions[CURLOPT_CAINFO] = $this->caBundlePath;
        }

        curl_setopt_array($ch, $curlOptions);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($code >= 200 && $code < 300 && !empty($body)) {
            $data = json_decode((string) $body, true);
            if (is_array($data) && !empty($data['webhook_secret'])) {
                $this->saveWebhookSecret((string) $data['webhook_secret']);
                return $this->webhookSecret;
            }
        } elseif (!empty($curlError)) {
            $this->log('warning', "Feedple: failed to fetch webhook secret: {$curlError}");
        }

        return null;
    }

    private function webhookSecretCachePath(): string
    {
        return $this->runtimeDir . DIRECTORY_SEPARATOR . 'feedple-webhook-secret.json';
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
            $this->buildLogger(),
            $this->verifySsl,
            $this->caBundlePath
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
            'verify_ssl'           => $this->verifySsl,
            'ca_bundle_path'       => $this->caBundlePath,
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
            'websocket',
            null,
            $config['verify_ssl'] ?? null,
            $config['ca_bundle_path'] ?? null
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
        $payloadData = ['schema' => $schema, 'transport_mode' => $this->transport];
        $resolvedWebhookUrl = $webhookUrl;
        if (!empty($resolvedWebhookUrl)) {
            $payloadData['webhook_url'] = $resolvedWebhookUrl;
            $payloadData['transport_mode'] = 'webhook';
        } elseif ($this->transport === 'webhook') {
            $resolvedWebhookUrl = self::detectWebhookUrl();
            if (!empty($resolvedWebhookUrl)) {
                $payloadData['webhook_url'] = $resolvedWebhookUrl;
            }
        }
        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: Feedple-PHP-SDK/1.0',
        ];

        $ch = curl_init($endpoint);
        $curlOptions = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];

        if ($this->verifySsl && !empty($this->caBundlePath)) {
            $curlOptions[CURLOPT_CAINFO] = $this->caBundlePath;
        }

        curl_setopt_array($ch, $curlOptions);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        @curl_close($ch);

        if ($responseBody === false || !empty($curlError)) {
            $msg = "HTTP schema sync failed: " . ($curlError ?: 'unknown network error');
            if (self::isLocalUrl($baseUrl)) {
                $msg .= PHP_EOL . "  → The local Feedple server at {$baseUrl} is not reachable. Start it (e.g. `uvicorn` on port 8000),"
                      . PHP_EOL . "    or remove FEEDPLE_DEV_ENV=true from .env to target the production Feedple Cloud.";
            } elseif ($curlErrno === 60 || $curlErrno === 77 || stripos($curlError, 'certificate') !== false || stripos($curlError, 'local issuer certificate') !== false) {
                $msg .= PHP_EOL . "  → SSL Certificate Verification Issue:"
                      . PHP_EOL . "    Your PHP environment (especially common on Windows Apache / XAMPP) cannot verify the SSL certificate."
                      . PHP_EOL . "    Fix options:"
                      . PHP_EOL . "    1. Set `FEEDPLE_VERIFY_SSL=false` in your .env for local testing."
                      . PHP_EOL . "    2. Set `curl.cainfo = \"C:\\path\\to\\cacert.pem\"` in your php.ini."
                      . PHP_EOL . "    3. Specify `FEEDPLE_CA_BUNDLE=\"C:\\path\\to\\cacert.pem\"` in your .env.";
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
        if (!empty($decoded['webhook_secret'])) {
            $this->saveWebhookSecret((string) $decoded['webhook_secret']);
        }
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

        if ($pid === null || $pid <= 0) {
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