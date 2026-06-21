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
require_once BASE_PATH . '/core/AuditService.php';
require_once BASE_PATH . '/core/WarehouseRouter.php';
require_once BASE_PATH . '/core/OrderService.php';
require_once BASE_PATH . '/core/FulfillmentCallbackService.php';

use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletStateException;
use Dealer\Wallet\Exception\WalletPermissionException;

MockDatabase::getInstance()->clearAll();
PermissionService::setOperatorContext('admin_1', 'super_admin');

echo "========================================================\n";
echo "  经销商钱包状态机 - 回滚提示与重试入口测试\n";
echo "========================================================\n\n";

$service = new WalletService();
$pass = 0;
$fail = 0;

function test(string $name, callable $fn) {
    global $pass, $fail;
    echo "【测试】{$name}\n";
    try {
        $fn();
        echo "  ✅ 通过\n\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "  ❌ 失败: " . $e->getMessage() . "\n";
        echo "  " . str_replace("\n", "\n  ", $e->getTraceAsString()) . "\n\n";
        $fail++;
    }
}

function section(string $title) {
    echo "──────────────────────────────────────────────────\n";
    echo "  {$title}\n";
    echo "──────────────────────────────────────────────────\n";
}

section('场景 1：基准测试 - 充值正常流程');
test('充值 1000 成功，无异常', function() use ($service) {
    $result = $service->recharge(1001, 1000.00, ['operator' => 'admin']);
    assert($result['balance'] === '1000.00', '余额应该是 1000.00，实际 ' . $result['balance']);
    assert($result['status'] === WalletStatus::NORMAL, '状态应该是 NORMAL');
    assert($result['available_amount'] === '1000.00', '可用余额应该是 1000.00');
});

section('场景 2：超额提现 - 余额不足回滚 + 不可重试 + 充值建议');
test('提现 2000（超过余额 1000），应抛出 InsufficientBalanceException 且带完整回滚/重试信息', function() use ($service) {
    $walletBefore = $service->getWallet(1001);
    assert($walletBefore['balance'] === '1000.00', '操作前余额应该是 1000.00');

    $caught = null;
    try {
        $service->withdraw(1001, 2000.00, ['operator' => 'admin']);
    } catch (InsufficientBalanceException $e) {
        $caught = $e;
    }

    assert($caught !== null, '应该抛出 InsufficientBalanceException');

    $rollback = $caught->getRollbackInfo();
    echo "  📋 回滚信息:\n";
    echo "     rollback_success: " . ($rollback['rollback_success'] ? 'true' : 'false') . "\n";
    echo "     operation_name: {$rollback['operation_name']}\n";
    echo "     operation_type: {$rollback['operation_type']}\n";
    echo "     operation_amount: {$rollback['operation_amount']}\n";
    echo "     rollback_message: {$rollback['rollback_message']}\n";
    echo "     wallet_snapshot: balance_before={$rollback['wallet_snapshot']['balance_before']}, "
         . "frozen_before={$rollback['wallet_snapshot']['frozen_amount_before']}, "
         . "available_before={$rollback['wallet_snapshot']['available_amount_before']}, "
         . "status_before=" . WalletStatus::getName($rollback['wallet_snapshot']['status_before']) . "\n";
    echo "     rollback_details:\n";
    foreach ($rollback['rollback_details'] as $d) {
        echo "       - {$d}\n";
    }

    assert($rollback['rollback_success'] === true, '回滚应该成功');
    assert($rollback['operation_name'] === '余额提现', '操作名应该是 余额提现');
    assert($rollback['operation_type'] === 'balance_decrease', '操作类型应该是 balance_decrease');
    assert($rollback['operation_amount'] === '2000.00', '操作金额应该是 2000.00');
    assert(isset($rollback['wallet_snapshot']), '应该有钱包快照');
    assert($rollback['wallet_snapshot']['balance_before'] === '1000.00', '快照余额应该是 1000.00');
    assert(!empty($rollback['rollback_details']), '应该有回滚详情');

    $retry = $caught->getRetryInfo();
    echo "  🔄 重试信息:\n";
    echo "     retryable: " . ($retry['retryable'] ? 'true' : 'false') . "\n";
    echo "     retry_strategy: {$retry['retry_strategy']}\n";
    echo "     retry_entry.operation_name: {$retry['retry_entry']['operation_name']}\n";
    echo "     retry_entry.can_retry: " . ($retry['retry_entry']['can_retry'] ? 'true' : 'false') . "\n";
    echo "     retry_entry.retry_button_text: {$retry['retry_entry']['retry_button_text']}\n";
    echo "     retry_entry.retry_hint: {$retry['retry_entry']['retry_hint']}\n";
    echo "     suggestions:\n";
    foreach ($retry['suggestions'] as $s) {
        echo "       - {$s}\n";
    }

    assert($retry['retryable'] === false, '余额不足应该不可重试');
    assert($retry['retry_entry']['can_retry'] === false, 'UI 层也应该不可重试');
    assert($retry['retry_entry']['retry_button_text'] === '无法重试', '按钮文案应该是 无法重试');
    assert(in_array('请先充值或解冻部分冻结资金后再试', $retry['suggestions']), '应该有充值/解冻建议');

    $walletAfter = $service->getWallet(1001);
    assert($walletAfter['balance'] === '1000.00', '回滚后余额应该仍为 1000.00，实际 ' . $walletAfter['balance']);
    assert($walletAfter['available_amount'] === '1000.00', '回滚后可用余额应该仍为 1000.00');

    $ctx = $caught->getFullContext();
    assert(isset($ctx['rollback_info']) && isset($ctx['retry_info']), 'getFullContext 应该包含完整上下文');
});

