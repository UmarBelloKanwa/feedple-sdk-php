```python
import os

markdown_content = """# Feedple SDK Integration Guide: Process Architecture & Lifecycle Management

This guide documents how the Feedple SDK initializes and runs its background worker process, followed by the three primary integration patterns available for PHP and Laravel applications.

---

## ⚙️ How the SDK Works Under the Hood

Regardless of where you place the initialization code, the Feedple SDK follows an identical lifecycle to establish its persistent background sync worker:

1. **Autoloading**: Loading Composer's autoloader (`vendor/autoload.php`) makes the `FeedpleSDK`, `DbConfig`, and `Identity` classes resolvable.
2. **Initialization**: When `new FeedpleSDK(...)` executes in the current PHP process (a web request or a CLI command), the constructor runs synchronously to perform initial health checks:
   - Validates that the provided API key is formatted correctly and not empty.
   - Builds a temporary `\PDO` connection using your `DbConfig` and executes `SELECT 1` to ensure the database is reachable. This guarantees immediate, loud failures if your configuration is wrong.
   - Checks a process ID (`.pid`) file inside your runtime directory (`sys_get_temp_dir()` by default) to see if a worker from a previous execution is already alive and active.
3. **Worker Spawning**: If no healthy worker process is detected:
   - The SDK writes a small, temporary JSON control file to the runtime directory containing the encrypted configuration payload.
   - It executes `proc_open()` to fork a completely detached, asynchronous background PHP process running `worker.php` (packaged inside the SDK vendor folder).
   - The main process captures the background worker's PID, saves it to the local `.pid` file, and **immediately returns control to your application**. It does not wait for the worker to begin syncing.
4. **Persistent Execution**: 
   - Your original script or web request finishes its lifecycle normally and terminates (e.g., serving the HTTP response to the client).
   - Meanwhile, the detached background `worker.php` process bootstraps independently. It reads the control file, establishes its own long-lived database connection, establishes a persistent WebSocket connection to Feedple, and initiates a non-blocking **ReactPHP event loop**. 
   - This event loop runs indefinitely, handling real-time schema syncing and inbound query requests from the Feedple platform.
5. **Idempotency**: On subsequent requests or commands, the constructor's `.pid` check finds the existing, healthy background worker process. The SDK skips the spawning phase entirely, making the execution a cheap check that prevents duplicate worker processes.

### ⚠️ System Prerequisites
Before choosing an integration path, ensure your server environment complies with the following:
* **Function Permissions**: `proc_open()` must not be blacklisted in your `php.ini`. (Verify with: `php -i | grep disable_functions`).
* **Storage Permissions**: The runtime directory must be writable by the user running PHP. If the default system temp directory is restricted, explicitly configure a custom directory using the `runtimeDir` parameter:

```

```text
File written successfully.

```php
  runtimeDir: storage_path('app/feedple')

```

---

## 🚀 Integration Methods

Choose **one** of the three integration methods below based on your application architecture.

### Method 1: Dedicated CLI Entry Point (`php bin/start-feedple.php`)

Ideal for vanilla/legacy PHP applications lacking a unified front-controller routing layout, or for deployments where background services are strictly decoupled from web-request pipelines.

Create a standalone executable script inside your project workspace (e.g., `bin/start-feedple.php`):

```php
<?php
// bin/start-feedple.php

require __DIR__ . '/../vendor/autoload.php';

use Feedple\\Sdk\\FeedpleSDK;
use Feedple\\Sdk\\DbConfig;
use Feedple\\Sdk\\Core\\Identity;

try {
    new FeedpleSDK(
        apiKey:   'sk_live_...',
        dbConfig: DbConfig::mysql(
            host:     '127.0.0.1', 
            database: 'vtu_app', 
            username: 'db_user', 
            password: 'db_password'
        ),
        identity: new Identity(name: 'vtu_app', allTables: true),
    );
    echo "Feedple SDK: Background initialization completed successfully.\\n";
} catch (\\Throwable $e) {
    fprintf(STDERR, "Feedple SDK Initialization Error: %s\\n", $e->getMessage());
    exit(1);
}

