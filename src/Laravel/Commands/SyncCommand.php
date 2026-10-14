<?php

namespace Feedple\Sdk\Laravel\Commands;

use Illuminate\Console\Command;
use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\Laravel\FeedpleServiceProvider;

class SyncCommand extends Command
{
    protected $signature = 'feedple:sync {--force : Force schema re-sync even if hash matches} {--bridge-url= : Override the webhook bridge URL to register with Feedple Cloud}';
    protected $description = 'Perform a one-shot database schema synchronization to Feedple Cloud over HTTPS';

    public function handle(): int
    {
        $this->info('Starting Feedple schema synchronization...');

        try {
            $sdk = FeedpleServiceProvider::makeSdk();

            if (!$sdk) {
                $this->error('FEEDPLE_API_KEY is not configured in .env file.');
                return Command::FAILURE;
            }

            $apiBaseUrl = config('feedple.api_base_url') ?? FeedpleSDK::getApiBaseUrl();
            $transportMode = $sdk->getTransport();
            $isLocalBackend = FeedpleSDK::isLocalUrl((string) $apiBaseUrl);
            $this->line('<comment>Feedple Backend:</comment> ' . ($isLocalBackend ? 'Local dev server' : 'Feedple Cloud (production)'));
            $this->line('<comment>Transport:</comment> ' . ucfirst($transportMode) . ($transportMode === 'websocket' ? ' (outbound worker)' : ' (inbound HTTP bridge)'));
            $this->line('<comment>API Endpoint:</comment> ' . rtrim($apiBaseUrl, '/') . '/tenants/schema');

            $requested = strtolower((string) (getenv('FEEDPLE_TRANSPORT') ?: ($_ENV['FEEDPLE_TRANSPORT'] ?? '')));
            if ($requested === 'webhook' && $transportMode === 'websocket') {
                $this->warn('Notice: FEEDPLE_TRANSPORT=webhook was ignored — Feedple Cloud cannot reach a local APP_URL. Using WebSocket instead.');
            }

            $force = (bool) $this->option('force');
            $customBridgeUrl = $this->option('bridge-url');

            // Auto-resolve Webhook Bridge URL
            $bridgeUrl = null;
            if (!empty($customBridgeUrl)) {
                $bridgeUrl = (string) $customBridgeUrl;
            } elseif ($transportMode === 'webhook') {
                $bridgePath = config('feedple.bridge_path') ?? env('FEEDPLE_BRIDGE_PATH', FeedpleSDK::DEFAULT_BRIDGE_PATH);
                $bridgeUrl = FeedpleSDK::detectWebhookUrl($bridgePath);

                if (empty($bridgeUrl)) {
                    $appUrl = config('app.url') ?? env('APP_URL', '');
                    if (!empty($appUrl) && filter_var($appUrl, FILTER_VALIDATE_URL)) {
                        $isLocalApp = FeedpleSDK::isLocalUrl((string) $appUrl);
                        $isRemoteApi = !FeedpleSDK::isLocalUrl((string) $apiBaseUrl);

                        if ($isLocalApp && $isRemoteApi) {
                            $this->warn('Notice: APP_URL is local (' . $appUrl . ') and cannot receive webhooks from Feedple Cloud.');
                            $this->line('  → Syncing schema without registering local webhook URL.');
                            $this->line('  → Tip: In development, Feedple defaults to WebSocket mode. Run: php artisan feedple:start');
                            $bridgeUrl = null;
                        } else {
                            $bridgeUrl = rtrim((string) $appUrl, '/') . '/' . ltrim((string) $bridgePath, '/');
                        }
                    }
                }
            } else {
                $this->line('  → Transport is WebSocket: no webhook URL required.');
                $this->line('  → Start the background worker daemon with: <comment>php artisan feedple:start</comment>');
            }

            if (!empty($bridgeUrl)) {
                $this->line('<comment>Bridge URL:</comment> ' . $bridgeUrl);
            }

            $startTime = microtime(true);
            $result = $sdk->syncSchemaHttp(apiBaseUrl: $apiBaseUrl, force: $force, webhookUrl: $bridgeUrl);
            $elapsed = round(microtime(true) - $startTime, 2);

            if ($result['skipped']) {
                $this->info("Schema is up-to-date (unchanged). Verified {$result['tables_count']} tables in {$elapsed}s.");
            } else {
                $this->info("Schema synchronized successfully! Version: {$result['schema_version']} ({$result['tables_count']} tables) in {$elapsed}s.");
            }

            if (!empty($bridgeUrl) && !empty($result['registered_webhook_url'])) {
                $this->line("<info>✓ Registered Webhook Bridge:</info> {$result['registered_webhook_url']}");
            }

            if (!empty($result['webhook_secret'])) {
                $existingSecret = config('feedple.webhook_secret') ?? env('FEEDPLE_WEBHOOK_SECRET');
                if (empty($existingSecret) || $existingSecret === 'whsec_your_webhook_signing_secret_here') {
                    $envPath = function_exists('base_path') ? base_path('.env') : (getcwd() . '/.env');
                    $autoUpdated = false;
                    if (file_exists($envPath) && is_writable($envPath)) {
                        $envContent = (string) file_get_contents($envPath);
                        if (str_contains($envContent, 'FEEDPLE_WEBHOOK_SECRET=')) {
                            $envContent = (string) preg_replace(
                                '/^FEEDPLE_WEBHOOK_SECRET=.*$/m',
                                'FEEDPLE_WEBHOOK_SECRET=' . $result['webhook_secret'],
                                $envContent
                            );
                        } else {
                            $envContent .= "\nFEEDPLE_WEBHOOK_SECRET=" . $result['webhook_secret'] . "\n";
                        }
                        @file_put_contents($envPath, $envContent);
                        $autoUpdated = true;
                    }

                    if ($autoUpdated) {
                        $this->line("<info>✓ Saved Webhook Secret to .env:</info> {$result['webhook_secret']}");
                    } else {
                        $this->line("<info>✓ Webhook Secret configured & cached automatically.</info>");
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to synchronize schema: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
