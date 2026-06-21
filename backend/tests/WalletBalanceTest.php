<?php

use PHPUnit\Framework\TestCase;
use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\InsufficientBalanceException;

class WalletBalanceTest extends TestCase
{
    private WalletService $service;

    protected function setUp(): void
    {
        parent::setUp();
        \PermissionService::setOperatorContext('admin_1', 'super_admin');
        $this->service = new WalletService();
    }

    protected function tearDown(): void
    {
        $db = \Dealer\Wallet\Config\Database::getConnection();
        $db->clearAll();
        parent::tearDown();
    }

    private function getWallet(int $dealerId): array
    {
        $repo = new \Dealer\Wallet\Repository\WalletRepository();
        $wallet = $repo->findByDealerId($dealerId);
        return $wallet ? $wallet->toArray() : [];
    }

    // ===== 基础余额操作测试 =====

    public function testRechargeIncreasesBalance()
    {
        $result = $this->service->recharge(201, 500.00, ['operator' => 'admin']);
        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('500.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    public function testMultipleRechargesCumulate()
    {
        $this->service->recharge(202, 100.00, ['operator' => 'admin']);
        $this->service->recharge(202, 200.50, ['operator' => 'admin']);
        $result = $this->service->recharge(202, 300.25, ['operator' => 'admin']);

        $this->assertEquals('600.75', $result['balance']);
        $this->assertEquals('600.75', $result['available_amount']);
    }

    public function testWithdrawDecreasesBalance()
    {
        $this->service->recharge(203, 1000.00, ['operator' => 'admin']);
        $result = $this->service->withdraw(203, 300.00, ['operator' => 'admin']);

        $this->assertEquals('700.00', $result['balance']);
        $this->assertEquals('700.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    public function testWithdrawExceedsBalanceThrowsException()
    {
        $this->service->recharge(204, 500.00, ['operator' => 'admin']);

        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessage('余额不足');
        $this->service->withdraw(204, 600.00, ['operator' => 'admin']);
    }

    public function testConsumeDecreasesAvailableBalance()
    {
        $this->service->recharge(205, 1000.00, ['operator' => 'admin']);
        $result = $this->service->consume(205, 200.00, ['operator' => 'admin', 'related_no' => 'ORDER001']);

        $this->assertEquals('800.00', $result['balance']);
        $this->assertEquals('800.00', $result['available_amount']);
    }

    public function testConsumeExceedsAvailableThrowsException()
    {
        $this->service->recharge(206, 500.00, ['operator' => 'admin']);

        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessage('可用余额不足');
        $this->service->consume(206, 600.00, ['operator' => 'admin']);
    }

    public function testRefundIncreasesBalance()
    {
        $this->service->recharge(207, 1000.00, ['operator' => 'admin']);
        $this->service->consume(207, 300.00, ['operator' => 'admin']);
        $result = $this->service->refund(207, 150.00, ['operator' => 'admin', 'related_no' => 'REFUND001']);

        $this->assertEquals('850.00', $result['balance']);
        $this->assertEquals('850.00', $result['available_amount']);
    }

    // ===== 余额与冻结的交互测试 =====

    public function testFreezeDoesNotAffectBalanceOnlyAvailable()
    {
        $this->service->recharge(208, 1000.00, ['operator' => 'admin']);
        $result = $this->service->freeze(208, 300.00, ['operator' => 'admin', 'reason' => 'test']);

        $this->assertEquals('1000.00', $result['balance']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('700.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
    }

    public function testConsumeWithFrozenAmountUsesAvailable()
    {
        $this->service->recharge(209, 1000.00, ['operator' => 'admin']);
        $this->service->freeze(209, 400.00, ['operator' => 'admin', 'reason' => 'test']);

        $result = $this->service->consume(209, 500.00, ['operator' => 'admin']);
        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('400.00', $result['frozen_amount']);
        $this->assertEquals('100.00', $result['available_amount']);
    }

    public function testConsumeExceedsAvailableWithFrozenThrowsException()
    {
        $this->service->recharge(210, 1000.00, ['operator' => 'admin']);
        $this->service->freeze(210, 700.00, ['operator' => 'admin', 'reason' => 'test']);

        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessage('可用余额不足');
        $this->service->consume(210, 400.00, ['operator' => 'admin']);
    }

    public function testWithdrawWithFrozenAmountUsesAvailable()
    {
        $this->service->recharge(211, 1000.00, ['operator' => 'admin']);
        $this->service->freeze(211, 300.00, ['operator' => 'admin', 'reason' => 'test']);

        $result = $this->service->withdraw(211, 500.00, ['operator' => 'admin']);
        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('200.00', $result['available_amount']);
    }

    public function testRefundWithFrozenAmountIncreasesAvailable()
    {
        $this->service->recharge(212, 1000.00, ['operator' => 'admin']);
        $this->service->freeze(212, 400.00, ['operator' => 'admin', 'reason' => 'test']);
        $this->service->consume(212, 300.00, ['operator' => 'admin']);

        $wallet = $this->getWallet(212);
        $this->assertEquals('700.00', $wallet['balance']);
        $this->assertEquals('400.00', $wallet['frozen_amount']);
        $this->assertEquals('300.00', $wallet['available_amount']);

        $result = $this->service->refund(212, 150.00, ['operator' => 'admin']);
        $this->assertEquals('850.00', $result['balance']);
        $this->assertEquals('400.00', $result['frozen_amount']);
        $this->assertEquals('450.00', $result['available_amount']);
    }

    public function testRechargeWithFrozenAmountIncreasesBalanceAndAvailable()
    {
        $this->service->recharge(213, 500.00, ['operator' => 'admin']);
        $this->service->freeze(213, 300.00, ['operator' => 'admin', 'reason' => 'test']);

        $result = $this->service->recharge(213, 500.00, ['operator' => 'admin']);
        $this->assertEquals('1000.00', $result['balance']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('700.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
    }

    // ===== 状态机联动测试 =====

    public function testFullCycleRechargeFreezeConsumeUnfreezeWithdraw()
    {
        $this->service->recharge(214, 2000.00, ['operator' => 'admin']);
        $wallet = $this->getWallet(214);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);

        $f1 = $this->service->freeze(214, 800.00, ['operator' => 'admin', 'reason' => 'order hold']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $f1['status']);

        $this->service->consume(214, 500.00, ['operator' => 'admin']);
        $wallet = $this->getWallet(214);
        $this->assertEquals('1500.00', $wallet['balance']);
        $this->assertEquals('700.00', $wallet['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet['status']);

        $this->service->unfreeze($f1['freeze_no'], 800.00, ['operator' => 'admin', 'reason' => 'release']);
        $wallet = $this->getWallet(214);
        $this->assertEquals('1500.00', $wallet['balance']);
        $this->assertEquals('1500.00', $wallet['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);

        $this->service->withdraw(214, 1000.00, ['operator' => 'admin']);
        $wallet = $this->getWallet(214);
        $this->assertEquals('500.00', $wallet['balance']);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);
    }

    public function testFullyFrozenWalletCannotConsume()
    {
        $this->service->recharge(215, 1000.00, ['operator' => 'admin']);
        $this->service->freeze(215, 1000.00, ['operator' => 'admin', 'reason' => 'full freeze']);

        $wallet = $this->getWallet(215);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $wallet['status']);
        $this->assertEquals('0.00', $wallet['available_amount']);

        $this->expectException(InsufficientBalanceException::class);
        $this->service->consume(215, 50.00, ['operator' => 'admin']);
    }

    public function testFullyFrozenWalletCannotWithdraw()
    {
        $this->service->recharge(216, 1000.00, ['operator' => 'admin']);
        $this->service->freeze(216, 1000.00, ['operator' => 'admin', 'reason' => 'full freeze']);

        $this->expectException(InsufficientBalanceException::class);
        $this->service->withdraw(216, 50.00, ['operator' => 'admin']);
    }

    public function testDeductFrozenReducesBalance()
    {
        $this->service->recharge(217, 1000.00, ['operator' => 'admin']);
        $f = $this->service->freeze(217, 500.00, ['operator' => 'admin', 'reason' => 'test']);

        $result = $this->service->deductFrozen($f['freeze_no'], 500.00, ['operator' => 'admin', 'reason' => 'penalty']);
        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('0.00', $result['frozen_amount']);
        $this->assertEquals('500.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    // ===== 金额精度和边界测试 =====

    public function testDecimalPrecisionPreserved()
    {
        $this->service->recharge(218, 100.33, ['operator' => 'admin']);
        $this->service->recharge(218, 200.77, ['operator' => 'admin']);
        $result = $this->service->recharge(218, 50.50, ['operator' => 'admin']);

        $this->assertEquals('351.60', $result['balance']);
    }

    public function testZeroAmountThrowsException()
    {
        $this->service->recharge(219, 100.00, ['operator' => 'admin']);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('金额非法');
        $this->service->withdraw(219, 0.00, ['operator' => 'admin']);
    }

    public function testNegativeAmountThrowsException()
    {
        $this->service->recharge(220, 100.00, ['operator' => 'admin']);

        $this->expectException(WalletException::class);
        $this->service->consume(220, -50.00, ['operator' => 'admin']);
    }

    // ===== 交易记录测试（基础验证） =====

    public function testRechargeCreatesTransactionRecord()
    {
        $result = $this->service->recharge(221, 500.00, [
            'operator' => 'admin',
            'remark' => 'test recharge',
            'related_no' => 'RECHARGE001'
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertGreaterThan(0, $result['id']);
    }

    // ===== 完整闭环测试 =====

    public function testCompleteBalanceLifecycle()
    {
        $this->service->recharge(222, 5000.00, ['operator' => 'admin']);

        $f1 = $this->service->freeze(222, 1000.00, ['operator' => 'admin', 'reason' => 'order1']);
        $f2 = $this->service->freeze(222, 1500.00, ['operator' => 'admin', 'reason' => 'order2']);

        $this->service->consume(222, 800.00, ['operator' => 'admin']);
        $this->service->consume(222, 500.00, ['operator' => 'admin']);

        $wallet = $this->getWallet(222);
        $this->assertEquals('3700.00', $wallet['balance']);
        $this->assertEquals('2500.00', $wallet['frozen_amount']);
        $this->assertEquals('1200.00', $wallet['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet['status']);

        $this->service->refund(222, 200.00, ['operator' => 'admin']);
        $wallet = $this->getWallet(222);
        $this->assertEquals('3900.00', $wallet['balance']);
        $this->assertEquals('1400.00', $wallet['available_amount']);

        $this->service->unfreeze($f1['freeze_no'], 1000.00, ['operator' => 'admin', 'reason' => 'order1 done']);
        $wallet = $this->getWallet(222);
        $this->assertEquals('3900.00', $wallet['balance']);
        $this->assertEquals('1500.00', $wallet['frozen_amount']);
        $this->assertEquals('2400.00', $wallet['available_amount']);

        $this->service->deductFrozen($f2['freeze_no'], 1500.00, ['operator' => 'admin', 'reason' => 'order2 penalty']);
        $wallet = $this->getWallet(222);
        $this->assertEquals('2400.00', $wallet['balance']);
        $this->assertEquals('0.00', $wallet['frozen_amount']);
        $this->assertEquals('2400.00', $wallet['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);

        $this->service->withdraw(222, 2400.00, ['operator' => 'admin']);
        $wallet = $this->getWallet(222);
        $this->assertEquals('0.00', $wallet['balance']);
        $this->assertEquals('0.00', $wallet['frozen_amount']);
        $this->assertEquals('0.00', $wallet['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);
    }
}
