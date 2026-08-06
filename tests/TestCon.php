<?php
require __DIR__ . '/../vendor/autoload.php';
use Feedple\Sdk\FeedpleSDK;
use Feedple\Sdk\Core\Identity;
$pdo = new \PDO('sqlite::memory:');
$sdk = new FeedpleSDK(
    apiKey: 'test_key',
    db: $pdo,
    identity: new Identity(name: 'admin', allTables: true),
);
// Optional: Add a timer to automatically stop the SDK after 5 seconds so it doesn't run forever
React\EventLoop\Loop::get()->addTimer(5.0, function () use ($sdk) {
    echo "Stopping SDK...\n";
    $sdk->stop();
});
echo "Starting SDK...\n";
$sdk->run(); // This will connect to ws://localhost:8000 and hit your FastAPI server
