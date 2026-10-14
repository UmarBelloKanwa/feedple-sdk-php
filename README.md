# Feedple PHP SDK

The official PHP SDK for the [Feedple AI](https://feedple.ai) platform. Connect your database to Feedple AI with minimal setup.

[![Packagist Version](https://img.shields.io/packagist/v/feedple/feedple-sdk)](https://packagist.org/packages/feedple/feedple-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/feedple/feedple-sdk)](https://packagist.org/packages/feedple/feedple-sdk)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://spdx.org/licenses/MIT.html)

---

## Installation

```bash
composer require feedple/feedple-sdk
```

**Requirements:** PHP `>= 8.0` and the `pdo` extension for your database (`pdo_mysql`, `pdo_pgsql`, or `pdo_sqlite`).

---

## Core Setup in 3 Steps

```php
require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

// 1. Configure database connection
$dbConfig = DbConfig::mysql(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'root',
    password: 'secret_password',
);

// 2. Define table access permissions (RBAC)
$identity = new Identity(
    name:          'main-app',
    allTables:     true, // or: allowedTables: ['users', 'orders']
);

// 3. Initialize Feedple SDK
$sdk = new FeedpleSDK(
    apiKey:   getenv('FEEDPLE_API_KEY'),
    dbConfig: $dbConfig,
    identity: $identity,
);
```

---

## Transport Modes

Feedple supports two execution transports:

### 1. Webhook Mode (Recommended for Web & APIs)
Stateless and fast (~15–30ms). Requires **zero** background daemons or event loops. Incoming queries from Feedple Cloud are handled directly via `WebhookBridge`:

```php
use Feedple\Sdk\Core\WebhookBridge;

$bridge = new WebhookBridge(
    sdk:           $sdk,
    webhookSecret: getenv('FEEDPLE_WEBHOOK_SECRET'),
);

$rawBody = file_get_contents('php://input') ?: '';
$headers = getallheaders() ?: [];

$result = $bridge->handle($rawBody, $headers);

http_response_code($result['status_code']);
header('Content-Type: application/json');
echo json_encode($result['body']);
```

### 2. WebSocket Mode (CLI Daemon / Local Dev)
Persistent outbound socket connection. Ideal for CLI workers or local environments behind NAT/firewalls:

```php
$sdk = new FeedpleSDK(
    apiKey:    getenv('FEEDPLE_API_KEY'),
    dbConfig:  $dbConfig,
    identity:  $identity,
    transport: 'websocket',
);

// Start worker (blocks until stopped)
$sdk->run();

// Or stop cleanly from another process/signal:
$sdk->stop();
```

> **Auto Transport Selection:** When `transport` is omitted, the SDK defaults to `'websocket'` in local development (`APP_ENV=local`) and `'webhook'` in production. If your app is on `localhost` but targeting Feedple Cloud, `'websocket'` is automatically forced so the cloud can reach your database.

---

## Database Configuration (`DbConfig`)

```php
use Feedple\Sdk\DbConfig;

// PostgreSQL
$db = DbConfig::pgsql(host: '127.0.0.1', database: 'myapp', username: 'postgres', password: 'secret', port: 5432);

// MySQL / MariaDB
$db = DbConfig::mysql(host: '127.0.0.1', database: 'myapp', username: 'root', password: 'secret', port: 3306);

// SQLite
$db = DbConfig::sqlite('/path/to/database.sqlite');

// Custom DSN
$db = DbConfig::raw('sqlsrv:Server=localhost;Database=myapp', username: 'sa', password: 'secret');
```

---

## Table Access Control (`Identity`)

Control which database tables Feedple AI is permitted to query:

```php
use Feedple\Sdk\Core\Identity;

// Expose specific tables only (Recommended)
$identity = new Identity(
    name:          'restricted-access',
    allowedTables: ['users', 'orders', 'products'],
);

// Expose all tables
$identity = new Identity(
    name:      'full-access',
    allTables: true,
);
```

*Note: Sensitive columns (`password`, `token`, `secret`, `hash`, `salt`, `ssn`, `credit_card`) are always excluded automatically.*

---

## Schema Synchronization

Upload your database structure (tables, columns, primary & foreign keys) to Feedple:

```php
// HTTP one-shot sync (recommended for deploy scripts / CI)
$result = $sdk->syncSchemaHttp(
    webhookUrl: 'https://myapp.com/api/feedple/bridge',
    force: false,
);

// Sync schema & notify WebSocket if active
$sdk->syncSchema();
```

---

## Laravel Integration (Zero-Config Auto-Discovery)

The SDK automatically registers the service provider via **Laravel Package Discovery**. No manual registration in `bootstrap/app.php` or `config/app.php` is needed!

1. Install the SDK:
```bash
composer require feedple/feedple-sdk
```

2. Add your Feedple API Key to `.env`:
```ini
FEEDPLE_API_KEY=fdpl_live_your_actual_api_key
```

When you start your development server (`php artisan serve`), the Feedple background worker daemon starts automatically in < 5ms without blocking.

*(Optional) Publish the configuration file if you wish to customize options:*
```bash
php artisan vendor:publish --tag=feedple-config
```

### Artisan Commands

```bash
# Push schema and register webhook bridge
php artisan feedple:sync

# Check worker status
php artisan feedple:status

# Start background worker daemon (WebSocket mode)
php artisan feedple:start
php artisan feedple:start --tail

# Stop background worker daemon
php artisan feedple:stop
```

---

## Environment Configuration

By default, the SDK connects to **Feedple Cloud**:
- **API Base URL**: `https://feedple-ai-614817435356.us-central1.run.app/api/v1`
- **WebSocket URL**: `wss://feedple-ai-614817435356.us-central1.run.app/api/v1/tenants/ws`

To connect to a **local Feedple server** during development, set in `.env`:

```ini
FEEDPLE_DEV_ENV=true
```
*(Sets API to `http://localhost:8000/api/v1` and WebSocket to `ws://localhost:8000/api/v1/tenants/ws`)*

---

## Core API Reference

### `FeedpleSDK`
```php
namespace Feedple\Sdk;

class FeedpleSDK
{
    public function __construct(
        string            $apiKey,
        DbConfig          $dbConfig,
        Identity          $identity,
        ?string           $autoloadPath       = null,
        bool              $autoSync           = true,
        int               $syncInterval       = 60,
        bool              $reconnectEnabled   = true,
        ?int              $maxRetries         = null,
        bool              $probeBeforeConnect = false,
        ?LoggerInterface  $logger             = null,
        ?string           $runtimeDir         = null,
        bool              $isWorkerProcess    = false,
        ?string           $transport          = null // 'webhook' | 'websocket'
    );

    public function run(): void;
    public function stop(): void;
    public function syncSchema(?string $webhookUrl = null): void;
    public function syncSchemaHttp(?string $apiBaseUrl = null, bool $force = false, ?string $webhookUrl = null): array;
    public function executeIr(array $ir): array;
    public function getTransport(): string;
    public function setTransport(string $transport): void;

    // Helpers
    public static function getDefaultTransport(): string;
    public static function isDevelopment(): bool;
    public static function isProduction(): bool;
    public static function isWebhookReachable(): bool;
    public static function isLocalApp(): bool;
    public static function isDevEnv(): bool;
}
```

### `WebhookBridge`
```php
namespace Feedple\Sdk\Core;

class WebhookBridge
{
    public function __construct(FeedpleSDK $sdk, ?string $webhookSecret = null, int $tolerance = 300);
    public function handle(string $rawBody, array $headers): array; // ['status_code' => int, 'body' => array]
    public static function verifySignature(string $rawBody, ?string $signatureHeader, string|array|null $secret = null, int $tolerance = 300): array;
}
```

### `DbConfig`
```php
namespace Feedple\Sdk;

final class DbConfig
{
    public static function mysql(string $host, string $database, ?string $username = null, ?string $password = null, int $port = 3306, string $charset = 'utf8mb4'): self;
    public static function pgsql(string $host, string $database, ?string $username = null, ?string $password = null, int $port = 5432): self;
    public static function sqlite(string $path): self;
    public static function raw(string $dsn, ?string $username = null, ?string $password = null, array $options = []): self;
    public function toPdo(): \PDO;
}
```

### `Identity`
```php
namespace Feedple\Sdk\Core;

class Identity
{
    public function __construct(?string $name = null, array $allowedTables = [], bool $allTables = false);
}
```

---

## License

MIT License.
