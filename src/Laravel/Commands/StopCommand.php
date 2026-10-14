<?php

namespace Feedple\Sdk\Laravel\Commands;

use Illuminate\Console\Command;
use Feedple\Sdk\Laravel\FeedpleServiceProvider;

class StopCommand extends Command
{
    protected $signature = 'feedple:stop';
    protected $description = 'Stop the Feedple background worker process';

    public function handle(): int
    {
        $this->info('Stopping Feedple background worker...');

        try {
            $sdk = FeedpleServiceProvider::makeSdk('websocket');
            if ($sdk) {
                $sdk->stop();
            }

            $pidFile = sys_get_temp_dir() . '/feedple-sdk.pid';
            if (file_exists($pidFile)) {
                $pid = (int) trim((string) file_get_contents($pidFile));
                if ($pid > 0) {
                    exec("kill -9 {$pid} 2>/dev/null");
                }
                @unlink($pidFile);
            }

            $this->info('Feedple background worker stopped.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to stop Feedple worker: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
