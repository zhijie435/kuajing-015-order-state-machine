<?php

if (php_sapi_name() !== 'cli') {
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASE_PATH', __DIR__);
define('DS', DIRECTORY_SEPARATOR);

require_once BASE_PATH . '/tests/MockDatabase.php';

class_alias('MockDatabase', 'Dealer\\Wallet\\Config\\Database');

require_once BASE_PATH . '/src/Enum/WalletStatus.php';
require_once BASE_PATH . '/src/Enum/FreezeStatus.php';
require_once BASE_PATH . '/src/Enum/TransactionType.php';
require_once BASE_PATH . '/src/Exception/WalletException.php';
require_once BASE_PATH . '/src/Exception/InsufficientBalanceException.php';
require_once BASE_PATH . '/src/Exception/WalletStateException.php';
require_once BASE_PATH . '/src/Exception/WalletPermissionException.php';
require_once BASE_PATH . '/src/Model/Wallet.php';
require_once BASE_PATH . '/src/Model/FreezeRecord.php';
require_once BASE_PATH . '/src/Model/Transaction.php';
require_once BASE_PATH . '/src/Repository/WalletRepository.php';
require_once BASE_PATH . '/src/Repository/FreezeRecordRepository.php';
require_once BASE_PATH . '/src/Repository/TransactionRepository.php';
require_once BASE_PATH . '/src/StateMachine/WalletStateMachine.php';
require_once BASE_PATH . '/src/Service/ReconciliationService.php';
require_once BASE_PATH . '/src/Service/WalletService.php';

require_once BASE_PATH . '/core/PermissionService.php';
require_once BASE_PATH . '/core/OrderNoGenerator.php';

use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Enum\TransactionType;

MockDatabase::getInstance()->clearAll();
PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);

$db = MockDatabase::getInstance();

echo "=== Test 1: Insert transaction directly via repo ===\n";
$txRepo = new \Dealer\Wallet\Repository\TransactionRepository();
$txId = $txRepo->create([
    'wallet_id' => 1,
    'dealer_id' => 1001,
    'type' => TransactionType::RECHARGE,
    'amount' => 500.0,
    'balance_before' => 0,
    'balance_after' => 500.0,
    'frozen_before' => 0,
    'frozen_after' => 0,
    'related_no' => 'TEST123',
    'operator' => 'admin',
    'remark' => 'test',
]);
echo "Inserted tx id: {$txId}\n";

// Now let's use reflection to look at the tables
$reflection = new \ReflectionClass($db);
$prop = $reflection->getProperty('tables');
$prop->setAccessible(true);
$tables = $prop->getValue($db);
echo "Tables: " . json_encode(array_keys($tables)) . "\n";
echo "dealer_wallet_transaction count: " . count($tables['dealer_wallet_transaction'] ?? []) . "\n";
if (isset($tables['dealer_wallet_transaction'])) {
    echo "First tx row: " . json_encode($tables['dealer_wallet_transaction'][0]) . "\n";
}

echo "\n=== Test 2: findByWalletId ===\n";
$result = $txRepo->findByWalletId(1, 1, 10);
echo "findByWalletId(1) count: " . count($result) . "\n";
var_dump($result);

echo "\n=== Test 3: Check SQL for findByWalletId ===\n";
echo "Last SQL: " . $db->lastSql . "\n";
echo "Last Params: " . json_encode($db->lastParams) . "\n";
