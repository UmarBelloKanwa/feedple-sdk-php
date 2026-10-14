<?php

namespace Feedple\Sdk\Laravel\Commands;

use Illuminate\Console\Command;
use Feedple\Sdk\Laravel\FeedpleServiceProvider;

class StartCommand extends Command
{
    protected $signature = 'feedple:start {--tail : Stream worker logs in real time}';
    protected $description = 'Start the Feedple background worker daemon (WebSocket dev mode)';

    public function handle(): int
    {
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
