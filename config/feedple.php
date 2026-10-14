<?php

use Feedple\Sdk\FeedpleSDK;

return [
    /*
    |--------------------------------------------------------------------------
    | Feedple API Key
    |--------------------------------------------------------------------------
    |
    | The API key associated with your Feedple workspace (fdpl_... or sk_...).
    | Obtainable from your Feedple Cloud dashboard.
    |
    */
    'api_key' => env('FEEDPLE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Feedple Webhook Signing Secret
    |--------------------------------------------------------------------------
    |
    | The HMAC-SHA256 secret used to verify incoming webhook requests from Feedple Cloud.
    | Supports comma-separated secrets for zero-downtime secret rotation.
    | Automatically cached from Feedple Cloud via your API key during schema sync.
    |
    */
    'webhook_secret' => env('FEEDPLE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Transport Mode
    |--------------------------------------------------------------------------
    |
    | 'webhook'   - Stateless HTTP bridge endpoint (default in production).
    | 'websocket' - Persistent WebSocket background worker daemon (default in development).
    |
    | Defaults dynamically via FeedpleSDK::getDefaultTransport():
    |  - Development: 'websocket'
    |  - Production:  'webhook'
    |
    */
    'transport' => FeedpleSDK::getDefaultTransport(),

    /*
    |--------------------------------------------------------------------------
    | Webhook Bridge Path
    |--------------------------------------------------------------------------
    |
    | The relative URI path where Feedple Cloud sends signed IR query requests.
    | Defaults directly from FeedpleSDK::DEFAULT_BRIDGE_PATH ('/api/feedple/bridge').
    |
    */
    'bridge_path' => env('FEEDPLE_BRIDGE_PATH', FeedpleSDK::DEFAULT_BRIDGE_PATH),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | Feedple REST API base URL for one-shot schema synchronization.
    | Defaults dynamically from FeedpleSDK::getApiBaseUrl() (local when FEEDPLE_DEV_ENV=true,
    | otherwise production Cloud Run).
    |
    */
    'api_base_url' => env('FEEDPLE_API_BASE_URL', FeedpleSDK::getApiBaseUrl()),

    /*
    |--------------------------------------------------------------------------
    | SSL Peer Verification & Root CA Bundle
    |--------------------------------------------------------------------------
    |
    | Whether to verify SSL certificates when communicating with Feedple Cloud.
    | Set to false in local development if running on Windows (Apache/XAMPP)
    | without curl.cainfo configured.
    |
    | Optional custom CA root bundle path (FEEDPLE_CA_BUNDLE). If omitted, the SDK
    | auto-detects system stores and uses its bundled Mozilla CA bundle.
    |
    */
    'verify_ssl' => env('FEEDPLE_VERIFY_SSL', true),
    'ca_bundle'  => env('FEEDPLE_CA_BUNDLE'),
];
