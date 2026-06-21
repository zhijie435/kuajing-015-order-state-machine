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
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\WalletPermissionException;

MockDatabase::getInstance()->clearAll();

$pass = 0;
$fail = 0;

function runTest($name, callable $fn) {
    global $pass, $fail;
    echo "\n──────────────────────────────────────────────────\n";
    echo "  {$name}\n";
    echo "──────────────────────────────────────────────────\n";
    try {
        $fn();
        echo "  ✅ 通过\n";
        $pass++;
    } catch (\Exception $e) {
        echo "  ❌ 失败：{$e->getMessage()}\n";
        echo "     File: {$e->getFile()}:{$e->getLine()}\n";
        $fail++;
    }
}

function assertEq($actual, $expected, $msg = '') {
    if ($actual != $expected) {
        $actualStr = var_export($actual, true);
        $expectedStr = var_export($expected, true);
        throw new \Exception("{$msg} - 期望: {$expectedStr}, 实际: {$actualStr}");
    }
}

function assertNotEmpty($value, $msg = '') {
    if (empty($value)) {
        throw new \Exception("{$msg} - 值不能为空");
    }
}

// ============================================================
// 问题2测试：冻结释放/余额变更后列表刷新 + 缓存残留
// ============================================================

runTest('问题2a：updateRemaining更新后FreezeRecord内存对象同步刷新', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);

    $svc = new WalletService();
    $svc->recharge(1001, 1000.0);

    $freezeResult = $svc->freeze(1001, 300.0, ['reason' => '测试冻结', 'operator' => 'admin']);
    $freezeNo = $freezeResult['freeze_no'];

    $repo = new \Dealer\Wallet\Repository\FreezeRecordRepository();
    $record = $repo->findByFreezeNo($freezeNo);
    $recordId = $record->id;

    $repo->updateRemaining($record, 200.0, FreezeStatus::PARTIALLY_UNFROZEN);

    assertEq($record->remainingAmount, 200.0, 'updateRemaining后内存对象remainingAmount已同步');
    assertEq($record->status, FreezeStatus::PARTIALLY_UNFROZEN, 'updateRemaining后内存对象status已同步');

    $refreshed = $repo->findByFreezeNo($freezeNo);
    assertEq($refreshed->remainingAmount, 200.0, 'DB查询验证remainingAmount');
    assertEq($refreshed->status, FreezeStatus::PARTIALLY_UNFROZEN, 'DB查询验证status');
});

runTest('问题2b：充值操作返回结果附带最新交易记录快照', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);

    $svc = new WalletService();
    $result = $svc->recharge(1001, 500.0, ['operator' => 'admin']);

    assertNotEmpty($result['recent_transactions'], '操作返回应包含 recent_transactions');
    assertNotEmpty($result['snapshot_time'], '操作返回应包含 snapshot_time');
    assertEq(count($result['recent_transactions']) >= 1, true, '至少有1条交易记录');
    assertEq($result['balance'], '500.00', '余额正确');
});

runTest('问题2b：冻结操作返回结果附带最新冻结记录快照', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);

    $svc = new WalletService();
    $svc->recharge(1001, 1000.0);
    $result = $svc->freeze(1001, 200.0, ['reason' => '测试']);

    assertNotEmpty($result['recent_freeze_records'], '操作返回应包含 recent_freeze_records');
    assertEq(count($result['recent_freeze_records']) >= 1, true, '至少有1条冻结记录');
    assertEq($result['frozen_amount'], '200.00', '冻结金额正确');
});

runTest('问题2b：解冻操作返回freeze_record_snapshot反映最新状态', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);

    $svc = new WalletService();
    $svc->recharge(1001, 1000.0);
    $freezeResult = $svc->freeze(1001, 500.0);
    $freezeNo = $freezeResult['freeze_no'];

    $unfreezeResult = $svc->unfreeze($freezeNo, 200.0);
    $snapshot = $unfreezeResult['freeze_record_snapshot'];

    assertNotEmpty($snapshot, '应返回 freeze_record_snapshot');
    assertEq($snapshot['remaining_amount'], '300.00', 'snapshot中remaining_amount正确');
    assertEq($snapshot['status'], FreezeStatus::PARTIALLY_UNFROZEN, 'snapshot中status正确');
});

runTest('问题2b：提现操作返回的recent_transactions包含本次提现记录', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);

    $svc = new WalletService();
    $svc->recharge(1001, 1000.0);
    $result = $svc->withdraw(1001, 200.0, ['operator' => 'admin']);

    assertEq($result['balance'], '800.00', '提现后余额正确');
    $foundWithdraw = false;
    foreach ($result['recent_transactions'] as $tx) {
        if ($tx['type'] == \Dealer\Wallet\Enum\TransactionType::WITHDRAW) {
            $foundWithdraw = true;
            assertEq($tx['amount'], '200.00', '提现交易金额正确');
        }
    }
    assertEq($foundWithdraw, true, 'recent_transactions中包含提现记录');
});

// ============================================================
// 问题3测试：权限边界和异常提示统一
// ============================================================

runTest('问题3a：listWallets需要wallet:view:all权限（auditor角色允许）', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('auditor_1', 'auditor');

    $svc = new WalletService();
    $list = $svc->listWallets(1, 20);
    assertEq(isset($list['items']), true, 'auditor角色可以查看钱包列表');
});

runTest('问题3a：listWallets - dealer角色只有view_own只能看自己的', function() {
    MockDatabase::getInstance()->clearAll();

    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);
    $svc = new WalletService();
    $svc->recharge(1001, 100.0);

    PermissionService::setOperatorContext('dealer_1', 'dealer', 1001);
    $list = $svc->listWallets(1, 20);
    assertEq($list['total'], 1, 'dealer只能看到自己的钱包');
    assertEq($list['items'][0]['dealer_id'], 1001, '看到的钱包是自己的');
});

