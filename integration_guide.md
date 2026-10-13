# Feedple PHP SDK Integration Guide

This guide documents the architecture, lifecycle management, and integration patterns available for connecting PHP and Laravel applications to **Feedple AI**.

---

## ⚙️ Architectural Overview: Dual-Transport Model

The Feedple PHP SDK supports two distinct transport models tailored for different hosting environments:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                               FEEDPLE CLOUD PLATFORM                             │
└─────────────────────────────────┬────────────────────────────────────────────────┘
                                  │
         ┌────────────────────────┴────────────────────────┐
         │                                                 │
    [Method 1: Stateless Webhook]              [Method 2: Persistent WebSocket]
    Recommended for Web, Serverless,           Recommended for Dedicated VPS,
    PHP-FPM, cPanel, & Laravel                 Long-Running Background Daemons
         │                                                 │
         ▼                                                 ▼
┌─────────────────────────────────┐               ┌─────────────────────────────────┐
│     Your App HTTP Bridge        │               │   CLI Worker Daemon (ReactPHP)  │
│    (POST /api/feedple/bridge)   │               │   (php vendor/bin/feedple-worker)│
│                                 │               │                                 │
│  - Zero background daemons      │               │  - Long-lived outbound socket   │
│  - Cryptographic HMAC-SHA256    │               │  - Continuous heartbeat pings   │
│  - Request/response lifecycle   │               │  - Auto-reconnecting loop       │
│  - 15-30ms execution time       │               │  - Requires CLI process manager │
└────────────────┬────────────────┘               └────────────────┬────────────────┘
                 │                                                 │
                 └────────────────────────┬────────────────────────┘
                                          ▼
                               ┌─────────────────────┐
                               │    Your Database    │
                               │ (PostgreSQL, MySQL, │
                               │   SQLite via PDO)   │
                               └─────────────────────┘
```

### 1. Stateless HTTP Webhook (`FEEDPLE_TRANSPORT=webhook`) — **Recommended**
- **Hosting Targets**: Shared hosting (Namecheap, cPanel, CloudLinux LVE, CageFS), PHP-FPM, Apache, Nginx, Serverless (AWS Lambda / Bref), and standard web frameworks (Laravel, Symfony, Slim).
- **Zero background daemons, zero persistent workers, zero ReactPHP loops.**
- Queries are dispatched as cryptographically signed HTTPS POST requests from Feedple Cloud to your webhook endpoint (`/api/feedple/bridge`).
- The SDK verifies the HMAC signature, validates table RBAC, translates the IR AST to parameterized SQL, executes against your database, and returns JSON in ~15-30ms.
- Schema synchronization is performed on-demand or via one-shot CLI command (`php artisan feedple:sync`).

### 2. Persistent WebSocket Daemon (`FEEDPLE_TRANSPORT=websocket`)
- **Hosting Targets**: Dedicated VPS, cloud VMs (EC2, Compute Engine), Docker containers with Supervisor or systemd.
- Connects outbound to `wss://...` using a ReactPHP event loop.
- Automatically handles reconnection and real-time schema change detection.

---

## 🔑 Authentication & Environment Variables

Feedple uses two separate credentials to secure dual-direction communication:

```ini
# 1. OUTBOUND: Authenticates your application when calling Feedple Cloud APIs (e.g. schema sync)
FEEDPLE_API_KEY=fdpl_live_your_actual_workspace_api_key

# 2. INBOUND: Shared secret used by WebhookBridge to cryptographically verify incoming webhook queries
FEEDPLE_WEBHOOK_SECRET=whsec_your_webhook_signing_secret

# Transport configuration
FEEDPLE_TRANSPORT=webhook
FEEDPLE_BRIDGE_PATH=/api/feedple/bridge
FEEDPLE_API_BASE_URL=https://feedple-ai-614817435356.us-central1.run.app/api/v1
```

| Variable | Direction | Purpose | Format |
| :--- | :--- | :--- | :--- |
| `FEEDPLE_API_KEY` | Outbound | Sent in `Authorization: Bearer <key>` when pushing schemas or making API calls to Feedple Cloud. | `fdpl_live_...` or `sk_live_...` |
| `FEEDPLE_WEBHOOK_SECRET` | Inbound | Used by `WebhookBridge::verifySignature()` to validate incoming `X-Feedple-Signature` HMAC headers. | `whsec_...` |
| `FEEDPLE_TRANSPORT` | Internal | Transport strategy: `'webhook'` (default in production) or `'websocket'` (default in dev). | `'webhook'` \| `'websocket'` |
| `FEEDPLE_BRIDGE_PATH` | Routing | Relative path where your application mounts the webhook bridge handler. | `/api/feedple/bridge` |
| `FEEDPLE_DEV_ENV` | Internal | When `true`, targets local backend (`http://localhost:8000`). Default `false` (targets Cloud Run). | `true` \| `false` |

