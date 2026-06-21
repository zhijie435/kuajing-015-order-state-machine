<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

\PermissionService::setOperatorContext('admin_1', 'super_admin');
$service = new \Dealer\Wallet\Service\WalletService();
$result = $service->recharge(113, 2000.00, ['operator' => 'admin']);
$walletId = $result['id'];
echo "Wallet ID: " . $walletId . "\n";

$f1 = $service->freeze(113, 100.00, ['operator' => 'admin', 'reason' => 'test']);
echo "Freeze no: " . $f1['freeze_no'] . "\n";
echo "Freeze result: " . json_encode($f1) . "\n";

$db = \Dealer\Wallet\Config\Database::getConnection();
$table = 'dealer_wallet_freeze_record';
echo "Table exists: " . (isset($db->tables[$table]) ? 'yes' : 'no') . "\n";
if (isset($db->tables[$table])) {
    echo "Row count: " . count($db->tables[$table]) . "\n";
    foreach ($db->tables[$table] as $idx => $row) {
        echo "Row[$idx]: " . json_encode($row) . "\n";
    }
}

$repo = new \Dealer\Wallet\Repository\FreezeRecordRepository();
$records = $repo->findAllByWalletId($walletId);
echo "findAllByWalletId count: " . count($records) . "\n";

$sql = "SELECT * FROM dealer_wallet_freeze_record WHERE wallet_id = :wallet_id ORDER BY id ASC";
$stmt = $db->prepare($sql);
$stmt->bindValue(':wallet_id', $walletId, \PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
echo "Direct query count: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo "  Row: " . json_encode($row) . "\n";
}
