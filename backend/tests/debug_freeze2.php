<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

\PermissionService::setOperatorContext('admin_1', 'super_admin');
$service = new \Dealer\Wallet\Service\WalletService();
$result = $service->recharge(113, 2000.00, ['operator' => 'admin']);
$walletId = $result['id'];
echo "Wallet ID: " . $walletId . "\n";

$db = \Dealer\Wallet\Config\Database::getConnection();

echo "=== Before freeze ===\n";
echo "Tables: " . json_encode(array_keys($db->tables)) . "\n";

$f1 = $service->freeze(113, 100.00, ['operator' => 'admin', 'reason' => 'test']);
echo "Freeze no: " . $f1['freeze_no'] . "\n";

echo "=== After freeze ===\n";
echo "Tables: " . json_encode(array_keys($db->tables)) . "\n";

$walletTable = 'dealer_wallet';
echo "Wallet table rows: " . (isset($db->tables[$walletTable]) ? count($db->tables[$walletTable]) : 0) . "\n";

$freezeTable = 'dealer_wallet_freeze_record';
echo "Freeze table exists: " . (isset($db->tables[$freezeTable]) ? 'yes' : 'no') . "\n";
if (isset($db->tables[$freezeTable])) {
    echo "Freeze table rows: " . count($db->tables[$freezeTable]) . "\n";
    foreach ($db->tables[$freezeTable] as $idx => $row) {
        echo "  Row[$idx]: " . json_encode($row) . "\n";
    }
}

$txTable = 'dealer_wallet_transaction';
echo "Transaction table exists: " . (isset($db->tables[$txTable]) ? 'yes' : 'no') . "\n";
if (isset($db->tables[$txTable])) {
    echo "Transaction table rows: " . count($db->tables[$txTable]) . "\n";
}

echo "\n=== Testing direct insert ===\n";
$sql = "INSERT INTO test_table (name, value) VALUES ('test', 123)";
$stmt = $db->prepare($sql);
$stmt->execute();
echo "Test table exists: " . (isset($db->tables['test_table']) ? 'yes' : 'no') . "\n";
if (isset($db->tables['test_table'])) {
    echo "Test table rows: " . count($db->tables['test_table']) . "\n";
}
