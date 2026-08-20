# Feedple PHP SDK Developer Guide & Technical Documentation

Welcome to the technical documentation for the **Feedple PHP SDK** (`feedple/feedple-sdk`), the official PHP integration library for the [Feedple AI](https://feedple.ai) platform.

This guide provides an exhaustive walkthrough of the SDK's architecture, installation, configuration, public APIs, background process lifecycle, security policies, error handling, and production deployment patterns.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Installation](#2-installation)
3. [Project Structure](#3-project-structure)
4. [Getting Started](#4-getting-started)
5. [Usage Guide](#5-usage-guide)
6. [Configuration](#6-configuration)
7. [Common Workflows](#7-common-workflows)
8. [Error Handling](#8-error-handling)
9. [Best Practices](#9-best-practices)
10. [Troubleshooting](#10-troubleshooting)
11. [API Reference](#11-api-reference)
12. [Examples](#12-examples)
13. [Developer Notes & API Usability Recommendations](#13-developer-notes--api-usability-recommendations)

---

## 1. Overview

### What is the Feedple PHP SDK?
`feedple/feedple-sdk` is an asynchronous, background-managed PHP library designed to connect your application's relational database (PostgreSQL, MySQL, SQLite, etc.) to Feedple AI. It connects your PDO database connection with Feedple's intelligence server through a secure, persistent background connection.

### The Problem It Solves
Integrating AI platforms with PHP databases traditionally presents two major challenges:
1. **Blocking Lifecycle**: Traditional PHP scripts follow a short-lived request/response lifecycle (`CGI` / `FPM`) that cannot maintain persistent background connections without blocking HTTP client responses.
2. **Security Vulnerabilities**: Exposing database ports directly to cloud providers or passing database credentials through loose strings creates credential leakage risks.

The Feedple PHP SDK solves these issues:

- **Zero-Blocking Architecture**: Uses `proc_open()` to spawn a completely detached, background worker process (`src/worker.php`) running a non-blocking ReactPHP event loop. The main process returns control to your application instantly (0ms latency impact on web requests).
- **Cross-Platform Compatibility**: Requires no `pcntl` or `posix` extensions, making it 100% compatible across Linux, macOS, and Windows environments.
- **Credential Protection**: Uses PHP 8.2 `#[\SensitiveParameter]` attributes to automatically redact database passwords from exception stack traces and Sentry/Bugsnag error trackers.
- **Automated Schema Synchronization**: Introspects database structures (tables, columns, PKs, FKs, indexes) and transmits schema updates securely using SHA-256 hash change detection.
- **Safe IR Query Execution**: Receives structured Intermediate Representation (IR) JSON objects, translates them into parameterized PDO statements via `IrBuilder`, and executes them safely against your database engine.
- **Table-Level RBAC**: Enforces strict table-level access rules before any query touches the database.

---

## 2. Installation

> [!NOTE]
> Make sure `proc_open` is enabled in `php.ini` before bootstrapping the background worker.

### Prerequisites
- **PHP**: Version `8.1` or higher (`8.1`, `8.2`, `8.3`, `8.4+`).
- **PDO Extension**: `pdo` along with your database driver (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`).
- **Composer**: Package manager for dependency resolution.
- **System Functions**: `proc_open()` enabled in `php.ini` (not blacklisted in `disable_functions`).

### Installation Command

Install the SDK via Composer:

```bash
composer require feedple/feedple-sdk
```

### Core Dependencies

| Package | Version Constraint | Purpose |
| :--- | :--- | :--- |
| `php` | `>= 8.1` | Modern PHP engine features (strict types, `readonly`, `#[\SensitiveParameter]`) |
| `react/event-loop` | `^1.5` | Non-blocking event loop for the background worker process |
| `ratchet/pawl` | `^0.4` | Asynchronous background connection client |
| `monolog/monolog` | `^3.0` | PSR-3 logging framework support |
| `psr/log` | `^3.0` | PSR-3 logger interface definitions |

---

## 3. Project Structure

The PHP SDK codebase is organized into clean, single-responsibility modules under `src/`:

```
sdk-php/
├── composer.json                 # Package metadata, requirements, and PSR-4 autoload rules
├── composer.lock                 # Locked dependency versions
├── phpunit.xml                   # PHPUnit test suite configuration
├── README.md                     # Overview & quickstart guide
├── integration_guide.md          # Multi-pattern integration guide (Vanilla & Laravel)
├── docs/
│   └── usage.md                  # Comprehensive technical documentation (this file)
└── src/
    ├── DbConfig.php              # Immutable database connection configuration builder
    ├── FeedpleSDK.php            # Main SDK client, background launcher, and orchestrator
    ├── worker.php                # Standalone background worker process entrypoint
    └── Core/
        ├── FeedpleWebSocket.php  # Persistent background connection client
        ├── Identity.php          # Security dataclass defining tenant table access rules
        ├── IrBuilder.php         # IR JSON to parameterized SQL string & params builder
        ├── JsonSerializer.php  # Safe JSON encoder handling DateTimeInterface & objects
        ├── PolicyEngine.php      # Table-level Role-Based Access Control (RBAC) engine
        ├── SchemaServices.php    # Schema introspection, hashing, and sensitive column filter
        ├── SqlCompiler.php       # Regex-based SQL table extractor & policy validator
        └── Exceptions/
            ├── AuthException.php         # Thrown when authentication handshake fails
            ├── IrExecutionException.php  # Thrown when IR query build/execution fails
            └── SchemaSyncException.php # Thrown on schema synchronization failures
```

### Module Responsibilities

- **`Feedple\Sdk\FeedpleSDK`**: Primary entrypoint class. Handles DB health verification (`SELECT 1`), PID-based worker idempotency, background worker spawning via `proc_open()`, schema sync scheduling, and query execution dispatch.
- **`Feedple\Sdk\DbConfig`**: Immutable database configuration builder providing named constructors (`mysql()`, `pgsql()`, `sqlite()`, `raw()`). Protects passwords with `#[\SensitiveParameter]`, custom `__debugInfo()`, and automatically configures MySQL `ANSI_QUOTES` mode.
- **`Feedple\Sdk\Core\Identity`**: Value object holding `$name`, `$allowedTables`, and `$allTables` flags.
- **`Feedple\Sdk\Core\PolicyEngine`**: Enforces table-level access rules via `canAccessTable()` and `validateIrAccess()`.
- **`Feedple\Sdk\Core\IrBuilder`**: Translates flat IR JSON objects into `['sql' => string, 'params' => array]` for PDO prepared statements.
- **`Feedple\Sdk\Core\SqlCompiler`**: Regex table extractor for raw SQL statements that validates table access against `PolicyEngine`.
- **`Feedple\Sdk\Core\SchemaServices`**: Introspects MySQL, PostgreSQL, and SQLite databases using `INFORMATION_SCHEMA` or `PRAGMA` queries. Computes SHA-256 schema hashes and strips sensitive columns.
- **`Feedple\Sdk\Core\FeedpleWebSocket`**: Persistent connection client managing authentication, heartbeats, chunked schema transmission (`sendSchema`), and IR request routing.
- **`Feedple\Sdk\Core\JsonSerializer`**: Encodes/decodes JSON safely, serializing `DateTimeInterface` objects to ISO 8601 (`ATOM`) strings and stripping private `_`-prefixed object properties.

---

## 4. Getting Started

### Basic Initialization Flow

1. Define a database connection using `DbConfig`.
2. Define access permissions using `Identity`.
3. Instantiate `FeedpleSDK` with your Feedple API key, `DbConfig`, and `Identity`.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

// 1. Define database configuration
$dbConfig = DbConfig::mysql(
    host:     '127.0.0.1',
    database: 'production_db',
    username: 'db_user',
    password: 'db_password',
    port:     3306,
);

// 2. Define table access permissions
$identity = new Identity(
    name:          'main-app',
    allowedTables: ['users', 'orders', 'products'],
    allTables:     false,
);

// 3. Initialize the SDK
$sdk = new FeedpleSDK(
    apiKey:   'sk_live_123456789abcdef',
    dbConfig: $dbConfig,
    identity: $identity,
);

// Returns instantly — the SDK worker runs in a background process.
```

When `new FeedpleSDK(...)` executes:
1. The SDK connects to your database via PDO and executes `SELECT 1` to verify connectivity.
2. It checks a local `.pid` file in the system temp directory (`sys_get_temp_dir()`).
3. If no active worker process exists, it writes a temporary, restricted control file (`chmod 0600`) and spawns `worker.php` via `proc_open()`.
4. The background process bootstraps the execution environment, connects to Feedple AI securely, authenticates, and begins schema synchronization.

---

## 5. Usage Guide

### 1. `FeedpleSDK`

Main orchestrator class.

#### Constructor Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `$apiKey` | `string` | **required** | Your Feedple tenant API key. Throws `\InvalidArgumentException` if empty. |
| `$dbConfig` | `DbConfig` | **required** | Connection configuration instance. |
| `$identity` | `Identity` | **required** | Security identity controlling table access. |
| `$autoloadPath` | `?string` | `null` | Path to `vendor/autoload.php`. Guessed automatically if `null`. |
| `$autoSync` | `bool` | `true` | Automatically inspect and sync schema periodically. |
| `$syncInterval` | `int` | `60` | Interval in seconds between background schema sync cycles. |
| `$reconnectEnabled` | `bool` | `true` | Automatically reconnect on connection drop. |
| `$maxRetries` | `?int` | `null` | Maximum reconnect attempts (`null` = unlimited). |
| `$probeBeforeConnect`| `bool` | `false` | Perform HTTP GET probe prior to handshake to surface 403 errors. |
| `$logger` | `?LoggerInterface`| `null` | PSR-3 logger instance. Writes to `feedple-sdk.log` if `null`. |
| `$runtimeDir` | `?string` | `null` | Directory for control/pid/log files (defaults to `sys_get_temp_dir()`). |

#### Public Methods

- **`syncSchema(): void`**:
  Manually triggers database schema inspection and transmits updated definitions over the secure connection if the SHA-256 schema hash differs.

- **`stop(): void`**:
  Terminates the background worker process using cross-platform system signals (`taskkill` on Windows, `kill -TERM` on Linux/macOS) and cleans up the `.pid` file.

- **`buildCompiler(): SqlCompiler`**:
  Returns a new `SqlCompiler` instance bound to the current `Identity` policy.

- **`log(string $level, string $message): void`**:
  Logs a message using the injected PSR-3 logger or writes to `feedple-sdk.log`.

---

### 2. `DbConfig`

Immutable database configuration builder.

```php
use Feedple\Sdk\DbConfig;

// MySQL configuration
$mysqlConfig = DbConfig::mysql(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'root',
    password: 'secret_password',
);

// PostgreSQL configuration
$pgsqlConfig = DbConfig::pgsql(
    host:     '127.0.0.1',
    database: 'myapp',
    username: 'postgres',
    password: 'secret_password',
    port:     5432,
);

// SQLite configuration (no credentials required)
$sqliteConfig = DbConfig::sqlite(
    path: '/var/www/html/database.sqlite',
);

// Custom raw PDO DSN
$rawConfig = DbConfig::raw(
    dsn:      'sqlsrv:Server=localhost;Database=mydb',
    username: 'sa',
    password: 'SecretPassword123!',
);
```

#### Key Features
- **Sensitive Parameter Protection**: `$password` is annotated with `#[\SensitiveParameter]`. PHP automatically redacts its value from stack traces and error monitoring tools (Sentry, Bugsnag, Datadog).
- **MySQL ANSI_QUOTES Support**: `DbConfig::toPdo()` executes `SET SESSION sql_mode=(SELECT CONCAT(@@sql_mode, ',ANSI_QUOTES'))` on MySQL connections, allowing double-quoted identifiers (`"users"."id"`) to execute seamlessly.
- **Redacted Serialization**: `json_encode($dbConfig)` and `var_dump($dbConfig)` mask the password as `'••••••'`.

---

### 3. `Identity`

Specifies table visibility and access rules.

```php
use Feedple\Sdk\Core\Identity;

// Whitelist specific tables
$restricted = new Identity(
    name:          'web-app',
    allowedTables: ['users', 'orders', 'products'],
    allTables:     false, // default
);

// Allow access to all current and future tables
$admin = new Identity(
    name:      'admin-full-access',
    allTables: true,
);
```

---

### 4. `IrBuilder`

Converts flat JSON IR objects into parameterized SQL strings and parameter arrays.

```php
use Feedple\Sdk\Core\IrBuilder;

$ir = [
    'operation' => 'query',
    'table'     => 'orders',
    'fields'    => [
        ['column' => 'orders.id', 'expression' => null, 'alias' => null],
        ['column' => 'orders.amount', 'expression' => 'sum', 'alias' => 'total_spent'],
    ],
    'joins' => [
        [
            'table'     => 'users',
            'on_left'   => 'orders.user_id',
            'on_right'  => 'users.id',
            'join_type' => 'INNER',
        ]
    ],
    'filters' => [
        ['column' => 'orders.status', 'operator' => 'eq', 'value' => 'completed'],
    ],
    'group_by' => ['orders.user_id'],
    'limit'    => 50,
];

['sql' => $sql, 'params' => $params] = IrBuilder::buildQueryFromIr($ir);

// $sql = 'SELECT "orders"."id", SUM("orders"."amount") AS "total_spent" FROM "orders" INNER JOIN "users" ON "orders"."user_id" = "users"."id" WHERE "orders"."status" = ? GROUP BY "orders"."user_id" LIMIT ?'
// $params = ['completed', 50]
```

#### Supported Filter Operators
- Comparison: `eq` (`=`), `neq` (`!=`), `gt` (`>`), `gte` (`>=`), `lt` (`<`), `lte` (`<=`)
- Set Inclusion: `in`, `in_`
- Pattern Matching: `like`, `ilike`

#### Supported Aggregate Functions
- `count`, `count(distinct)`, `sum`, `avg`, `min`, `max`

---

## 6. Configuration

### Parameter Reference

```php
$sdk = new FeedpleSDK(
    apiKey:             'sk_live_987654321',
    dbConfig:           $dbConfig,
    identity:           $identity,
    autoloadPath:       __DIR__ . '/vendor/autoload.php',
    autoSync:           true,
    syncInterval:       120, // 2 minutes
    reconnectEnabled:   true,
    maxRetries:         10,
    probeBeforeConnect: true,
    logger:             $customPsr3Logger,
    runtimeDir:         '/var/run/feedple',
);
```

### Logging Configuration

By default, logs are written to `feedple-sdk.log` inside `$runtimeDir`. To send logs to Monolog or custom log drivers:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('feedple');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$sdk = new FeedpleSDK(
    apiKey:   'sk_live_...',
    dbConfig: $dbConfig,
    identity: $identity,
    logger:   $logger,
);
```

---

## 7. Common Workflows

### 1. Dedicated CLI Entrypoint (`bin/start-feedple.php`)

Ideal for plain PHP or legacy applications.

```php
<?php
// bin/start-feedple.php

require __DIR__ . '/../vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

try {
    new FeedpleSDK(
        apiKey:   getenv('FEEDPLE_API_KEY') ?: 'sk_live_123',
        dbConfig: DbConfig::mysql(
            host:     getenv('DB_HOST') ?: '127.0.0.1',
            database: getenv('DB_DATABASE') ?: 'app_db',
            username: getenv('DB_USERNAME') ?: 'root',
            password: getenv('DB_PASSWORD') ?: '',
        ),
        identity: new Identity(name: 'cli-worker', allTables: true),
    );
    echo "Feedple SDK worker initialized successfully.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Feedple SDK Error: {$e->getMessage()}\n");
    exit(1);
}
```

Run via CLI or system crontab:

```bash
php bin/start-feedple.php
```

Crontab entry for server reboot auto-start:

```text
@reboot php /path/to/your/app/bin/start-feedple.php > /dev/null 2>&1
```

---

### 2. Laravel Service Provider (`AppServiceProvider`)

Integrates the SDK worker check directly into Laravel's application boot lifecycle.

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->environment('production', 'staging') && !$this->app->runningUnitTests()) {
            try {
                new FeedpleSDK(
                    apiKey:   config('services.feedple.key'),
                    dbConfig: DbConfig::mysql(
                        host:     config('database.connections.mysql.host'),
                        database: config('database.connections.mysql.database'),
                        username: config('database.connections.mysql.username'),
                        password: config('database.connections.mysql.password'),
                        port:     (int) config('database.connections.mysql.port', 3306),
                    ),
                    identity:   new Identity(name: config('app.name'), allTables: true),
                    runtimeDir: storage_path('framework/cache'),
                );
            } catch (\Throwable $e) {
                logger()->error('Feedple SDK failed to bootstrap: ' . $e->getMessage());
            }
        }
    }
}
```

---

### 3. Custom Laravel Artisan Command (`php artisan feedple:start`)

Encapsulates worker management inside a clean Artisan CLI command.

Create `app/Console/Commands/FeedpleStart.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

class FeedpleStart extends Command
{
    protected $signature   = 'feedple:start';
    protected $description = 'Verifies connection health and spawns the detached Feedple background sync worker';

    public function handle(): int
    {
        $this->info('Initializing Feedple SDK...');

        try {
            new FeedpleSDK(
                apiKey:   config('services.feedple.key'),
                dbConfig: DbConfig::mysql(
                    host:     config('database.connections.mysql.host'),
                    database: config('database.connections.mysql.database'),
                    username: config('database.connections.mysql.username'),
                    password: config('database.connections.mysql.password'),
                ),
                identity:   new Identity(name: config('app.name'), allTables: true),
                runtimeDir: storage_path('app/feedple'),
            );

            $this->info('Feedple worker active.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to launch Feedple SDK worker: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

Execute:

```bash
php artisan feedple:start
```

---

## 8. Error Handling

### Exception Hierarchy

| Exception Class | Trigger Condition | Recommended Strategy |
| :--- | :--- | :--- |
| `\InvalidArgumentException` | Empty `$apiKey`, malformed IR JSON, or invalid SQL identifier | Validate configuration input parameters |
| `\RuntimeException` | PDO connection failure (`SELECT 1` failed) or worker process spawn failure | Check database server status, host credentials, and `proc_open()` permissions |
| `Feedple\Sdk\Core\Exceptions\AuthException` | Authentication handshake error (`auth.error`) | Verify Feedple API key validity in workspace dashboard |
| `Feedple\Sdk\Core\Exceptions\IrExecutionException` | Error compiling or executing IR query | Inspect query filter formats and database column types |
| `Feedple\Sdk\Core\Exceptions\SchemaSyncException` | Schema upload transmission failure | Check network connectivity to Feedple API |

---

## 9. Best Practices

> [!TIP]
> Use storage_path('framework/cache') as runtimeDir in Laravel environments to prevent permissions issues in worker spawned processes.

### Security & Access Control
- **Sensitive Parameter Redaction**: Always use `DbConfig` rather than raw connection strings to benefit from `#[\SensitiveParameter]` protection.
- **Explicit Table Whitelisting**: Use `new Identity(allowedTables: ['table_a', 'table_b'])` in production environments instead of `allTables: true`.
- **Sensitive Columns**: `SchemaServices::SENSITIVE_COLUMNS` automatically removes `password`, `token`, `secret`, `hash`, `salt`, `ssn`, and `credit_card` columns.

### Performance & Resilience
- **PID Idempotency**: `FeedpleSDK` checks `.pid` files before spawning child processes. It is safe to invoke `new FeedpleSDK(...)` frequently without spawning duplicate processes.
- **Custom Writable `runtimeDir`**: In containerized or shared hosting environments (e.g. AWS Lambda, Laravel Vapor, Docker), specify a writable path like `storage_path('app/feedple')`.

---

## 10. Troubleshooting

> [!WARNING]
> Ensure proc_open is enabled in php.ini. If proc_open is blacklisted in disable_functions, background process spawning will throw a \RuntimeException.

### Issue 1: `Feedple: could not start background worker process`
- **Cause**: `proc_open()` is listed in `disable_functions` in `php.ini`.
- **Resolution**: Edit `php.ini` and remove `proc_open` from `disable_functions`.

### Issue 2: `Feedple SDK could not connect to the database`
- **Cause**: PDO failed initial `SELECT 1` test query.
- **Resolution**: Check database credentials in `DbConfig::mysql()` / `pgsql()`. Verify that host IP, port, database name, username, and password are correct.

### Issue 3: MySQL Syntax Errors on double-quoted identifiers
- **Cause**: MySQL running without `ANSI_QUOTES` SQL mode.
- **Resolution**: Ensure you build connection configuration via `DbConfig::mysql()`. `DbConfig` executes `SET SESSION sql_mode=(SELECT CONCAT(@@sql_mode, ',ANSI_QUOTES'))` automatically on connection creation.

---

## 11. API Reference

### `FeedpleSDK`
```php
namespace Feedple\Sdk;

class FeedpleSDK
{
    public function __construct(
        string $apiKey,
        DbConfig $dbConfig,
        Identity $identity,
        ?string $autoloadPath = null,
        bool $autoSync = true,
        int $syncInterval = 60,
        bool $reconnectEnabled = true,
        ?int $maxRetries = null,
        bool $probeBeforeConnect = false,
        ?LoggerInterface $logger = null,
        ?string $runtimeDir = null
    );

    public function syncSchema(): void;
    public function stop(): void;
    public function buildCompiler(): SqlCompiler;
    public function log(string $level, string $message): void;
    public static function runWorker(string $controlFilePath): void;
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

---

## 12. Examples

### Complete Runnable PHP Script

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\DbConfig;
use Feedple\Sdk\Core\Identity;

// 1. Create SQLite database file for testing
$dbPath = __DIR__ . '/test_app.sqlite';
$pdo = new PDO("sqlite:{$dbPath}");
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password_hash TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL)");

// 2. Configure SDK
$dbConfig = DbConfig::sqlite($dbPath);
$identity = new Identity(name: 'demo-app', allowedTables: ['users', 'orders']);

echo "Initializing Feedple SDK...\n";

$sdk = new FeedpleSDK(
    apiKey:   'sk_test_demo_key_999',
    dbConfig: $dbConfig,
    identity: $identity,
);

echo "Feedple background worker spawned successfully.\n";

// Keep process running briefly for demo purposes
sleep(3);

// 3. Stop background worker
echo "Stopping SDK worker...\n";
$sdk->stop();
echo "SDK worker stopped.\n";
```

---

## 13. Developer Notes & API Usability Recommendations

### Process Lifecycle & Control File Architecture
When `FeedpleSDK` launches a background worker:
1. It writes a temporary JSON file (`feedple-control-*.json`) to `$runtimeDir` containing the encrypted payload (`apiKey`, base64-serialized `Identity`, array `DbConfig`).
2. The control file is permissions-restricted with `chmod 0600`.
3. `worker.php` reads the control file on startup, builds independent PDO & connection handles, and calls `@unlink($controlFilePath)` immediately to erase credentials from disk.

---

### API Usability & Architectural Recommendations

During codebase analysis, three areas were identified where public API usability could be improved:

#### 1. Configurable Base Server URL & Connection Endpoint
- **Current State**: `FEEDPLE_WS_URL` and `FEEDPLE_API_BASE_URL` in `src/FeedpleSDK.php` are top-level constants driven by `const FEEDPLE_DEV_ENV = true`.
- **Usability Recommendation**: Expose a `$wsUrl` parameter in `FeedpleSDK` or read an environment variable (`FEEDPLE_WS_URL`) so developers can switch between Local, Staging, and Production environments without modifying SDK source code.

#### 2. Automatic Sensitive Column Filtering in `getSchema()`
- **Current State**: `SchemaServices::filterSensitiveColumns()` exists as a utility method, but `getSchema()` returns unfiltered column definitions.
- **Usability Recommendation**: Automatically invoke `filterSensitiveColumns()` inside `getSchema()` by default (with an optional `$filterSensitive = true` flag) to ensure sensitive columns are excluded out-of-the-box.

#### 3. AST Transpilation Clarity in `SqlCompiler`
- **Current State**: `SqlCompiler::compile()` uses regular expressions to extract table names and returns raw SQL strings unchanged because PHP lacks native AST transpilers.
- **Usability Recommendation**: Document that `IrBuilder` is the primary, recommended query execution path for multi-database environments.
