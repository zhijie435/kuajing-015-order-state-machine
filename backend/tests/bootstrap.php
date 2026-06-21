<?php

if (php_sapi_name() !== 'cli') {
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASE_PATH', dirname(__DIR__));
define('TEST_PATH', __DIR__);
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
require_once BASE_PATH . '/core/AuditService.php';
require_once BASE_PATH . '/core/WarehouseRouter.php';
require_once BASE_PATH . '/core/OrderService.php';
require_once BASE_PATH . '/core/FulfillmentCallbackService.php';

require_once TEST_PATH . '/TestCase.php';
require_once TEST_PATH . '/TestDataSeeder.php';
