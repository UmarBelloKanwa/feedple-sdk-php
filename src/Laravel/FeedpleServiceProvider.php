<?php

namespace Feedple\Sdk\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\WebhookBridge;

class FeedpleServiceProvider extends ServiceProvider
{
    /**
     * Cached Feedple SDK instance.
     */
    protected static ?FeedpleSDK $cachedSdk = null;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $configFile = __DIR__ . '/../../config/feedple.php';
        if (file_exists($configFile)) {
            $this->mergeConfigFrom($configFile, 'feedple');
        }

        $this->app->singleton(FeedpleSDK::class, function () {
            return static::makeSdk();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. Publish configuration file
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/feedple.php' => function_exists('config_path')
                    ? config_path('feedple.php')
                    : (base_path('config/feedple.php')),
            ], 'feedple-config');
        }

        // 2. Register Webhook Bridge endpoints
        $bridgePath = config('feedple.bridge_path')
            ?? env('FEEDPLE_BRIDGE_PATH', FeedpleSDK::DEFAULT_BRIDGE_PATH);

        $postRoute = Route::post($bridgePath, function (Request $request) {
            $t0 = microtime(true);
            $sdk = static::makeSdk('webhook');
            $t1 = microtime(true);

            if (!$sdk) {
                return response()->json([
                    'error' => 'Feedple SDK is not configured. Missing FEEDPLE_API_KEY in .env',
                ], 500);
            }

            $secret = config('feedple.webhook_secret')
                ?? env('FEEDPLE_WEBHOOK_SECRET')
                ?: ($_ENV['FEEDPLE_WEBHOOK_SECRET'] ?? getenv('FEEDPLE_WEBHOOK_SECRET') ?: null);
            $bridge = new WebhookBridge($sdk, $secret);

            $rawBody = (string) $request->getContent();
            $headers = $request->headers->all();

            $result = $bridge->handle($rawBody, $headers);
            $t2 = microtime(true);
            error_log(sprintf("FEEDPLE_BRIDGE_TIMING: makeSdk=%.3fs handle=%.3fs total=%.3fs", $t1 - $t0, $t2 - $t1, $t2 - $t0));

            return response()->json($result['body'], $result['status_code']);
        });

        // Exempt CSRF verification on the bridge route across Laravel versions
        $csrfMiddlewares = array_values(array_filter([
            '\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
            'App\Http\Middleware\VerifyCsrfToken',
        ], 'class_exists'));
        if (!empty($csrfMiddlewares)) {
            $postRoute->withoutMiddleware($csrfMiddlewares);
        }

        // Health check endpoint for testing bridge reachability
        Route::get($bridgePath, function () {
            $apiKey = config('feedple.api_key') ?? env('FEEDPLE_API_KEY');
            $configured = !empty($apiKey) && $apiKey !== 'sk_live_your_actual_api_key' && $apiKey !== 'sk_live_your_workspace_api_key_here';
            $secret = config('feedple.webhook_secret') ?? env('FEEDPLE_WEBHOOK_SECRET');
            $secretSet = !empty($secret) && $secret !== 'whsec_your_webhook_signing_secret_here';

            return response()->json([
                'status'     => 'active',
                'service'    => 'Feedple Webhook Bridge',
                'transport'  => config('feedple.transport') ?? FeedpleSDK::getDefaultTransport(),
                'configured' => $configured,
                'secret_set' => $secretSet,
            ]);
        });

        // 3. Register CLI artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\SyncCommand::class,
                Commands\StartCommand::class,
                Commands\StopCommand::class,
                Commands\StatusCommand::class,
            ]);
        }

        // 4. Auto-boot SDK when running `php artisan serve` or on local web requests
        if ($this->shouldAutoBoot()) {
            try {
                static::makeSdk();
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->warning('Feedple SDK auto-boot notice: ' . $e->getMessage());
                }
            }
        }

        // 5. In production webhook mode, auto-register webhook bridge URL with Feedple Cloud
        if (!$this->app->runningInConsole() && method_exists($this->app, 'terminating')) {
            $this->app->terminating(function () {
                $this->autoRegisterWebhookIfProduction();
            });
        }
    }

    /**
     * Factory method to build and initialize the Feedple SDK instance from Laravel config.
     *
     * @param string|null $transport Transport mode ('webhook' or 'websocket').
     */
    public static function makeSdk(?string $transport = null): ?FeedpleSDK
    {
        if (static::$cachedSdk !== null) {
            return static::$cachedSdk;
        }

        $apiKey = config('feedple.api_key')
            ?? env('FEEDPLE_API_KEY')
            ?: ($_ENV['FEEDPLE_API_KEY'] ?? getenv('FEEDPLE_API_KEY') ?: null);

        if (!$apiKey || $apiKey === 'sk_live_your_actual_api_key' || $apiKey === 'sk_live_your_workspace_api_key_here') {
            return null;
        }

        $defaultConn = config('database.default', env('DB_CONNECTION', 'sqlite'));
        $connConfig = config("database.connections.{$defaultConn}", []);
        $dbDriver = $connConfig['driver'] ?? $defaultConn;

        if ($dbDriver === 'mysql' || $dbDriver === 'mariadb') {
            $dbConfig = DbConfig::mysql(
                host: $connConfig['host'] ?? env('DB_HOST', '127.0.0.1'),
                database: $connConfig['database'] ?? env('DB_DATABASE', 'laravel'),
                username: $connConfig['username'] ?? env('DB_USERNAME', 'root'),
                password: $connConfig['password'] ?? env('DB_PASSWORD', ''),
                port: (int) ($connConfig['port'] ?? env('DB_PORT', 3306))
            );
        } elseif ($dbDriver === 'pgsql' || $dbDriver === 'postgres') {
            $sslmode = $connConfig['sslmode'] ?? env('DB_SSLMODE', 'prefer');
            if (empty($sslmode) || $sslmode === 'prefer') {
                if (str_contains($connConfig['host'] ?? '', 'supabase.co') || str_contains(env('DB_HOST', ''), 'supabase.co')) {
                    $sslmode = 'require';
                }
            }
            $options = [\PDO::ATTR_PERSISTENT => true];
            if (!empty($sslmode)) {
                $options['sslmode'] = $sslmode;
            }
            $dbConfig = DbConfig::pgsql(
                host: $connConfig['host'] ?? env('DB_HOST', '127.0.0.1'),
                database: $connConfig['database'] ?? env('DB_DATABASE', 'postgres'),
                username: $connConfig['username'] ?? env('DB_USERNAME', 'postgres'),
                password: $connConfig['password'] ?? env('DB_PASSWORD', ''),
                port: (int) ($connConfig['port'] ?? env('DB_PORT', 5432)),
                options: $options
            );
        } else {
            $dbPath = $connConfig['database'] ?? env('DB_DATABASE', function_exists('database_path') ? database_path('database.sqlite') : 'database.sqlite');
            if (!str_starts_with((string) $dbPath, '/') && function_exists('base_path')) {
                $dbPath = base_path((string) $dbPath);
            }
            $dbConfig = DbConfig::sqlite(path: (string) $dbPath);
        }

        $allowedTables = config('feedple.allowed_tables');
        if (is_array($allowedTables) && !empty($allowedTables)) {
            $identity = new Identity(
                name: 'laravel-app',
                allowedTables: $allowedTables
            );
        } else {
            $identity = new Identity(
                name: 'laravel-app',
                allTables: true
            );
        }

        $selectedTransport = $transport
            ?? config('feedple.transport')
            ?? FeedpleSDK::getDefaultTransport();

        return static::$cachedSdk = new FeedpleSDK(
            apiKey: $apiKey,
            dbConfig: $dbConfig,
            identity: $identity,
            transport: $selectedTransport
        );
    }

    /**
     * Determine whether the SDK should auto-start on application boot.
     */
    protected function shouldAutoBoot(): bool
    {
        if ($this->app->runningUnitTests() || $this->app->environment('testing')) {
            return false;
        }

        $apiKey = config('feedple.api_key') ?? env('FEEDPLE_API_KEY');
        if (empty($apiKey) || $apiKey === 'sk_live_your_actual_api_key' || $apiKey === 'sk_live_your_workspace_api_key_here') {
            return false;
        }

        // When running in console, auto-boot ONLY for `php artisan serve`
        if ($this->app->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];
            return in_array('serve', $argv, true);
        }

        // For regular web requests (FPM, Apache, Nginx, Valet, Herd):
        // Auto-boot in development so WebSocket worker is active without extra commands
        return FeedpleSDK::isDevelopment();
    }

    /**
     * In production webhook mode, automatically register the webhook bridge URL
     * with Feedple Cloud (cached for 24h) so Feedple Cloud knows where to probe
     * and query without requiring manual sync commands.
     */
    protected function autoRegisterWebhookIfProduction(): void
    {
        if ($this->app->runningUnitTests() || $this->app->environment('testing')) {
            return;
        }

        $transport = config('feedple.transport') ?? FeedpleSDK::getDefaultTransport();
        if ($transport !== 'webhook') {
            return;
        }

        $apiKey = config('feedple.api_key') ?? env('FEEDPLE_API_KEY');
        if (empty($apiKey) || $apiKey === 'sk_live_your_actual_api_key' || $apiKey === 'sk_live_your_workspace_api_key_here') {
            return;
        }

        $appUrl = config('app.url') ?? env('APP_URL');
        if (empty($appUrl) || FeedpleSDK::isLocalUrl((string) $appUrl) || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
            return;
        }

        $cacheFile = sys_get_temp_dir() . '/feedple_webhook_registered_' . substr(md5((string) $apiKey), 0, 10) . '.lock';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            // Already registered within the last 24 hours
            return;
        }

        try {
            $sdk = static::makeSdk('webhook');
            if ($sdk) {
                $bridgePath = config('feedple.bridge_path') ?? env('FEEDPLE_BRIDGE_PATH', FeedpleSDK::DEFAULT_BRIDGE_PATH);
                $bridgeUrl = rtrim((string) $appUrl, '/') . '/' . ltrim((string) $bridgePath, '/');
                $sdk->syncSchemaHttp(null, false, $bridgeUrl);
                @touch($cacheFile);
            }
        } catch (\Throwable $e) {
            // Never disrupt user application lifecycle
            if (function_exists('logger')) {
                logger()->debug('Feedple production auto-registration notice: ' . $e->getMessage());
            }
        }
    }
}
