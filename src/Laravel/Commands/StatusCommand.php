<?php

namespace Feedple\Sdk\Laravel\Commands;

use Illuminate\Console\Command;
use Feedple\Sdk\FeedpleSDK;

class StatusCommand extends Command
{
    protected $signature = 'feedple:status {--lines=15 : Number of log lines to show}';
    protected $description = 'Check the status of the Feedple background worker and view recent logs';

    public function handle(): int
    {
        $this->info('--- Feedple Worker Status ---');

        $apiKey = config('feedple.api_key') ?? env('FEEDPLE_API_KEY');
        if (!$apiKey || $apiKey === 'sk_live_your_actual_api_key' || $apiKey === 'sk_live_your_workspace_api_key_here') {
            $this->warn('API Key: Not configured or using placeholder in .env');
        } else {
            $maskedKey = substr((string) $apiKey, 0, 7) . '...' . substr((string) $apiKey, -4);
            $this->line("<comment>API Key:</comment> {$maskedKey}");
        }

        $transport = config('feedple.transport') ?? FeedpleSDK::getDefaultTransport();
        $this->line("<comment>Configured Transport:</comment> {$transport}");

        $defaultConn = config('database.default', env('DB_CONNECTION', 'sqlite'));
        $this->line("<comment>Database Connection:</comment> {$defaultConn}");

        $tempDir = sys_get_temp_dir();
        $pidFile = $tempDir . '/feedple-sdk.pid';
        $logFile = $tempDir . '/feedple-sdk.log';

        $isRunning = false;
        $pid = null;

        if (file_exists($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $out = shell_exec(sprintf('tasklist /FI "PID eq %d" 2>NUL', $pid));
                    $isRunning = $out !== null && str_contains($out, (string) $pid);
                } else {
                    exec(sprintf('kill -0 %d 2>/dev/null', $pid), result_code: $exitCode);
                    $isRunning = ($exitCode === 0);
                }
            }
        }

        if ($transport === 'webhook') {
            $bridgePath = config('feedple.bridge_path') ?? env('FEEDPLE_BRIDGE_PATH', FeedpleSDK::DEFAULT_BRIDGE_PATH);
            $appUrl = config('app.url') ?? env('APP_URL', '');
            $bridgeUrl = !empty($appUrl) ? rtrim($appUrl, '/') . '/' . ltrim($bridgePath, '/') : $bridgePath;
            $this->info("Bridge Status: ACTIVE (Stateless HTTP endpoint: {$bridgeUrl})");
            $this->line("  → No background daemon required in Webhook mode. Apache/Nginx routes incoming queries to POST {$bridgePath}.");
        } else {
            if ($isRunning && $pid) {
                $this->info("Worker Status: RUNNING (PID: {$pid})");
            } else {
                $this->warn("Worker Status: STOPPED (Start it with: php artisan feedple:start)");
            }
        }

        $this->line("<comment>Log File:</comment> {$logFile}");

        if (file_exists($logFile)) {
            $lines = max(1, (int) $this->option('lines'));
            $this->line("<comment>Recent Logs (last {$lines} lines):</comment>");
            $content = file($logFile);
            if ($content !== false && count($content) > 0) {
                $slice = array_slice($content, -$lines);
                foreach ($slice as $logLine) {
                    $this->output->write($logLine);
                }
            } else {
                $this->line('  (log file is empty)');
            }
        } else {
            $this->line('  (no log file found yet)');
        }

        return Command::SUCCESS;
    }
}
