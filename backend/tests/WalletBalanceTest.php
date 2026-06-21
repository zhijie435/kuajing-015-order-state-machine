<?php

use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Enum\WalletStatus;

class WalletBalanceTest extends TestCase
{
    private WalletService $service;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::setOperatorContext('admin_1', 'super_admin');
        $this->service = new WalletService();
    }

    public function testRechargeCreatesWalletAndIncreasesBalance(): void
    {
        $result = $this->service->recharge(101, 500.00, ['operator' => 'admin']);
        $this->assertSame('500.00', $result['balance']);
        $this->assertSame('0.00', $result['frozen_amount']);
        $this->assertSame('500.00', $result['available_amount']);
        $this->assertSame(WalletStatus::NORMAL, $result['status']);
    }

    public function testWithdrawDecreasesAvailableBalance(): void
    {
        $this->service->recharge(102, 500.00);
        $result = $this->service->withdraw(102, 100.00, ['operator' => 'admin']);
        $this->assertSame('400.00', $result['balance']);
        $this->assertSame('400.00', $result['available_amount']);
    }

    public function testWithdrawMoreThanAvailableThrowsInsufficientBalance(): void
    {
        $this->service->recharge(103, 100.00);
        try {
            $this->service->withdraw(103, 200.00);
            $this->assertTrue(false, 'Expected exception not thrown');
        } catch (InsufficientBalanceException $e) {
            $this->assertTrue(true);
            $this->assertTrue($e->hasRollbackInfo(), 'Should have rollback info');
            $this->assertTrue($e->hasRetryInfo(), 'Should have retry info');
            $rollback = $e->getRollbackInfo();
            $this->assertTrue($rollback['rollback_success']);
            $this->assertSame('余额提现', $rollback['operation_name']);
            $this->assertSame('100.00', $rollback['operation_amount']);
            $retry = $e->getRetryInfo();
            $this->assertFalse($retry['retryable']);
            $this->assertFalse($retry['retry_entry']['can_retry']);
        }
    }

    public function testConsumeAndRefund(): void
    {
        $this->service->recharge(104, 500.00);
        $r1 = $this->service->consume(104, 100.00);
        $this->assertSame('400.00', $r1['balance']);

        $r2 = $this->service->refund(104, 50.00);
        $this->assertSame('450.00', $r2['balance']);
    }

    public function testNegativeAmountThrowsException(): void
    {
        try {
            $this->service->recharge(105, -10);
            $this->assertTrue(false);
        } catch (WalletException $e) {
            $this->assertTrue(true);
        }
    }

    public function testRechargeWithRetryInfoOnError(): void
    {
        PermissionService::setOperatorContext('dealer_200', 'dealer', 200);
        try {
            $this->service->withdraw(999, 100.00);
        } catch (\Exception $e) {
            if ($e instanceof WalletException) {
                $this->assertTrue($e->hasRollbackInfo());
            }
        }
    }
}