runTest('问题3a：listWallets - 无权限角色抛出统一格式异常', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setRawContext([
        'operator_id' => 'nobody',
        'roles' => ['guest'],
        'permissions' => [],
        'dealer_id' => null,
    ]);

    $svc = new WalletService();
    try {
        $svc->listWallets();
        throw new \Exception('应该抛出权限异常');
    } catch (WalletPermissionException $e) {
        $ctx = $e->getFullContext();
        assertNotEmpty($ctx['retry_info'], '权限异常应包含 retry_info');
        assertEq($ctx['retry_info']['retryable'], false, '权限异常不可重试');
        assertNotEmpty($ctx['retry_info']['suggestions'], '权限异常包含处理建议');
        assertEq(strpos($e->getMessage(), '【权限不足】') !== false, true, '异常消息统一以【权限不足】开头');
    }
});

runTest('问题3c：dealer角色（只有view_own）无法执行充值', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('dealer_1', 'dealer', 1001);

    $svc = new WalletService();
    try {
        $svc->recharge(1001, 100.0);
        throw new \Exception('应该抛出权限异常 - dealer无充值权限');
    } catch (WalletPermissionException $e) {
        $ctx = $e->getFullContext();
        assertEq($ctx['retry_info']['retryable'], false, '权限异常不可重试');
        assertEq(strpos($e->getMessage(), '钱包充值') !== false, true, '异常消息包含操作名');
    }
});

runTest('问题3c：wallet_admin角色可以执行充值/提现/冻结/解冻', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('wallet_admin_1', 'wallet_admin', 1001);

    $svc = new WalletService();
    $r = $svc->recharge(1001, 1000.0, ['operator' => 'wa']);
    assertEq($r['balance'], '1000.00', 'wallet_admin可以充值');

    $r = $svc->withdraw(1001, 100.0, ['operator' => 'wa']);
    assertEq($r['balance'], '900.00', 'wallet_admin可以提现');

    $r = $svc->freeze(1001, 200.0, ['operator' => 'wa', 'reason' => 't']);
    assertEq($r['frozen_amount'], '200.00', 'wallet_admin可以冻结');

    $freezeNo = $r['freeze_no'];
    $r = $svc->unfreeze($freezeNo, 50.0, ['operator' => 'wa']);
    assertEq($r['frozen_amount'], '150.00', 'wallet_admin可以解冻');
});

runTest('问题3c：auditor角色（只有view权限）无法执行冻结', function() {
    MockDatabase::getInstance()->clearAll();

    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);
    $svc = new WalletService();
    $svc->recharge(1001, 1000.0);

    PermissionService::setOperatorContext('auditor_1', 'auditor');
    try {
        $svc->freeze(1001, 100.0);
        throw new \Exception('应该抛出权限异常 - auditor无冻结权限');
    } catch (WalletPermissionException $e) {
        assertEq(strpos($e->getMessage(), '资金冻结') !== false, true, '异常包含操作名：' . $e->getMessage());
    }
});

runTest('问题3d：经销商数据隔离异常提示统一格式', function() {
    MockDatabase::getInstance()->clearAll();
    PermissionService::setOperatorContext('dealer_1', 'dealer', 1001);

    $svc = new WalletService();
    try {
        $svc->getWallet(9999);
        throw new \Exception('应该抛出数据隔离异常');
    } catch (WalletPermissionException $e) {
        assertEq(strpos($e->getMessage(), '【权限不足】经销商数据隔离') !== false, true, '异常消息格式正确');
        $ctx = $e->getFullContext();
        assertEq($ctx['retry_info']['retry_entry']['can_retry'], false, '不可重试');
        assertNotEmpty($ctx['retry_info']['suggestions'], '包含处理建议');
    }
});

runTest('问题3d：fixWalletInconsistency需要wallet:fix权限', function() {
    MockDatabase::getInstance()->clearAll();

    PermissionService::setOperatorContext('wallet_admin_1', 'wallet_admin');
    $svc = new WalletService();
    try {
        $svc->fixWalletInconsistency(1001, 'tester');
    } catch (WalletPermissionException $e) {
        throw new \Exception("wallet_admin应该有fix权限: " . $e->getMessage());
    } catch (\Exception $e) {
    }

    PermissionService::setOperatorContext('auditor_1', 'auditor');
    $svc2 = new WalletService();
    try {
        $svc2->fixWalletInconsistency(1001, 'tester');
        throw new \Exception('auditor不应该有fix权限');
    } catch (WalletPermissionException $e) {
        assertEq(strpos($e->getMessage(), '修复钱包异常数据') !== false, true, '异常消息包含操作名');
    }
});

runTest('问题3d：dealer角色操作其他经销商钱包触发数据隔离异常', function() {
    MockDatabase::getInstance()->clearAll();

    PermissionService::setOperatorContext('admin_1', 'super_admin', 1001);
    $svc = new WalletService();
    $svc->recharge(2002, 500.0);

    PermissionService::setOperatorContext('dealer_1', 'dealer', 1001);
    try {
        $svc->withdraw(2002, 100.0);
        throw new \Exception('应该抛出数据隔离异常');
    } catch (WalletPermissionException $e) {
        assertEq(strpos($e->getMessage(), '经销商数据隔离') !== false, true, '异常包含经销商数据隔离提示');
    }
});

echo "\n========================================================\n";
echo "  测试完成：通过 {$pass}，失败 {$fail}\n";
echo "========================================================\n";

exit($fail > 0 ? 1 : 0);