```

#### Execution & Maintenance

Run the script manually from your terminal to kick off the worker:

```bash
php bin/start-feedple.php

```

To ensure the worker auto-starts when the server cycles, append a directive to your system crontab (`crontab -e`):

```text
@reboot php /path/to/your/app/bin/start-feedple.php > /dev/null 2>&1

```

---

### Method 2: Laravel Service Provider (`AppServiceProvider`)

Best for monolithic Laravel applications where you want the Feedple SDK background check integrated directly into the application's global boot lifecycle. This ensures that any standard web request automatically ensures a worker is alive.

Add the initialization logic inside your `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\\Providers;

use Illuminate\\Support\\ServiceProvider;
use Feedple\\Sdk\\FeedpleSDK;
use Feedple\\Sdk\\DbConfig;
use Feedple\\Sdk\\Core\\Identity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only boot Feedple when running in appropriate environments 
        // and when the application is not running in a testing environment.
        if ($this->app->environment('production', 'staging') && !$this->app->runningUnitTests()) {
            try {
                new FeedpleSDK(
                    apiKey:   config('services.feedple.key'),
                    dbConfig: DbConfig::mysql(
                        host:     config('database.connections.mysql.host'),
                        database: config('database.connections.mysql.database'),
                        username: config('database.connections.mysql.username'),
                        password: config('database.connections.mysql.password')
                    ),
                    identity: new Identity(name: config('app.name'), allTables: true),
                    runtimeDir: storage_path('framework/cache'), // Custom writeable directory
                );
            } catch (\\Throwable $e) {
                // Log the exception safely without crashing the client request lifecycle
                logger()->error('Feedple SDK failed to bootstrap: ' . $e->getMessage());
            }
        }
    }
}

```

*Note: Extracting raw values into `config/services.php` and `.env` files is highly recommended to protect sensitive operational credentials.*

---

### Method 3: Custom Laravel Artisan Command (`FeedpleStart`)

The preferred method for modern Laravel architectures. It encapsulates the daemon control inside an Artisan CLI command, providing an explicit boundary between web workers and infrastructure services.

#### Step 1: Generate the Artisan Command

```bash
php artisan make:command FeedpleStart

```

#### Step 2: Implement the Command Logic

Open `app/Console/Commands/FeedpleStart.php` and configure it to initialize the SDK environment:

```php
<?php

namespace App\\Console\\Commands;

use Illuminate\\Console\\Command;
use Feedple\\Sdk\\FeedpleSDK;
use Feedple\\Sdk\\DbConfig;
use Feedple\\Sdk\\Core\\Identity;

class FeedpleStart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feedple:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifies connection health and spawns the detached Feedple background sync worker';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Initializing Feedple SDK handshake...');

        try {
            new FeedpleSDK(
                apiKey:   config('services.feedple.key'),
                dbConfig: DbConfig::mysql(
                    host:     config('database.connections.mysql.host'),
                    database: config('database.connections.mysql.database'),
                    username: config('database.connections.mysql.username'),
                    password: config('database.connections.mysql.password')
                ),
                identity: new Identity(name: config('app.name'), allTables: true),
                runtimeDir: storage_path('app/feedple'),
            );

            $this->info('Feedple background sync worker validated or successfully spawned.');
            return Command::SUCCESS;
            
        } catch (\\Throwable $e) {
            $this->error('Failed to launch Feedple SDK worker: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

```

#### Execution & Maintenance

Run the command directly from your deployment pipeline or terminal interface:

```bash
php artisan feedple:start

```

For zero-maintenance runtime persistence across server lifecycle restarts, add a cron workspace hook pointing to the Artisan script runner:

```text
@reboot cd /path/to/your/laravel-app && php artisan feedple:start > /dev/null 2>&1

```

"""

with open("feedple_integration_guide.md", "w") as f:
f.write(markdown_content)
print("File written successfully.")
