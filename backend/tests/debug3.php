<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

\PermissionService::setOperatorContext('admin_1', 'super_admin');

$db = \Dealer\Wallet\Config\Database::getConnection();

echo "=== Step 1: Before recharge ===\n";
$rows = $db->executeSelect('dealer_wallet');
echo "dealer_wallet rows: " . count($rows) . "\n";

$service = new \Dealer\Wallet\Service\WalletService();
$result = $service->recharge(113, 2000.00, ['operator' => 'admin']);
echo "Recharge done, wallet id: " . $result['id'] . "\n";

echo "=== Step 2: After recharge ===\n";
$rows = $db->executeSelect('dealer_wallet');
echo "dealer_wallet rows: " . count($rows) . "\n";
$rows2 = $db->executeSelect('dealer_wallet_transaction');
echo "dealer_wallet_transaction rows: " . count($rows2) . "\n";

$f1 = $service->freeze(113, 100.00, ['operator' => 'admin', 'reason' => 'test']);
echo "Freeze done, freeze_no: " . $f1['freeze_no'] . "\n";

echo "=== Step 3: After freeze ===\n";
$rows = $db->executeSelect('dealer_wallet');
echo "dealer_wallet rows: " . count($rows) . "\n";
$rows2 = $db->executeSelect('dealer_wallet_transaction');
echo "dealer_wallet_transaction rows: " . count($rows2) . "\n";
$rows3 = $db->executeSelect('dealer_wallet_freeze_record');
echo "dealer_wallet_freeze_record rows: " . count($rows3) . "\n";

if (count($rows3) > 0) {
    foreach ($rows3 as $row) {
        echo "  Freeze record: " . json_encode($row) . "\n";
    }
}

echo "\n=== Step 4: Check transaction with where ===\n";
echo "In transaction: " . ($db->inTransaction() ? 'yes' : 'no') . "\n";