> [!TIP]
> **Environment-Aware Transport & Reachability Guard (v1.0.18):**
> When `FEEDPLE_TRANSPORT` is omitted, the SDK automatically selects `'websocket'` in local development (`isDevelopment()`) and `'webhook'` in production (`isProduction()`). If an application runs on `localhost` (e.g. `APP_URL=http://localhost:8000`) while connecting to Feedple Cloud, the SDK automatically forces `'websocket'` because Cloud Run cannot send inbound webhooks to `localhost`.

---

## 🚀 Integration Patterns

### Pattern 1: Laravel Integration (Zero-Config Autonomous Bridge)

For Laravel applications, use `feedple-examples/php/laravel` or register the `FeedpleServiceProvider`.

#### Step 1: Install Package & Add Configuration

Add your environment variables to `.env`:

```ini
FEEDPLE_API_KEY=fdpl_live_your_actual_api_key
FEEDPLE_WEBHOOK_SECRET=whsec_your_webhook_signing_secret
FEEDPLE_TRANSPORT=webhook
FEEDPLE_BRIDGE_PATH=/api/feedple/bridge
```

Publish configuration (`config/feedple.php`):

```php
<?php

return [
    'api_key'        => env('FEEDPLE_API_KEY'),
    'webhook_secret' => env('FEEDPLE_WEBHOOK_SECRET'),
    'transport'      => env('FEEDPLE_TRANSPORT', 'webhook'),
    'bridge_path'    => env('FEEDPLE_BRIDGE_PATH', '/api/feedple/bridge'),
    'api_base_url'   => env('FEEDPLE_API_BASE_URL', 'https://feedple-ai-614817435356.us-central1.run.app/api/v1'),
];
```

#### Step 2: Register Service Provider

In `bootstrap/providers.php` (Laravel 11+) or `config/app.php` (Laravel 10 and below):

```php
App\Providers\FeedpleServiceProvider::class,
```

The Service Provider automatically:
1. Registers `POST /api/feedple/bridge` for query execution.
2. Registers `GET /api/feedple/bridge` for reachability health checks.
3. Automatically exempts the bridge route from CSRF verification in `bootstrap/app.php`:
   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->validateCsrfTokens(except: [
           'api/feedple/bridge',
       ]);
   })
   ```

#### Step 3: Synchronize Schema & Auto-Register Bridge

Run the schema synchronization command:

```bash
php artisan feedple:sync
```

What `feedple:sync` does:
1. Inspects your database schema using `DbConfig` (tables, columns, primary keys, foreign keys).
2. Auto-resolves your bridge URL (`APP_URL + /api/feedple/bridge`).
3. Sends the schema to Feedple Cloud via `POST /api/v1/tenants/schema/sync`.
4. Feedple Cloud registers the webhook URL and provisions `FEEDPLE_WEBHOOK_SECRET` automatically!

---

### Pattern 2: Vanilla PHP / Nginx / Apache / cPanel

For custom PHP scripts, micro-frameworks (Slim, Lumen, Flight), or shared hosting:

Create a public endpoint file (e.g. `public/feedple-bridge.php` or route in your framework):

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\WebhookBridge;

// 1. Build immutable database connection configuration
$dbConfig = DbConfig::mysql(
    host:     getenv('DB_HOST') ?: '127.0.0.1',
    database: getenv('DB_DATABASE') ?: 'myapp',
    username: getenv('DB_USERNAME') ?: 'db_user',
    password: getenv('DB_PASSWORD') ?: 'secret',
    port:     (int) (getenv('DB_PORT') ?: 3306),
);

// 2. Define table access permissions (RBAC)
$identity = new Identity(
    name:      'production',
    allTables: true, // or allowedTables: ['users', 'orders']
);

// 3. Initialize SDK in stateless webhook mode
$sdk = new FeedpleSDK(
    apiKey:    getenv('FEEDPLE_API_KEY'),
    dbConfig:  $dbConfig,
    identity:  $identity,
    transport: 'webhook',
);

// 4. Handle incoming webhook request
$bridge = new WebhookBridge(
    sdk:           $sdk,
    webhookSecret: getenv('FEEDPLE_WEBHOOK_SECRET')
);

// Support health check GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status'     => 'active',
        'service'    => 'Feedple Webhook Bridge',
        'transport'  => 'webhook',
        'configured' => true,
    ]);
    exit(0);
}

// Process POST IR execution request
$rawBody = file_get_contents('php://input') ?: '';
$headers = getallheaders() ?: [];

$result = $bridge->handle($rawBody, $headers);

http_response_code($result['status_code']);
header('Content-Type: application/json');
echo json_encode($result['body']);
```

