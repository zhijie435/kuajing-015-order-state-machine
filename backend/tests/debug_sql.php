<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

$db = \Dealer\Wallet\Config\Database::getConnection();

$sql1 = "INSERT INTO test1 (name, value) VALUES ('test', 123)";
$stmt1 = $db->prepare($sql1);
$stmt1->execute();
echo "Test1 (single line) - rows: " . count($db->executeSelect('test1')) . "\n";

$sql2 = "INSERT INTO test2 
(name, value) 
VALUES ('test', 456)";
$stmt2 = $db->prepare($sql2);
$stmt2->execute();
echo "Test2 (multi line) - rows: " . count($db->executeSelect('test2')) . "\n";

$sql3 = "INSERT INTO dealer_wallet_freeze_record 
(wallet_id, dealer_id, freeze_no, amount, remaining_amount, status, 
 reason, expired_at, operator) 
VALUES (1, 101, 'FZ001', 100.00, 100.00, 1, 
        'test reason', NULL, 'admin')";
$stmt3 = $db->prepare($sql3);
$stmt3->execute();
echo "Test3 (freeze record style) - rows: " . count($db->executeSelect('dealer_wallet_freeze_record')) . "\n";

$rows = $db->executeSelect('dealer_wallet_freeze_record');
foreach ($rows as $row) {
    echo "  Row: " . json_encode($row) . "\n";
}
