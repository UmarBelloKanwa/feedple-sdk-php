# Feedple PHP SDK

[![Packagist Version](https://img.shields.io/packagist/v/feedple/feedple-sdk)](https://packagist.org/packages/feedple/feedple-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/feedple/feedple-sdk)](https://packagist.org/packages/feedple/feedple-sdk)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://spdx.org/licenses/MIT.html)

The official PHP SDK for the [Feedple AI](https://feedple.ai) platform. Connect your database to Feedple with a single class — the SDK handles authentication, schema sync, and query execution automatically.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Identity & Access Control](#identity--access-control)
- [Schema Sync](#schema-sync)
- [IR Query Execution](#ir-query-execution)
- [Connection Management](#connection-management)
- [Utilities](#utilities)
- [Running in Production](#running-in-production)
- [API Reference](#api-reference)
- [License](#license)

---

## How It Works

```
Your App                      Feedple SDK                     Feedple API
    │                              │                               │
    │  new FeedpleSDK(…)           │                               │
    │─────────────────────────────>│                               │
    │  $sdk->run()                 │                               │
    │─────────────────────────────>│── connect() ─────────────────>│
    │                              │<─ auth.ack (session_id) ──────│
    │                              │                               │
    │                              │── schema.started ────────────>│
    │                              │── schema.data (chunks) ──────>│
    │                              │── schema.completed ──────────>│
    │                              │                               │
    │                              │<─ ir.request (IR payload) ────│
    │                              │── ir.ack ────────────────────>│
    │                              │  [executes SQL against DB]    │
    │                              │── ir.result ─────────────────>│
    │                              │                               │
```

1. **Connect & Authenticate** — Opens a persistent WebSocket connection and sends your API key. The server responds with a `session_id` used to resume after disconnects.
2. **Sync Schema** — Inspects your database (tables, columns, PKs, FKs, indexes) and sends the schema to Feedple in chunks. Re-syncs on a configurable interval; skips if nothing changed.
3. **Execute Queries** — Receives incoming IR query requests from Feedple, enforces RBAC, executes them safely as parameterized PDO statements, and returns the results.

---

## Requirements

- PHP **≥ 8.1**
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

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\Core\Identity;

// 1. Create a PDO connection to your database
$pdo = new PDO(
    'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    'db_user',
    'db_pass',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 2. Define which tables Feedple can access
$identity = new Identity(
    name:      'production',
    allTables: true,           // expose every table, or…
    // allowedTables: ['users', 'orders', 'products'],  // …restrict to specific tables
);

// 3. Initialise the SDK
$sdk = new FeedpleSDK(
    apiKey:   'sk_live_...',
    db:       $pdo,
    identity: $identity,
);

// 4. Start the SDK — this call blocks until stop() is called.
//    Run this script as a CLI worker or background process.
$sdk->run();
```

> **Important:** `run()` starts the [ReactPHP](https://reactphp.org/) event loop, which **blocks** until `stop()` is called. In a web application, call this in a dedicated CLI worker process — not inside a request handler.

---

## Configuration

All constructor parameters except the first three are optional.

```php
$sdk = new FeedpleSDK(
    // Required
    apiKey:   'sk_live_...',      // Your Feedple API key
    db:       $pdo,               // PDO connection
    identity: $identity,          // Identity (see below)

    // Schema sync
    autoSync:     true,           // Sync schema on startup and periodically (default: true)
    syncInterval: 60,             // Seconds between sync cycles (default: 60)

    // Connection
    reconnectEnabled:   true,     // Reconnect on disconnect (default: true)
    maxRetries:         null,     // Max reconnect attempts; null = unlimited (default: null)
    probeBeforeConnect: false,    // HTTP probe before WS handshake for clearer errors (default: false)

    // Optional
    logger: $psrLogger,           // PSR-3 LoggerInterface; defaults to stderr output
    wsUrl:  'ws://...',           // Override the WebSocket URL (defaults to FEEDPLE_WS_URL)
);
```

### Parameter Reference

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `string` | **required** | Your Feedple API key. Throws `InvalidArgumentException` if empty. |
| `db` | `PDO` | **required** | PDO database connection. |
| `identity` | `Identity` | **required** | Controls which tables are accessible. |
| `autoSync` | `bool` | `true` | Periodically re-inspect and send the schema. |
| `syncInterval` | `int` | `60` | Seconds between schema re-sync cycles. |
| `reconnectEnabled` | `bool` | `true` | Automatically reconnect on connection loss. |
| `maxRetries` | `int\|null` | `null` | Cap on reconnect attempts. `null` means unlimited. |
| `probeBeforeConnect` | `bool` | `false` | Perform an HTTP GET before the WS handshake to surface clearer server error messages (e.g. 403 bodies). |
| `logger` | `LoggerInterface\|null` | `null` | PSR-3 logger. A stderr logger is used when `null`. |
| `wsUrl` | `string\|null` | `null` | Override the default WebSocket endpoint URL. |

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

The SDK automatically syncs your schema on startup (after authentication) and then every `syncInterval` seconds. You can also trigger a manual sync:

```php
// Trigger a schema sync manually.
// Safe to call while the event loop is running (e.g. via a signal handler).
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

The SDK receives IR (Intermediate Representation) query objects from the Feedple server and executes them as **parameterized PDO statements** against your database. You do not call this yourself — it is invoked automatically via the WebSocket.

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

Closes the WebSocket connection and stops the ReactPHP event loop. Safe to call from a signal handler:

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
    wsUrl:   'wss://feedple-ai.onrender.com/api/v1/tenants/ws',
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
        \PDO              $db,
        Identity          $identity,
        bool              $autoSync            = true,
        int               $syncInterval        = 60,
        bool              $reconnectEnabled    = true,
        ?int              $maxRetries          = null,
        bool              $probeBeforeConnect  = false,
        ?LoggerInterface  $logger              = null,
        ?string           $wsUrl               = null,
    );

    public function run(): void;          // starts event loop (blocking)
    public function syncSchema(): void;   // manually trigger schema sync
    public function stop(): void;         // stop event loop and close connection
    public function buildCompiler(): SqlCompiler; // raw SQL RBAC compiler
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
| `AuthException` | `RuntimeException` | WebSocket authentication fails (`auth.error` received) |
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
