<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Core\UpdateManager;

$updateManager = new UpdateManager();
$updateManager->saveLastUpdateId(0);

echo "✅ Bot reset successfully! All previous updates ignored.\n";
echo "🚀 Now run test_bot_once.php again\n";