section('场景 3：无效单号解冻 - 回滚 + 不可重试 + 核对单号建议');
test('解冻不存在的冻结单，应抛出 WalletException 且带完整回滚/重试信息', function() use ($service) {
    $walletBefore = $service->getWallet(1001);
    assert($walletBefore['balance'] === '1000.00');

    $caught = null;
    try {
        $service->unfreeze('FREEZE_INVALID_NO_12345', 100.00, ['operator' => 'admin']);
    } catch (WalletException $e) {
        $caught = $e;
    }

    assert($caught !== null, '应该抛出 WalletException');
    assert($caught instanceof InsufficientBalanceException === false, '不应该是 InsufficientBalanceException');

    $rollback = $caught->getRollbackInfo();
    echo "  📋 回滚信息:\n";
    echo "     rollback_success: " . ($rollback['rollback_success'] ? 'true' : 'false') . "\n";
    echo "     operation_name: {$rollback['operation_name']}\n";
    echo "     freeze_no: " . ($rollback['freeze_no'] ?? '(none)') . "\n";
    echo "     rollback_message: {$rollback['rollback_message']}\n";
    foreach ($rollback['rollback_details'] as $d) {
        echo "       - {$d}\n";
    }

    assert($rollback['rollback_success'] === true);
    assert($rollback['operation_name'] === '资金解冻');
    assert(($rollback['freeze_no'] ?? '') === 'FREEZE_INVALID_NO_12345');

    $retry = $caught->getRetryInfo();
    echo "  🔄 重试信息:\n";
    echo "     retryable: " . ($retry['retryable'] ? 'true' : 'false') . "\n";
    echo "     retry_button_text: {$retry['retry_entry']['retry_button_text']}\n";
    echo "     suggestions:\n";
    foreach ($retry['suggestions'] as $s) {
        echo "       - {$s}\n";
    }

    assert($retry['retryable'] === false, '单号不存在应该不可重试');
    assert($retry['retry_entry']['retry_button_text'] === '无法重试');
    assert(in_array('请核对相关单号或ID是否正确', $retry['suggestions']));

    $walletAfter = $service->getWallet(1001);
    assert($walletAfter['balance'] === '1000.00', '回滚后余额应该未变');
});

section('场景 4：权限异常 - 回滚 + 不可重试 + 切换账号建议');
test('普通经销商试图操作其他经销商钱包，应抛出 WalletPermissionException', function() use ($service) {
    PermissionService::setOperatorContext('dealer_200', 'dealer', 200);

    $caught = null;
    try {
        $service->withdraw(1001, 100.00, ['operator' => 'dealer_200']);
    } catch (WalletPermissionException $e) {
        $caught = $e;
    }

    assert($caught !== null, '应该抛出 WalletPermissionException');

    $rollback = $caught->getRollbackInfo();
    echo "  📋 回滚信息:\n";
    echo "     rollback_success: " . ($rollback['rollback_success'] ? 'true' : 'false') . "\n";
    echo "     operation_name: {$rollback['operation_name']}\n";
    echo "     error_type: {$rollback['error_type']}\n";

    $retry = $caught->getRetryInfo();
    echo "  🔄 重试信息:\n";
    echo "     retryable: " . ($retry['retryable'] ? 'true' : 'false') . "\n";
    echo "     retry_button_text: {$retry['retry_entry']['retry_button_text']}\n";
    echo "     suggestions:\n";
    foreach ($retry['suggestions'] as $s) {
        echo "       - {$s}\n";
    }

    assert($retry['retryable'] === false);
    assert($retry['retry_entry']['retry_button_text'] === '无法重试');
    assert(in_array('请使用具有相应权限的账号进行操作', $retry['suggestions']));

    PermissionService::setOperatorContext('admin_1', 'super_admin');
});

section('场景 5：回滚数据完整性验证');
test('所有异常场景后，钱包余额、冻结金额、状态都保持和操作前完全一致', function() use ($service) {
    $wallet = $service->getWallet(1001);
    assert($wallet['balance'] === '1000.00', '最终余额应为 1000.00，实际 ' . $wallet['balance']);
    assert($wallet['frozen_amount'] === '0.00', '最终冻结金额应为 0.00，实际 ' . $wallet['frozen_amount']);
    assert($wallet['available_amount'] === '1000.00', '最终可用余额应为 1000.00，实际 ' . $wallet['available_amount']);
    assert($wallet['status'] === WalletStatus::NORMAL, '最终状态应为 NORMAL');
    echo "  ✅ 钱包最终状态：余额={$wallet['balance']}，冻结={$wallet['frozen_amount']}，可用={$wallet['available_amount']}，状态=" . WalletStatus::getName($wallet['status']) . "\n";
});

echo "========================================================\n";
echo "  测试完成：通过 {$pass}，失败 {$fail}\n";
echo "========================================================\n";

exit($fail > 0 ? 1 : 0);
