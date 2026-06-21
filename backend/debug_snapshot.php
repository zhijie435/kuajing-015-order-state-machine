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

$svc = new WalletService();
$result = $svc->recharge(1001, 500.0, ['operator' => 'admin']);

echo "=== Result keys ===\n";
var_dump(array_keys($result));

echo "\n=== recent_transactions ===\n";
var_dump($result['recent_transactions'] ?? 'NOT SET');

echo "\n=== DB tables content ===\n";
$db = MockDatabase::getInstance();
var_dump(array_keys($db->tables));

echo "\n=== dealer_wallet_transaction ===\n";
var_dump($db->tables['dealer_wallet_transaction'] ?? 'EMPTY');

echo "\n=== Direct query test ===\n";
$txRepo = new \Dealer\Wallet\Repository\TransactionRepository();
$found = $txRepo->findByWalletId(1, 1, 10);
var_dump($found);
