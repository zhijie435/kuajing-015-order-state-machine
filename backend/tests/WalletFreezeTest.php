<?php

use PHPUnit\Framework\TestCase;
use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Repository\FreezeRecordRepository;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\InsufficientBalanceException;

class WalletFreezeTest extends TestCase
{
    private WalletService $service;
    private FreezeRecordRepository $freezeRepo;

    protected function setUp(): void
    {
        parent::setUp();
        \PermissionService::setOperatorContext('admin_1', 'super_admin');
        $this->service = new WalletService();
        $this->freezeRepo = new FreezeRecordRepository();
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

    public function testSingleFreezeFullUnfreezeCycle()
    {
        $this->service->recharge(101, 1000.00, ['operator' => 'admin']);

        $freezeResult = $this->service->freeze(101, 300.00, [
            'operator' => 'admin',
            'reason' => 'test freeze'
        ]);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $freezeResult['status']);
        $this->assertEquals('300.00', $freezeResult['frozen_amount']);
        $this->assertEquals('700.00', $freezeResult['available_amount']);
        $freezeNo = $freezeResult['freeze_no'];

        $unfreezeResult = $this->service->unfreeze($freezeNo, 300.00, [
            'operator' => 'admin',
            'reason' => 'test unfreeze'
        ]);
        $this->assertEquals(WalletStatus::NORMAL, $unfreezeResult['status']);
        $this->assertEquals('0.00', $unfreezeResult['frozen_amount']);
        $this->assertEquals('1000.00', $unfreezeResult['available_amount']);

        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertEquals(FreezeStatus::FULLY_UNFROZEN, $record->status);
        $this->assertEquals('0.00', $record->remainingAmount);
    }

    public function testFreezeThenDeductFrozen()
    {
        $this->service->recharge(103, 1000.00, ['operator' => 'admin']);

        $freezeResult = $this->service->freeze(103, 400.00, [
            'operator' => 'admin',
            'reason' => 'test freeze'
        ]);
        $freezeNo = $freezeResult['freeze_no'];

        $deductResult = $this->service->deductFrozen($freezeNo, 400.00, [
            'operator' => 'admin',
            'reason' => 'penalty deduction'
        ]);
        $this->assertEquals(WalletStatus::NORMAL, $deductResult['status']);
        $this->assertEquals('600.00', $deductResult['balance']);
        $this->assertEquals('0.00', $deductResult['frozen_amount']);
        $this->assertEquals('600.00', $deductResult['available_amount']);

        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertEquals(FreezeStatus::DEDUCTED, $record->status);
        $this->assertEquals('0.00', $record->remainingAmount);
    }

