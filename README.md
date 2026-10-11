# Feedple PHP SDK

[![Packagist Version](https://img.shields.io/packagist/v/feedple/feedple-sdk)](https://packagist.org/packages/feedple/feedple-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/feedple/feedple-sdk)](https://packagist.org/packages/feedple/feedple-sdk)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://spdx.org/licenses/MIT.html)

The official PHP SDK for the [Feedple AI](https://feedple.ai) platform. Connect your database to Feedple with a single class — the SDK handles authentication, schema sync, and query execution automatically.

---

## Table of Contents

- [Dual-Transport Architecture](#dual-transport-architecture)
- [Stateless HTTP Webhook (Production & Shared Hosting)](#stateless-http-webhook-production--shared-hosting)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Laravel Integration](#laravel-integration)
- [Configuration](#configuration)
- [Identity & Access Control](#identity--access-control)
- [Schema Sync](#schema-sync)
- [IR Query Execution](#ir-query-execution)
- [API Reference](#api-reference)
- [License](#license)

---

## Dual-Transport Architecture

Feedple provides a dual-transport architecture designed to suit both shared hosting / serverless environments and local development:

1. **Production: Stateless HTTP Webhook** (`FEEDPLE_TRANSPORT=webhook`)
   - Optimized for shared hosting (Namecheap, cPanel, CloudLinux LVE, CageFS) and serverless (AWS Lambda / Bref).
   - **Zero persistent processes, zero background daemons, zero ReactPHP event loops.**
   - Feedple Cloud sends cryptographically signed HTTPS POST requests (`HMAC-SHA256`) to your registered webhook endpoint (`/api/feedple/bridge`).
   - The query compiles and executes against your database within the HTTP lifecycle in ~15-30ms.
   - Schema synchronization is performed on-demand or via one-shot cron (`php artisan feedple:sync`).

2. **Development: Persistent WebSocket** (`FEEDPLE_TRANSPORT=websocket`)
   - Ideal for local development or dedicated VPS where persistent long-lived background daemons are permitted.
   - Connects outbound to `wss://...` with auto-reconnection and real-time streaming.

---

## Stateless HTTP Webhook (Production & Shared Hosting)

```
Feedple Cloud                      Your Webhook Bridge (/api/feedple/bridge)        Your Database
     │                                                │                                  │
     │── HTTPS POST (IR query + HMAC Signature) ─────>│                                  │
     │                                                │── Verify HMAC-SHA256 Signature   │
     │                                                │── Validate IR AST & RBAC         │
     │                                                │── Compile Parameterized SQL ────>│
     │                                                │<─ Query Result (rows) ───────────│
     │<─ HTTP 200 JSON Response (status, data, rows) ─│                                  │
     │   [Completed in 15-30ms, no daemon required]   │                                  │
```

## Requirements

- PHP **≥ 8.0** (Compatible with PHP 8.0, 8.1, 8.2, 8.3, 8.4+)
- [Composer](https://getcomposer.org/)
- A database accessible via **PDO** (MySQL, PostgreSQL, SQLite, and others)
- The `pdo_<driver>` PHP extension for your database (e.g. `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`)

---

## Installation

```bash
composer require feedple/feedple-sdk
```

No additional configuration is needed — Composer's autoloader handles all class loading automatically.

---

## Quick Start

### 1. Stateless HTTP Webhook (Recommended for Web & Shared Hosting)

In web applications, APIs, and shared hosting (Apache, PHP-FPM, cPanel, Nginx, Lambda/Bref), use the **Stateless Webhook Bridge**. It requires zero background workers, zero ReactPHP loops, and zero daemon processes.

```php
<?php
// public/feedple-bridge.php (or your framework route handler)
require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\WebhookBridge;

// 1. Configure your database connection
$dbConfig = DbConfig::mysql(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'db_user',
    password: 'db_password',
);

// 2. Define table access permissions
$identity = new Identity(
    name:      'production',
    allTables: true,           // expose all tables, or specify:
    // allowedTables: ['users', 'orders', 'products'],
);

// 3. Initialize the SDK in stateless webhook mode
$sdk = new FeedpleSDK(
    apiKey:    getenv('FEEDPLE_API_KEY'),
    dbConfig:  $dbConfig,
    identity:  $identity,
    transport: 'webhook',
);

// 4. Handle incoming Feedple Cloud webhook queries
$bridge = new WebhookBridge(
    sdk:           $sdk,
    webhookSecret: getenv('FEEDPLE_WEBHOOK_SECRET')
);

$rawBody = file_get_contents('php://input') ?: '';
$headers = getallheaders() ?: [];

$result = $bridge->handle($rawBody, $headers);

http_response_code($result['status_code']);
header('Content-Type: application/json');
echo json_encode($result['body']);
```

### 2. Persistent WebSocket Worker (For Dedicated CLI Daemons)

If you are running a dedicated CLI worker on a VPS or container:

```php
<?php
// worker.php
require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

$sdk = new FeedpleSDK(
    apiKey:    getenv('FEEDPLE_API_KEY'),
    dbConfig:  DbConfig::mysql(host: '127.0.0.1', database: 'myapp', username: 'db_user', password: 'secret'),
    identity:  new Identity(name: 'worker', allTables: true),
    transport: 'websocket',
);

// Starts the ReactPHP event loop (blocks until stopped)
$sdk->run();
```

---

## Laravel Integration

In Laravel applications, Feedple runs completely autonomously in stateless HTTP webhook mode without requiring background workers, ReactPHP loops, or supervisor daemons.

### 1. Environment Configuration (`.env`)

Feedple uses two credentials for secure dual-direction communication:

```ini
# Outbound: Used by your application to authenticate outgoing requests to Feedple Cloud
FEEDPLE_API_KEY=fdpl_live_your_actual_api_key

# Inbound: Used by WebhookBridge to cryptographically verify incoming webhook signatures
FEEDPLE_WEBHOOK_SECRET=whsec_your_webhook_signing_secret

# Transport & Bridge settings
FEEDPLE_TRANSPORT=webhook
FEEDPLE_BRIDGE_PATH=/api/feedple/bridge
FEEDPLE_API_BASE_URL=https://feedple-ai-614817435356.us-central1.run.app/api/v1
```

> **Note on Keys:**
> - `FEEDPLE_API_KEY` authenticates outbound API calls (e.g. `php artisan feedple:sync`) to Feedple Cloud.
> - `FEEDPLE_WEBHOOK_SECRET` is the shared secret used by `WebhookBridge` to verify `X-Feedple-Signature` on incoming queries dispatched from Feedple Cloud.

### 2. Auto-Registered Bridge Route

When using the `FeedpleServiceProvider`, the bridge endpoint is registered automatically at `POST /api/feedple/bridge` and exempted from CSRF protection:

```bash
# Test bridge reachability in terminal or browser
curl http://localhost:8000/api/feedple/bridge
```
Returns:
```json
{"status":"active","service":"Feedple Webhook Bridge","transport":"webhook","configured":true,"secret_set":true}
```

### 3. Synchronize Schema & Auto-Register Bridge

To sync your database schema with Feedple Cloud and automatically register your bridge URL:

```bash
php artisan feedple:sync
```

When you run `feedple:sync`:
1. It inspects your database schema (tables, columns, primary keys, foreign keys).
2. It auto-detects your webhook bridge URL (`APP_URL + /api/feedple/bridge`).
3. It sends the schema to Feedple Cloud over HTTP.
4. Feedple Cloud registers the webhook URL and provisions your `FEEDPLE_WEBHOOK_SECRET` automatically!

Options:
- `--force`: Force schema update even if the hash matches.
- `--bridge-url=https://xyz.ngrok.io/api/feedple/bridge`: Override the registered bridge URL (useful for tunneling in local dev).

---

## Configuration

### `DbConfig` Factories

Feedple uses immutable `DbConfig` objects to protect credentials and handle driver differences:

```php
use Feedple\Sdk\DbConfig;

// MySQL / MariaDB
$dbConfig = DbConfig::mysql(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'root',
    password: 'secret_password',
    port:     3306,
    charset:  'utf8mb4'
);

// PostgreSQL
$dbConfig = DbConfig::pgsql(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'postgres',
    password: 'secret_password',
    port:     5432
);

// SQLite
$dbConfig = DbConfig::sqlite(path: '/path/to/database.sqlite');

// Custom / Raw DSN
$dbConfig = DbConfig::raw(
    dsn:      'sqlsrv:Server=localhost;Database=myapp',
    username: 'sa',
    password: 'secret_password'
);
```

### SDK Constructor

```php
$sdk = new FeedpleSDK(
    // Required
    apiKey:    'fdpl_live_...',    // Your Feedple API key
    dbConfig:  $dbConfig,          // DbConfig instance
    identity:  $identity,          // Table access identity

    // Transport (Default: 'websocket', or 'webhook' for stateless web mode)
    transport: 'webhook',

    // Schema sync
    autoSync:     true,            // Periodic sync (used in websocket mode)
    syncInterval: 60,              // Seconds between sync cycles

    // Connection (WebSocket mode)
    reconnectEnabled:   true,
    maxRetries:         null,
    probeBeforeConnect: false,

    // Optional
    logger:     $psrLogger,        // PSR-3 LoggerInterface; defaults to stderr
    runtimeDir: sys_get_temp_dir() // Directory for PID/control files
);
```

### Parameter Reference

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `string` | **required** | Your Feedple API key. Throws `InvalidArgumentException` if empty. |
| `dbConfig` | `DbConfig` | **required** | Immutable connection config (`DbConfig::mysql()`, `pgsql()`, `sqlite()`, `raw()`). |
| `identity` | `Identity` | **required** | Controls table access permissions and RBAC. |
| `transport` | `string` | `'websocket'` | `'webhook'` (stateless HTTP bridge) or `'websocket'` (persistent worker daemon). |
| `autoSync` | `bool` | `true` | Periodically re-inspect and send the schema (WebSocket mode). |
| `syncInterval` | `int` | `60` | Seconds between schema re-sync cycles. |
| `reconnectEnabled` | `bool` | `true` | Automatically reconnect on connection loss (WebSocket mode). |
| `maxRetries` | `int\|null` | `null` | Cap on reconnect attempts. `null` means unlimited. |
| `probeBeforeConnect` | `bool` | `false` | Perform HTTP probe before WS handshake for clearer diagnostics. |
| `logger` | `LoggerInterface\|null` | `null` | PSR-3 logger. A stderr/file logger is used when `null`. |
| `runtimeDir` | `string\|null` | `sys_get_temp_dir()` | Directory for PID and worker lock files. |

---

## Identity & Access Control

`Identity` controls which database tables Feedple can see and query.

```php
use Feedple\Sdk\Core\Identity;

// Grant access to all tables
$adminIdentity = new Identity(
    name:      'admin',
    allTables: true,
);

// Restrict to specific tables only
$restrictedIdentity = new Identity(
    name:          'analytics-service',
    allowedTables: ['users', 'orders', 'products', 'events'],
    allTables:     false,   // default
);
```

### Identity Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `name` | `string\|null` | — | Human-readable label for this identity. |
| `allowedTables` | `string[]` | `[]` | Tables this identity may access. Ignored when `allTables` is `true`. |
| `allTables` | `bool` | `false` | When `true`, all current and future tables are accessible. |

> **Security:** The SDK enforces RBAC on every IR query received from the server. If the IR references a table not in `allowedTables`, a `RuntimeException` is thrown and an `ir.error` is sent back to the server — the query never reaches the database.

---

## Schema Sync

Feedple supports both one-shot HTTP schema synchronization (ideal for CI/CD, deployment commands, and webhooks) and continuous background synchronization (for WebSocket workers).

### 1. One-Shot HTTP Schema Sync (`syncSchemaHttp`)

In stateless webhook mode or during deployment (`php artisan feedple:sync`), inspect and push your schema via a direct HTTP POST to Feedple Cloud:

```php
$result = $sdk->syncSchemaHttp(
    apiBaseUrl: 'https://feedple-ai-614817435356.us-central1.run.app/api/v1',
    force:      false, // Set to true to bypass local hash change detection
    webhookUrl: 'https://myapp.com/api/feedple/bridge' // Registers webhook bridge
);

// Returns:
// [
//     'status'                 => 'success',
//     'schema_version'         => 2,
//     'skipped'                => false,
//     'tables_count'           => 34,
//     'registered_webhook_url' => 'https://myapp.com/api/feedple/bridge',
//     'webhook_secret'         => 'whsec_...', // Provisioned by Feedple Cloud
// ]
```

### 2. WebSocket Periodic Sync (`syncSchema`)

In persistent WebSocket mode, the SDK automatically syncs on startup and every `syncInterval` seconds. You can also trigger a sync manually:

```php
// Trigger a schema sync manually over the active WebSocket connection.
$sdk->syncSchema();
```

### What gets synced

For each table the identity can access, the SDK sends:

- **Columns** — name, type string, nullable flag, default value
- **Primary key** — constrained column names
- **Foreign keys** — local columns, referenced table, referenced columns
- **Indexes** — name, column names, unique flag
- **Unique constraints** — name, column names

### Database support

Schema introspection uses standard `INFORMATION_SCHEMA` queries (MySQL, PostgreSQL) and `PRAGMA` statements (SQLite), with no external dependencies.

| Database | PDO driver | Support |
|----------|-----------|---------|
| MySQL / MariaDB | `pdo_mysql` | ✅ Full |
| PostgreSQL | `pdo_pgsql` | ✅ Full |
| SQLite | `pdo_sqlite` | ✅ Full |
| Others | Any | ⚠️ Partial (via ANSI INFORMATION_SCHEMA) |

### Sensitive column filtering

The following column names are **never sent** to Feedple:

`password`, `token`, `secret`, `hash`, `salt`, `ssn`, `credit_card`

You can apply this filter manually:

```php
use Feedple\Sdk\Core\SchemaServices;

$safeSchema = SchemaServices::filterSensitiveColumns($rawSchema);
```

---

## IR Query Execution

The SDK receives IR (Intermediate Representation) query objects from the Feedple server and executes them as **parameterized PDO statements** against your database. You do not call this yourself — it is invoked automatically over the background connection.

### IR Schema

```php
$ir = [
    'operation' => 'query',              // always "query"
    'table'     => 'orders',            // primary FROM table
    'fields'    => [                    // columns to SELECT
        ['column' => 'orders.id',      'expression' => null,             'alias' => null],
        ['column' => 'orders.amount',  'expression' => 'sum',            'alias' => 'total'],
        ['column' => 'orders.user_id', 'expression' => 'count(distinct)','alias' => 'unique_users'],
    ],
    'joins' => [                         // JOIN clauses
        [
            'table'     => 'users',
            'on_left'   => 'orders.user_id',
            'on_right'  => 'users.id',
            'join_type' => 'INNER',      // "INNER" or "LEFT"
        ],
    ],
    'filters' => [                       // WHERE conditions (ANDed together)
        ['column' => 'orders.status', 'operator' => 'eq',  'value' => 'active'],
        ['column' => 'orders.amount', 'operator' => 'gte', 'value' => 100],
    ],
    'group_by' => ['orders.status'],
    'having'   => [['column' => 'orders.id', 'operator' => 'gt', 'value' => 5]],
    'order_by' => ['orders.created_at DESC'],
    'limit'    => 100,
    'offset'   => 0,
];
```

### Supported filter operators

| Operator | Aliases | SQL |
|----------|---------|-----|
| `eq` | `=` | `col = ?` |
| `neq` | `!=` | `col != ?` |
| `gt` | `>` | `col > ?` |
| `gte` | `>=` | `col >= ?` |
| `lt` | `<` | `col < ?` |
| `lte` | `<=` | `col <= ?` |
| `in` | `in_` | `col IN (?, ?, …)` |
| `like` | — | `col LIKE ?` |
| `ilike` | — | `col ILIKE ?` |

### Supported aggregate expressions

`count`, `count(distinct)`, `sum`, `avg`, `min`, `max`

### SQL injection safety

`IrBuilder` never uses string interpolation for values. All values are passed as PDO bound parameters (`?`). Identifiers (table and column names) are validated against `[a-zA-Z0-9_$]+` before being double-quoted.

---

## Connection Management

### Reconnect behaviour

The SDK reconnects automatically using exponential back-off:

| Attempt | Delay |
|---------|-------|
| 1 | 5 s |
| 2 | 10 s |
| 3 | 20 s |
| 4+ | 40 s → capped at 60 s |

Authentication failures (`auth.error`) are **not** retried — the SDK stops the event loop immediately and logs the error.

### Session resume

The `session_id` received in `auth.ack` is stored in `$ws->sessionId` and re-sent on every reconnect attempt. The server resumes the session if it is still within TTL, or issues a new session ID if it has expired.

### Stopping the SDK

```php
$sdk->stop();
```

Closes the background connection and stops the worker event loop. Safe to call from a signal handler:

```php
// Graceful shutdown on SIGTERM / SIGINT
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn() => $sdk->stop());
pcntl_signal(SIGINT,  fn() => $sdk->stop());

$sdk->run();
```

---

## Utilities

### `IrBuilder` — build SQL from an IR payload

```php
use Feedple\Sdk\Core\IrBuilder;

['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### `SqlCompiler` — validate raw SQL against RBAC

```php
use Feedple\Sdk\Core\SqlCompiler;
use Feedple\Sdk\Core\PolicyEngine;
use Feedple\Sdk\Core\Identity;

$identity = new Identity(name: 'reader', allowedTables: ['users', 'orders']);
$compiler = new SqlCompiler(new PolicyEngine($identity));

// Throws RuntimeException if SQL references a denied table
$safeSql = $compiler->compile('SELECT id, name FROM users WHERE active = 1');
```

### `SchemaServices` — schema utilities

```php
use Feedple\Sdk\Core\SchemaServices;
use Feedple\Sdk\Core\Identity;

// Inspect schema
$schema = SchemaServices::getSchema($pdo, $identity);

// Hash for change detection
$hash = SchemaServices::generateSchemaHash($schema);

// Check if sync is needed
$changed = SchemaServices::shouldSyncSchema($oldSchema, $newSchema);

// Strip sensitive columns
$safe = SchemaServices::filterSensitiveColumns($schema);
```

### `PolicyEngine` — direct RBAC checks

```php
use Feedple\Sdk\Core\PolicyEngine;
use Feedple\Sdk\Core\Identity;

$policy = new PolicyEngine(new Identity(name: 'reader', allowedTables: ['orders']));

$policy->canAccessTable('orders');   // true
$policy->canAccessTable('invoices'); // false

$policy->validateIrAccess($ir);      // throws RuntimeException if denied
```

### `JsonSerializer` — safe JSON encoding

```php
use Feedple\Sdk\Core\JsonSerializer;

$json   = JsonSerializer::encode($data);   // handles DateTime, objects
$array  = JsonSerializer::decode($json);
$normal = JsonSerializer::normalize($value); // recursive normalization only
```

---

## Running in Production

### As a standalone CLI worker

The simplest approach is a dedicated PHP process managed by your process manager:

```bash
php worker.php
```

```php
// worker.php
<?php
require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\Core\Identity;

$pdo = new PDO(getenv('DATABASE_URL'));

$sdk = new FeedpleSDK(
    apiKey:       getenv('FEEDPLE_API_KEY'),
    db:           $pdo,
    identity:     new Identity(name: 'prod', allTables: true),
    syncInterval: 300,
);

$sdk->run();
```

### With Supervisor

```ini
[program:feedple-worker]
command=php /var/www/app/worker.php
autostart=true
autorestart=true
stderr_logfile=/var/log/feedple-worker.err.log
stdout_logfile=/var/log/feedple-worker.out.log
```

### With a PSR-3 logger (Monolog)

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('feedple');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));

$sdk = new FeedpleSDK(
    apiKey:   'sk_live_...',
    db:       $pdo,
    identity: $identity,
    logger:   $logger,
);
```

### Environment URLs

The SDK targets the **development** server (`localhost:8000`) by default. Switch to production by overriding the `wsUrl` parameter:

```php
use function Feedple\Sdk\FEEDPLE_WS_URL;

// Development (default)
$sdk = new FeedpleSDK(apiKey: '...', db: $pdo, identity: $identity);

// Production
$sdk = new FeedpleSDK(
    apiKey:  '...',
    db:      $pdo,
    identity: $identity,
    wsUrl:   'wss://feedple-ai-614817435356.us-central1.run.app/api/v1/tenants/ws',
);
```

---

## API Reference

### `FeedpleSDK`

```php
namespace Feedple\Sdk;

class FeedpleSDK
{
    public function __construct(
        string            $apiKey,
        DbConfig          $dbConfig,
        Identity          $identity,
        ?string           $autoloadPath        = null,
        bool              $autoSync            = true,
        int               $syncInterval        = 60,
        bool              $reconnectEnabled    = true,
        ?int              $maxRetries          = null,
        bool              $probeBeforeConnect  = false,
        ?LoggerInterface  $logger              = null,
        ?string           $runtimeDir          = null,
        bool              $isWorkerProcess     = false,
        ?string           $transport           = null // 'webhook' or 'websocket'
    );

    public function run(): void; // starts event loop for worker daemon (blocking)
    public function stop(): void; // stop event loop and close connection
    public function syncSchema(?string $webhookUrl = null): void; // sync over WebSocket
    public function syncSchemaHttp(?string $apiBaseUrl = null, bool $force = false, ?string $webhookUrl = null): array; // HTTP one-shot sync
    public function executeIr(array $ir): array; // validates RBAC and executes IR AST against database
    public function getTransport(): string;
    public function setTransport(string $transport): void;
    public function buildCompiler(): SqlCompiler; // raw SQL RBAC compiler
}
```

### `DbConfig`

```php
namespace Feedple\Sdk;

final class DbConfig implements \JsonSerializable
{
    public static function mysql(string $host, string $database, ?string $username = null, ?string $password = null, int $port = 3306, string $charset = 'utf8mb4', array $options = []): self;
    public static function pgsql(string $host, string $database, ?string $username = null, ?string $password = null, int $port = 5432, array $options = []): self;
    public static function sqlite(string $path, array $options = []): self;
    public static function raw(string $dsn, ?string $username = null, ?string $password = null, array $options = []): self;

    public function toPdo(): \PDO;
}
```

### `WebhookBridge`

```php
namespace Feedple\Sdk\Core;

class WebhookBridge
{
    public function __construct(FeedpleSDK $sdk, ?string $webhookSecret = null, int $tolerance = 300);

    /**
     * Verifies HMAC-SHA256 signature from X-Feedple-Signature header.
     * Supports single or comma-separated secrets for zero-downtime rotation.
     */
    public static function verifySignature(
        string $rawBody,
        ?string $signatureHeader,
        string|array|null $secret = null,
        int $tolerance = 300
    ): array; // ['valid' => bool, 'reason' => ?string, 'timestamp' => ?int]

    /**
     * Dispatches an incoming raw HTTP request.
     * Verifies signature, validates IR, executes query, and returns structured result.
     */
    public function handle(string $rawBody, array $headers): array;
    // Returns: ['status_code' => int, 'body' => array]
}
```

### `Identity`

```php
namespace Feedple\Sdk\Core;

class Identity
{
    public function __construct(
        public readonly ?string $name,
        public readonly array   $allowedTables = [],
        public readonly bool    $allTables     = false,
    );
}
```

### `PolicyEngine`

```php
namespace Feedple\Sdk\Core;

class PolicyEngine
{
    public function __construct(Identity $identity);

    public function canAccessTable(string $table): bool;
    public function validateIrAccess(array $ir): void; // throws RuntimeException if denied
}
```

### `IrBuilder`

```php
namespace Feedple\Sdk\Core;

class IrBuilder
{
    /** @return array{sql: string, params: list<mixed>} */
    public static function buildQueryFromIr(array $ir): array;
}
```

### `SchemaServices`

```php
namespace Feedple\Sdk\Core;

class SchemaServices
{
    public const SENSITIVE_COLUMNS: string[];

    public static function getSchema(\PDO $db, Identity $identity): array;
    public static function generateSchemaHash(array $schema): string;
    public static function normalizeSchema(array $schema): string;
    public static function shouldSyncSchema(array $old, array $new): bool;
    public static function filterSensitiveColumns(array $schema): array;
}
```

### `SqlCompiler`

```php
namespace Feedple\Sdk\Core;

class SqlCompiler
{
    public function __construct(PolicyEngine $policy, string $dialect = 'postgres');

    public function parse(string $sql): array;            // ['sql' => …, 'tables' => […]]
    public function extractTables(string $sql): array;    // string[]
    public function validateAccess(array $tables): void;  // throws RuntimeException if denied
    public function compile(string $sql): string;
}
```

### `JsonSerializer`

```php
namespace Feedple\Sdk\Core;

class JsonSerializer
{
    public static function encode(mixed $data): string;
    public static function decode(string $json): array;
    public static function normalize(mixed $value): mixed;
}
```

### Exceptions

| Class | Extends | When thrown |
|-------|---------|-------------|
| `AuthException` | `RuntimeException` | Connection authentication fails (`auth.error` received) |
| `SchemaSyncException` | `RuntimeException` | Schema sync HTTP/API call fails |
| `IrExecutionException` | `RuntimeException` | IR query execution error (unused directly — `RuntimeException` is thrown inline) |

---

## Testing

```bash
composer install
./vendor/bin/phpunit
```

Tests requiring a live database (SQLite, MySQL) are automatically skipped when the corresponding PDO driver is not installed. The pure-logic tests (IR builder, policy engine, schema hash/filter) always run.

---

## License

`feedple-sdk` is distributed under the terms of the [MIT](https://spdx.org/licenses/MIT.html) license.
