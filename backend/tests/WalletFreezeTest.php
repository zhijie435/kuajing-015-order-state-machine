<?php

use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\InsufficientBalanceException;

class WalletFreezeTest extends TestCase
{
    private WalletService $service;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::setOperatorContext('admin_1', 'super_admin');
        $this->service = new WalletService();
    }

    public function testFreezeAndPartialUnfreeze(): void
    {
        $this->service->recharge(201, 1000.00);
        $freeze = $this->service->freeze(201, 400.00, ['operator' => 'admin', 'reason' => 'test']);
        $this->assertSame(WalletStatus::PARTIALLY_FROZEN, $freeze['status']);
        $this->assertSame('400.00', $freeze['frozen_amount']);
        $this->assertSame('600.00', $freeze['available_amount']);
        $this->assertNotEmpty($freeze['freeze_no']);

        $unfreeze = $this->service->unfreeze($freeze['freeze_no'], 100.00);
        $this->assertSame(WalletStatus::PARTIALLY_FROZEN, $unfreeze['status']);
        $this->assertSame('300.00', $unfreeze['frozen_amount']);
        $this->assertSame('700.00', $unfreeze['available_amount']);
        $this->assertSame('100.00', $unfreeze['unfrozen_amount']);
    }

    public function testFullFreezeAndFullUnfreeze(): void
    {
        $this->service->recharge(202, 500.00);
        $freeze = $this->service->freeze(202, 500.00);
        $this->assertSame(WalletStatus::FULLY_FROZEN, $freeze['status']);
        $this->assertSame('0.00', $freeze['available_amount']);

        $unfreeze = $this->service->unfreeze($freeze['freeze_no']);
        $this->assertSame(WalletStatus::NORMAL, $unfreeze['status']);
        $this->assertSame('0.00', $unfreeze['frozen_amount']);
    }

    public function testDeductFrozen(): void
    {
        $this->service->recharge(203, 1000.00);
        $freeze = $this->service->freeze(203, 300.00);
        $deduct = $this->service->deductFrozen($freeze['freeze_no'], 200.00);
        $this->assertSame('800.00', $deduct['balance']);
        $this->assertSame('100.00', $deduct['frozen_amount']);
        $this->assertSame('700.00', $deduct['available_amount']);
    }

    public function testFreezeMoreThanBalanceThrowsException(): void
    {
        $this->service->recharge(204, 100.00);
        try {
            $this->service->freeze(204, 300.00);
            $this->assertTrue(false);
        } catch (InsufficientBalanceException $e) {
            $this->assertTrue($e->hasRollbackInfo());
            $this->assertTrue($e->hasRetryInfo());
            $this->assertFalse($e->getRetryInfo()['retryable']);
        }
    }

    public function testUnfreezeNonExistentFreezeNo(): void
    {
        try {
            $this->service->unfreeze('NOT_EXIST_12345');
            $this->assertTrue(false);
        } catch (WalletException $e) {
            $this->assertTrue($e->hasRollbackInfo());
            $rollback = $e->getRollbackInfo();
            $this->assertSame('NOT_EXIST_12345', $rollback['freeze_no'] ?? '');
            $this->assertFalse($e->getRetryInfo()['retryable']);
        }
    }
}