    public function testPartiallyUnfrozenRecordCannotBeUnfrozenAgain()
    {
        $this->service->recharge(102, 1000.00, ['operator' => 'admin']);

        $freezeResult = $this->service->freeze(102, 500.00, [
            'operator' => 'admin',
            'reason' => 'test freeze'
        ]);
        $freezeNo = $freezeResult['freeze_no'];

        $partialResult = $this->service->unfreeze($freezeNo, 200.00, [
            'operator' => 'admin',
            'reason' => 'partial unfreeze'
        ]);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $partialResult['status']);
        $this->assertEquals('300.00', $partialResult['frozen_amount']);

        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertEquals(FreezeStatus::PARTIALLY_UNFROZEN, $record->status);
        $this->assertEquals('300.00', $record->remainingAmount);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('冻结记录状态异常');
        $this->service->unfreeze($freezeNo, 300.00, [
            'operator' => 'admin',
            'reason' => 'try again'
        ]);
    }

    public function testPartiallyDeductedRecordCannotBeDeductedAgain()
    {
        $this->service->recharge(104, 1000.00, ['operator' => 'admin']);

        $freezeResult = $this->service->freeze(104, 500.00, [
            'operator' => 'admin',
            'reason' => 'test freeze'
        ]);
        $freezeNo = $freezeResult['freeze_no'];

        $deductResult = $this->service->deductFrozen($freezeNo, 200.00, [
            'operator' => 'admin',
            'reason' => 'partial penalty'
        ]);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $deductResult['status']);
        $this->assertEquals('800.00', $deductResult['balance']);
        $this->assertEquals('300.00', $deductResult['frozen_amount']);

        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertEquals(FreezeStatus::PARTIALLY_UNFROZEN, $record->status);
        $this->assertEquals('300.00', $record->remainingAmount);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('冻结记录状态异常');
        $this->service->deductFrozen($freezeNo, 100.00, [
            'operator' => 'admin',
            'reason' => 'try again'
        ]);
    }

    public function testMultipleFreezesCumulativeFrozenAmount()
    {
        $this->service->recharge(105, 2000.00, ['operator' => 'admin']);

        $f1 = $this->service->freeze(105, 300.00, ['operator' => 'admin', 'reason' => 'freeze 1']);
        $this->assertEquals('300.00', $f1['frozen_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $f1['status']);

        $f2 = $this->service->freeze(105, 500.00, ['operator' => 'admin', 'reason' => 'freeze 2']);
        $this->assertEquals('800.00', $f2['frozen_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $f2['status']);

        $f3 = $this->service->freeze(105, 1200.00, ['operator' => 'admin', 'reason' => 'freeze 3']);
        $this->assertEquals('2000.00', $f3['frozen_amount']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $f3['status']);
        $this->assertEquals('0.00', $f3['available_amount']);
    }

    public function testFreezeAmountExceedsAvailableThrowsException()
    {
        $this->service->recharge(106, 500.00, ['operator' => 'admin']);

        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessage('可用余额不足');
        $this->service->freeze(106, 600.00, ['operator' => 'admin', 'reason' => 'too much']);
    }

    public function testFreezeZeroAmountThrowsException()
    {
        $this->service->recharge(107, 500.00, ['operator' => 'admin']);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('金额非法');
        $this->service->freeze(107, 0.00, ['operator' => 'admin', 'reason' => 'zero']);
    }

    public function testUnfreezeAmountExceedsRemainingThrowsException()
    {
        $this->service->recharge(108, 500.00, ['operator' => 'admin']);
        $freezeResult = $this->service->freeze(108, 200.00, ['operator' => 'admin', 'reason' => 'test']);
        $freezeNo = $freezeResult['freeze_no'];

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('解冻金额超额');
        $this->service->unfreeze($freezeNo, 300.00, ['operator' => 'admin', 'reason' => 'too much']);
    }

    public function testDeductFrozenAmountExceedsRemainingThrowsException()
    {
        $this->service->recharge(109, 500.00, ['operator' => 'admin']);
        $freezeResult = $this->service->freeze(109, 200.00, ['operator' => 'admin', 'reason' => 'test']);
        $freezeNo = $freezeResult['freeze_no'];

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('扣除金额超额');
        $this->service->deductFrozen($freezeNo, 300.00, ['operator' => 'admin', 'reason' => 'too much']);
    }

    public function testUnfreezeOnFullyUnfrozenRecordThrowsException()
    {
        $this->service->recharge(110, 500.00, ['operator' => 'admin']);
        $freezeResult = $this->service->freeze(110, 200.00, ['operator' => 'admin', 'reason' => 'test']);
        $freezeNo = $freezeResult['freeze_no'];

        $this->service->unfreeze($freezeNo, 200.00, ['operator' => 'admin', 'reason' => 'full']);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('冻结记录状态异常');
        $this->service->unfreeze($freezeNo, 50.00, ['operator' => 'admin', 'reason' => 'extra']);
    }

    public function testWalletStatusTransitionsWithFreezeOperations()
    {
        $this->service->recharge(111, 1000.00, ['operator' => 'admin']);
        $wallet = $this->getWallet(111);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);

        $f1 = $this->service->freeze(111, 300.00, ['operator' => 'admin', 'reason' => 'partial']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $f1['status']);

        $f2 = $this->service->freeze(111, 700.00, ['operator' => 'admin', 'reason' => 'full']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $f2['status']);

        $u1 = $this->service->unfreeze($f1['freeze_no'], 300.00, ['operator' => 'admin', 'reason' => 'unfreeze1']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $u1['status']);

        $u2 = $this->service->unfreeze($f2['freeze_no'], 700.00, ['operator' => 'admin', 'reason' => 'unfreeze2']);
        $this->assertEquals(WalletStatus::NORMAL, $u2['status']);
    }

    public function testFreezeNoGenerationAndQuery()
    {
        $this->service->recharge(112, 1000.00, ['operator' => 'admin']);

        $freezeResult = $this->service->freeze(112, 100.00, ['operator' => 'admin', 'reason' => 'test']);
        $freezeNo = $freezeResult['freeze_no'];

        $this->assertStringStartsWith('FZ', $freezeNo);
        $this->assertGreaterThan(10, strlen($freezeNo));

        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertNotNull($record);
        $this->assertEquals(112, $record->dealerId);
        $this->assertEquals('100.00', $record->amount);
        $this->assertEquals('100.00', $record->remainingAmount);
        $this->assertEquals(FreezeStatus::FROZEN, $record->status);
        $this->assertEquals('test', $record->reason);
    }

    public function testFreezeRecordFindAllByWalletId()
    {
        $this->service->recharge(113, 2000.00, ['operator' => 'admin']);

        $f1 = $this->service->freeze(113, 100.00, ['operator' => 'admin', 'reason' => 'freeze1']);
        $f2 = $this->service->freeze(113, 200.00, ['operator' => 'admin', 'reason' => 'freeze2']);
        $f3 = $this->service->freeze(113, 300.00, ['operator' => 'admin', 'reason' => 'freeze3']);

        $wallet = $this->getWallet(113);
        $records = $this->freezeRepo->findAllByWalletId($wallet['id']);
        $this->assertCount(3, $records);

        $this->service->unfreeze($f2['freeze_no'], 200.00, ['operator' => 'admin', 'reason' => 'unfreeze']);

        $frozenCount = 0;
        foreach ($this->freezeRepo->findAllByWalletId($wallet['id']) as $r) {
            if ($r->status === FreezeStatus::FROZEN) {
                $frozenCount++;
            }
        }
        $this->assertEquals(2, $frozenCount);
    }

    public function testFullFreezeCycleWithDeductAndUnfreeze()
    {
        $this->service->recharge(114, 3000.00, ['operator' => 'admin']);

        $f1 = $this->service->freeze(114, 500.00, ['operator' => 'admin', 'reason' => 'order1']);
        $f2 = $this->service->freeze(114, 800.00, ['operator' => 'admin', 'reason' => 'order2']);
        $f3 = $this->service->freeze(114, 1700.00, ['operator' => 'admin', 'reason' => 'order3']);

        $wallet = $this->getWallet(114);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $wallet['status']);
        $this->assertEquals('3000.00', $wallet['frozen_amount']);
        $this->assertEquals('0.00', $wallet['available_amount']);

        $this->service->deductFrozen($f1['freeze_no'], 500.00, ['operator' => 'admin', 'reason' => 'penalty']);
        $wallet = $this->getWallet(114);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $wallet['status']);
        $this->assertEquals('2500.00', $wallet['balance']);
        $this->assertEquals('2500.00', $wallet['frozen_amount']);
        $this->assertEquals('0.00', $wallet['available_amount']);

        $this->service->unfreeze($f2['freeze_no'], 800.00, ['operator' => 'admin', 'reason' => 'release']);
        $wallet = $this->getWallet(114);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet['status']);
        $this->assertEquals('2500.00', $wallet['balance']);
        $this->assertEquals('1700.00', $wallet['frozen_amount']);
        $this->assertEquals('800.00', $wallet['available_amount']);

        $this->service->unfreeze($f3['freeze_no'], 1700.00, ['operator' => 'admin', 'reason' => 'release all']);
        $wallet = $this->getWallet(114);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);
        $this->assertEquals('2500.00', $wallet['balance']);
        $this->assertEquals('0.00', $wallet['frozen_amount']);
        $this->assertEquals('2500.00', $wallet['available_amount']);
    }

    public function testFreezeAmountPrecision()
    {
        $this->service->recharge(115, 1000.55, ['operator' => 'admin']);

        $freezeResult = $this->service->freeze(115, 100.33, ['operator' => 'admin', 'reason' => 'test']);
        $this->assertEquals('100.33', $freezeResult['frozen_amount']);
        $this->assertEquals('900.22', $freezeResult['available_amount']);

        $freezeNo = $freezeResult['freeze_no'];
        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertEquals('100.33', $record->amount);
        $this->assertEquals('100.33', $record->remainingAmount);
    }

    public function testFreezeStatusEnumValues()
    {
        $this->assertEquals(1, FreezeStatus::FROZEN);
        $this->assertEquals(2, FreezeStatus::PARTIALLY_UNFROZEN);
        $this->assertEquals(3, FreezeStatus::FULLY_UNFROZEN);
        $this->assertEquals(4, FreezeStatus::DEDUCTED);
        $this->assertEquals(5, FreezeStatus::EXPIRED);
    }

    public function testFreezeStatusGetName()
    {
        $this->assertEquals('冻结中', FreezeStatus::getName(FreezeStatus::FROZEN));
        $this->assertEquals('部分解冻', FreezeStatus::getName(FreezeStatus::PARTIALLY_UNFROZEN));
        $this->assertEquals('已全额解冻', FreezeStatus::getName(FreezeStatus::FULLY_UNFROZEN));
        $this->assertEquals('已扣除', FreezeStatus::getName(FreezeStatus::DEDUCTED));
        $this->assertEquals('已过期', FreezeStatus::getName(FreezeStatus::EXPIRED));
    }

    public function testFreezeWithExpiredAt()
    {
        $this->service->recharge(116, 1000.00, ['operator' => 'admin']);

        $expiredAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $freezeResult = $this->service->freeze(116, 200.00, [
            'operator' => 'admin',
            'reason' => 'with expiry',
            'expired_at' => $expiredAt
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $record = $this->freezeRepo->findByFreezeNo($freezeNo);
        $this->assertEquals($expiredAt, $record->expiredAt);
    }

    public function testInvalidFreezeNoThrowsException()
    {
        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('冻结记录不存在');
        $this->service->unfreeze('INVALID_NO', 100.00, ['operator' => 'admin', 'reason' => 'test']);
    }

    public function testDeductOnDeductedRecordThrowsException()
    {
        $this->service->recharge(117, 1000.00, ['operator' => 'admin']);
        $freezeResult = $this->service->freeze(117, 200.00, ['operator' => 'admin', 'reason' => 'test']);
        $freezeNo = $freezeResult['freeze_no'];

        $this->service->deductFrozen($freezeNo, 200.00, ['operator' => 'admin', 'reason' => 'penalty']);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('冻结记录状态异常');
        $this->service->deductFrozen($freezeNo, 50.00, ['operator' => 'admin', 'reason' => 'extra']);
    }
}
