<?php

declare(strict_types=1);

/**
 * Entry point for the Feedple SDK background worker process.
 *
 * Spawned by FeedpleSDK::startBackgroundWorker() via proc_open() — never
 * run this file directly except for debugging. Expects exactly one CLI
 * argument: the path to a JSON control file written by the SDK.
 *
 * This is a genuinely separate PHP process (not a fork), so it bootstraps
 * its own Composer autoloader based on the path recorded in the control
 * file, then hands off to FeedpleSDK::runWorker() to build everything
 * (WebSocket client, DB connection via the connector script, event loop)
 * and run indefinitely.
 */

$controlFilePath = $argv[1] ?? null;

if ($controlFilePath === null || !is_file($controlFilePath)) {
    fwrite(STDERR, "Feedple worker: missing or invalid control file path\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($controlFilePath), true);

if (!is_array($config) || !isset($config['autoload_path'])) {
    fwrite(STDERR, "Feedple worker: control file is malformed\n");
    exit(1);
}

if (!is_file($config['autoload_path'])) {
    fwrite(STDERR, "Feedple worker: autoload path '{$config['autoload_path']}' not found\n");
    exit(1);
}

require $config['autoload_path'];

use Feedple\Sdk\FeedpleSDK;

try {
    FeedpleSDK::runWorker($controlFilePath);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Feedple worker crashed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}