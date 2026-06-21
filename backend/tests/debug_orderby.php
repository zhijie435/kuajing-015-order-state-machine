<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

$db = \Dealer\Wallet\Config\Database::getConnection();

$sql1 = "INSERT INTO test_order (id, name, amount) VALUES (1, 'order1', 100.00)";
$db->query($sql1);
$sql2 = "INSERT INTO test_order (id, name, amount) VALUES (2, 'order2', 200.00)";
$db->query($sql2);
$sql3 = "INSERT INTO test_order (id, name, amount) VALUES (3, 'order3', 300.00)";
$db->query($sql3);

echo "=== Test 1: SELECT without ORDER BY ===\n";
$stmt = $db->prepare("SELECT * FROM test_order");
$stmt->execute();
$rows = $stmt->fetchAll();
echo "Rows: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  " . json_encode($r) . "\n";
}

echo "\n=== Test 2: SELECT with ORDER BY id ASC ===\n";
$stmt = $db->prepare("SELECT * FROM test_order ORDER BY id ASC");
$stmt->execute();
$rows = $stmt->fetchAll();
echo "Rows: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  " . json_encode($r) . "\n";
}

echo "\n=== Test 3: SELECT with WHERE and ORDER BY ===\n";
$stmt = $db->prepare("SELECT * FROM test_order WHERE id > 1 ORDER BY id DESC");
$stmt->execute();
$rows = $stmt->fetchAll();
echo "Rows: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  " . json_encode($r) . "\n";
}

echo "\n=== Test 4: SELECT with WHERE ===\n";
$stmt = $db->prepare("SELECT * FROM test_order WHERE id = :id");
$stmt->bindValue(':id', 2, \PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
echo "Rows: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  " . json_encode($r) . "\n";
}
