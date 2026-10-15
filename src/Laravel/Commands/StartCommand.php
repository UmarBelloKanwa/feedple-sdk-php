<?php

namespace Feedple\Sdk\Laravel\Commands;

use Illuminate\Console\Command;
use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\Laravel\FeedpleServiceProvider;

class StartCommand extends Command
{
    protected $signature = 'feedple:start {--tail : Stream worker logs in real time} {--force : Force start WebSocket worker even if transport is webhook}';
    protected $description = 'Start the Feedple background worker daemon (WebSocket dev mode)';

    public function handle(): int
    {
        $transport = config('feedple.transport') ?? FeedpleSDK::getDefaultTransport();

        if ($transport === 'webhook' && !$this->option('force')) {
            $bridgePath = config('feedple.bridge_path') ?? env('FEEDPLE_BRIDGE_PATH', FeedpleSDK::DEFAULT_BRIDGE_PATH);
            $this->warn('Notice: Your application is configured for Webhook mode (FEEDPLE_TRANSPORT=webhook).');
            $this->line('  → Webhook mode is stateless and does not use a background worker daemon.');
            $this->line("  → Your HTTP bridge is active at: <info>{$bridgePath}</info>");
            $this->line('  → To sync your database schema, run: <comment>php artisan feedple:sync</comment>');
            $this->line('  → To force start a WebSocket daemon anyway, run: <comment>php artisan feedple:start --force</comment>');
            return Command::SUCCESS;
        }

        $this->info('Initializing Feedple SDK in WebSocket mode...');

        try {
            $sdk = FeedpleServiceProvider::makeSdk('websocket');

            if (!$sdk) {
                $this->error('FEEDPLE_API_KEY is not configured in .env file.');
                return Command::FAILURE;
            }

            $this->info('Feedple worker started (or already active).');

            $logFile = sys_get_temp_dir() . '/feedple-sdk.log';
            $this->line("<comment>Log File:</comment> {$logFile}");

            if ($this->option('tail')) {
                if (!file_exists($logFile)) {
                    touch($logFile);
                }
                $this->info("Tailing {$logFile} (Press Ctrl+C to stop)...");
                passthru("tail -f " . escapeshellarg($logFile));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to start Feedple worker: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