#### Sync Schema in Vanilla PHP

Create a one-line CLI script (e.g. `bin/sync-schema.php`):

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

$sdk = new FeedpleSDK(
    apiKey:    getenv('FEEDPLE_API_KEY'),
    dbConfig:  DbConfig::mysql(host: '127.0.0.1', database: 'myapp', username: 'root', password: 'password'),
    identity:  new Identity(name: 'prod', allTables: true),
    transport: 'webhook',
);

$res = $sdk->syncSchemaHttp(
    webhookUrl: 'https://myapp.com/feedple-bridge.php',
    force: true
);

echo "Schema synced successfully: {$res['tables_count']} tables uploaded.\n";
if (!empty($res['webhook_secret'])) {
    echo "Provisioned Webhook Secret: {$res['webhook_secret']}\n";
}
```

---

### Pattern 3: Dedicated CLI WebSocket Worker (VPS / Container)

If you are running on a dedicated server or VPS and prefer persistent outbound WebSockets over inbound webhooks:

Create `bin/feedple-worker.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

$sdk = new FeedpleSDK(
    apiKey:       getenv('FEEDPLE_API_KEY'),
    dbConfig:     DbConfig::pgsql(host: '127.0.0.1', database: 'myapp', username: 'postgres', password: 'password'),
    identity:     new Identity(name: 'vps-worker', allTables: true),
    transport:    'websocket',
    syncInterval: 300,
);

// Graceful shutdown on SIGTERM / SIGINT
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn() => $sdk->stop());
    pcntl_signal(SIGINT,  fn() => $sdk->stop());
}

$sdk->run();
```

#### Managing Worker with Supervisor

```ini
[program:feedple-worker]
command=php /var/www/myapp/bin/feedple-worker.php
autostart=true
autorestart=true
stderr_logfile=/var/log/feedple-worker.err.log
stdout_logfile=/var/log/feedple-worker.out.log
user=www-data
```

---

## 🔒 Security & Verification Details

### 1. Webhook Signature Verification (`WebhookBridge`)
- Incoming requests from Feedple Cloud include an `X-Feedple-Signature` header:
  ```http
  X-Feedple-Signature: t=1710000000,v1=5d41402abc4b2a76b9719d911017c592...
  ```
- The signature is calculated as:
  ```
  hash_hmac('sha256', "{$timestamp}.{$rawBody}", $webhookSecret)
  ```
- **Replay Protection**: The bridge enforces a configurable tolerance window (default: 300 seconds / 5 minutes). Timestamps older or newer than this window are rejected with `HTTP 401`.
- **Zero-Downtime Secret Rotation**: `WebhookBridge::verifySignature()` supports comma-separated secrets or arrays of secrets (`whsec_new,whsec_old`) to allow rotation without downtime.

### 2. Table Access Rules (`Identity`)
- Table-level RBAC is verified by `PolicyEngine` **before** any SQL is generated or executed.
- If an IR query attempts to access an unauthorized table, the SDK rejects the query with `HTTP 200` (`status: error`, `code: POLICY_VIOLATION`) and an informative reason string.

### 3. Sensitive Column Filtering
- During schema inspection, sensitive columns (`password`, `token`, `secret`, `hash`, `salt`, `ssn`, `credit_card`) are automatically stripped by `SchemaServices::filterSensitiveColumns()` before schema payloads are uploaded to Feedple Cloud.

---

## 🛠️ Verification & Troubleshooting Checklist

| Symptom | Cause | Solution |
| :--- | :--- | :--- |
| `HTTP 401: Invalid webhook signature` | Mismatched `FEEDPLE_WEBHOOK_SECRET` | Re-run `php artisan feedple:sync` to retrieve and update your `FEEDPLE_WEBHOOK_SECRET` in `.env`. |
| `HTTP 401: Timestamp outside tolerance window` | Server clock drift | Synchronize system clock with NTP (`sudo ntpdate pool.ntp.org` or `chrony`). |
| `HTTP 419: Page Expired` in Laravel | CSRF Middleware blocking bridge | Add `'api/feedple/bridge'` to CSRF exception list in `bootstrap/app.php` or `VerifyCsrfToken.php`. |
| `422: Missing IR query object` | Invalid JSON payload received | Ensure your web server or proxy is passing raw request body unmodified. |
| `Database connection failed` | Incorrect database credentials | Verify connection using `DbConfig::mysql()`, `pgsql()`, or `sqlite()`. |
