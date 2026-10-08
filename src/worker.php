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

// Execution & Memory Safety (CLI 24/7 continuous operation)
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

// Detach into independent session leader so parent exit / terminal close never terminates worker
if (function_exists('posix_setsid')) {
    @posix_setsid();
}

// Ignore SIGHUP so closing the terminal / session doesn't kill the worker
if (function_exists('pcntl_signal')) {
    if (function_exists('pcntl_async_signals')) {
        @pcntl_async_signals(true);
    }
    @pcntl_signal(SIGHUP, SIG_IGN);
}

$controlFilePath = $argv[1] ?? null;
$config = null;
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'feedple-sdk.log';

// Global Fatal Crash Trap: Catches fatal errors, OOM, and execution timeouts
register_shutdown_function(function () use (&$config, &$logFile, $controlFilePath): void {
    if ($controlFilePath !== null && is_file($controlFilePath)) {
        @unlink($controlFilePath);
    }

    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $targetLog = (is_array($config) && !empty($config['log_path']))
            ? $config['log_path']
            : ((is_array($config) && !empty($config['runtime_dir']))
                ? $config['runtime_dir'] . DIRECTORY_SEPARATOR . 'feedple-sdk.log'
                : $logFile);

        $entry = sprintf(
            "[%s] CRITICAL: Feedple worker fatal crash: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        @file_put_contents($targetLog, $entry, FILE_APPEND | LOCK_EX);
        fwrite(STDERR, $entry);
    }
});

if ($controlFilePath === null || !is_file($controlFilePath)) {
    $msg = "Feedple worker: missing or invalid control file path\n";
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $msg, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $msg);
    exit(1);
}

$rawConfig = (string) file_get_contents($controlFilePath);
$config = json_decode($rawConfig, true);

if (!is_array($config) || !isset($config['autoload_path'])) {
    $msg = "Feedple worker: control file is malformed\n";
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $msg, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $msg);
    exit(1);
}

if (!empty($config['log_path'])) {
    $logFile = $config['log_path'];
} elseif (!empty($config['runtime_dir'])) {
    $logFile = $config['runtime_dir'] . DIRECTORY_SEPARATOR . 'feedple-sdk.log';
}

if (!is_file($config['autoload_path'])) {
    $msg = "Feedple worker: autoload path '{$config['autoload_path']}' not found\n";
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $msg, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $msg);
    exit(1);
}

require $config['autoload_path'];

use Feedple\Sdk\FeedpleSDK;

try {
    FeedpleSDK::runWorker($controlFilePath);
} catch (\Throwable $e) {
    if ($controlFilePath !== null && is_file($controlFilePath)) {
        @unlink($controlFilePath);
    }

    $entry = sprintf(
        "[%s] CRITICAL: Feedple worker uncaught exception: %s\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getTraceAsString()
    );
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $entry);
    exit(1);
}