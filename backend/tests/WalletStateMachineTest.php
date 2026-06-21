<?php

use PHPUnit\Framework\TestCase;
use Dealer\Wallet\StateMachine\WalletStateMachine;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Exception\WalletStateException;
use Dealer\Wallet\Model\Wallet;

class WalletStateMachineTest extends TestCase
{
    public function testConstructWithValidNormalStatus()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $machine->getCurrentStatus());
    }

    public function testConstructWithValidPartiallyFrozenStatus()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testConstructWithValidFullyFrozenStatus()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testConstructWithInvalidStatusThrowsException()
    {
        $this->expectException(WalletStateException::class);
        new WalletStateMachine(999);
    }

    public function testFromWalletCreatesMachineWithWalletStatus()
    {
        $wallet = new Wallet([
            'balance' => 100.0,
            'frozen_amount' => 50.0,
            'status' => WalletStatus::PARTIALLY_FROZEN,
        ], false);
        $machine = WalletStateMachine::fromWallet($wallet);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testValidateAmountsWithValidAmountsPasses()
    {
        WalletStateMachine::validateAmounts(100.0, 50.0);
        $this->assertTrue(true);
    }

    public function testValidateAmountsWithZeroBalanceAndZeroFrozenPasses()
    {
        WalletStateMachine::validateAmounts(0.0, 0.0);
        $this->assertTrue(true);
    }

    public function testValidateAmountsWithNegativeFrozenThrowsException()
    {
        $this->expectException(WalletStateException::class);
        WalletStateMachine::validateAmounts(100.0, -10.0);
    }

    public function testValidateAmountsWithNegativeBalanceThrowsException()
    {
        $this->expectException(WalletStateException::class);
        WalletStateMachine::validateAmounts(-100.0, 0.0);
    }

    public function testValidateAmountsWithFrozenExceedsBalanceThrowsException()
    {
        $this->expectException(WalletStateException::class);
        WalletStateMachine::validateAmounts(50.0, 100.0);
    }

    public function testCalculateStatusWithZeroFrozenReturnsNormal()
    {
        $status = WalletStateMachine::calculateStatus(100.0, 0.0);
        $this->assertEquals(WalletStatus::NORMAL, $status);
    }

    public function testCalculateStatusWithPartialFrozenReturnsPartiallyFrozen()
    {
        $status = WalletStateMachine::calculateStatus(100.0, 50.0);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $status);
    }

    public function testCalculateStatusWithFullFrozenReturnsFullyFrozen()
    {
        $status = WalletStateMachine::calculateStatus(100.0, 100.0);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $status);
    }

    public function testCalculateStatusWithZeroBalanceAndZeroFrozenReturnsNormal()
    {
        $status = WalletStateMachine::calculateStatus(0.0, 0.0);
        $this->assertEquals(WalletStatus::NORMAL, $status);
    }

    public function testCalculateStatusWithSmallFrozenTreatedAsNormal()
    {
        $status = WalletStateMachine::calculateStatus(100.0, 0.0001);
        $this->assertEquals(WalletStatus::NORMAL, $status);
    }

    public function testCanTransitionFromNormalToPartiallyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $this->assertTrue($machine->canTransitionTo(WalletStatus::PARTIALLY_FROZEN));
    }

    public function testCanTransitionFromNormalToFullyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $this->assertTrue($machine->canTransitionTo(WalletStatus::FULLY_FROZEN));
    }

    public function testCannotTransitionFromNormalToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $this->assertFalse($machine->canTransitionTo(WalletStatus::NORMAL));
    }

    public function testCanTransitionFromPartiallyFrozenToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $this->assertTrue($machine->canTransitionTo(WalletStatus::NORMAL));
    }

    public function testCanTransitionFromPartiallyFrozenToFullyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $this->assertTrue($machine->canTransitionTo(WalletStatus::FULLY_FROZEN));
    }

    public function testCanTransitionFromFullyFrozenToPartiallyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $this->assertTrue($machine->canTransitionTo(WalletStatus::PARTIALLY_FROZEN));
    }

    public function testCanTransitionFromFullyFrozenToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $this->assertTrue($machine->canTransitionTo(WalletStatus::NORMAL));
    }

    public function testTransitionNormalToPartiallyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $machine->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testTransitionNormalToFullyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $machine->transition(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testTransitionPartiallyFrozenToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $machine->transition(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $machine->getCurrentStatus());
    }

    public function testTransitionPartiallyFrozenToFullyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $machine->transition(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testTransitionFullyFrozenToPartiallyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $machine->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $machine->getCurrentStatus());
    }

    public function testTransitionFullyFrozenToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $machine->transition(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $machine->getCurrentStatus());
    }

    public function testTransitionInvalidThrowsException()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $this->expectException(WalletStateException::class);
        $machine->transition(WalletStatus::NORMAL);
    }

    public function testFullStatusCycleThroughAllStates()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);

        $machine->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $machine->getCurrentStatus());

        $machine->transition(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $machine->getCurrentStatus());

        $machine->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $machine->getCurrentStatus());

        $machine->transition(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $machine->getCurrentStatus());
    }

    public function testAssertCanTransitionByAmountNoChange()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $result = $machine->assertCanTransitionByAmount(100.0, 0.0, 0.0);

        $this->assertFalse($result['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['to_status']);
        $this->assertNotEmpty($result['message']);
    }

    public function testAssertCanTransitionByAmountNormalToPartiallyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $result = $machine->assertCanTransitionByAmount(100.0, 0.0, 50.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['from_status']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountNormalToFullyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::NORMAL);
        $result = $machine->assertCanTransitionByAmount(100.0, 0.0, 100.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['from_status']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountPartiallyFrozenToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $result = $machine->assertCanTransitionByAmount(100.0, 50.0, 0.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountPartiallyFrozenToFullyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $result = $machine->assertCanTransitionByAmount(100.0, 50.0, 100.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountFullyFrozenToPartiallyFrozen()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $result = $machine->assertCanTransitionByAmount(100.0, 100.0, 50.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountFullyFrozenToNormal()
    {
        $machine = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $result = $machine->assertCanTransitionByAmount(100.0, 100.0, 0.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['to_status']);
    }

    public function testApplyToWalletUpdatesBalanceAndFrozenAndStatus()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1,
            'balance' => 100.0,
            'frozen_amount' => 0.0,
            'available_amount' => 100.0,
            'status' => WalletStatus::NORMAL,
        ], false);

        $machine = WalletStateMachine::fromWallet($wallet);
        $result = $machine->applyToWallet($wallet, 100.0, 50.0);

        $this->assertTrue($result['changed']);
        $this->assertEquals(100.0, $wallet->balance);
        $this->assertEquals(50.0, $wallet->frozenAmount);
        $this->assertEquals(50.0, $wallet->availableAmount);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }

    public function testApplyToWalletNoStatusChange()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1,
            'balance' => 100.0,
            'frozen_amount' => 50.0,
            'available_amount' => 50.0,
            'status' => WalletStatus::PARTIALLY_FROZEN,
        ], false);

        $machine = WalletStateMachine::fromWallet($wallet);
        $result = $machine->applyToWallet($wallet, 200.0, 80.0);

        $this->assertFalse($result['changed']);
        $this->assertEquals(200.0, $wallet->balance);
        $this->assertEquals(80.0, $wallet->frozenAmount);
        $this->assertEquals(120.0, $wallet->availableAmount);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }

    public function testApplyToWalletWithProvidedTransition()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1,
            'balance' => 100.0,
            'frozen_amount' => 0.0,
            'status' => WalletStatus::NORMAL,
        ], false);

        $machine = WalletStateMachine::fromWallet($wallet);
        $transition = [
            'changed' => true,
            'from_status' => WalletStatus::NORMAL,
            'to_status' => WalletStatus::PARTIALLY_FROZEN,
            'message' => 'test transition',
        ];
        $result = $machine->applyToWallet($wallet, 100.0, 50.0, $transition);

        $this->assertEquals($transition, $result);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }

    public function testGetAllowedTransitionsForNormal()
    {
        $transitions = WalletStateMachine::getAllowedTransitions(WalletStatus::NORMAL);
        $this->assertContains(WalletStatus::PARTIALLY_FROZEN, $transitions);
        $this->assertContains(WalletStatus::FULLY_FROZEN, $transitions);
        $this->assertCount(2, $transitions);
    }

    public function testGetAllowedTransitionsForInvalidStatusReturnsEmpty()
    {
        $transitions = WalletStateMachine::getAllowedTransitions(999);
        $this->assertEmpty($transitions);
    }

    public function testDescribeStatusContainsStatusNameAndAmounts()
    {
        $description = WalletStateMachine::describeStatus(WalletStatus::NORMAL, 100.0, 0.0);
        $this->assertStringContainsString('正常', $description);
        $this->assertStringContainsString('100.00', $description);
        $this->assertStringContainsString('0.00', $description);
    }

    public function testDescribeStatusForFullyFrozen()
    {
        $description = WalletStateMachine::describeStatus(WalletStatus::FULLY_FROZEN, 500.0, 500.0);
        $this->assertStringContainsString('全额冻结', $description);
        $this->assertStringContainsString('500.00', $description);
    }

    public function testWalletStatusEnumValues()
    {
        $this->assertEquals(1, WalletStatus::NORMAL);
        $this->assertEquals(2, WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(3, WalletStatus::FULLY_FROZEN);
    }

    public function testWalletStatusGetName()
    {
        $this->assertEquals('正常', WalletStatus::getName(WalletStatus::NORMAL));
        $this->assertEquals('部分冻结', WalletStatus::getName(WalletStatus::PARTIALLY_FROZEN));
        $this->assertEquals('全额冻结', WalletStatus::getName(WalletStatus::FULLY_FROZEN));
        $this->assertEquals('未知状态', WalletStatus::getName(999));
    }

    public function testWalletStatusGetColor()
    {
        $this->assertEquals('green', WalletStatus::getColor(WalletStatus::NORMAL));
        $this->assertEquals('orange', WalletStatus::getColor(WalletStatus::PARTIALLY_FROZEN));
        $this->assertEquals('red', WalletStatus::getColor(WalletStatus::FULLY_FROZEN));
        $this->assertEquals('gray', WalletStatus::getColor(999));
    }

    public function testWalletStatusIsValid()
    {
        $this->assertTrue(WalletStatus::isValid(WalletStatus::NORMAL));
        $this->assertTrue(WalletStatus::isValid(WalletStatus::PARTIALLY_FROZEN));
        $this->assertTrue(WalletStatus::isValid(WalletStatus::FULLY_FROZEN));
        $this->assertFalse(WalletStatus::isValid(999));
    }

    public function testWalletToArrayIncludesStatusNameAndColor()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1,
            'balance' => 100.0,
            'frozen_amount' => 0.0,
            'status' => WalletStatus::NORMAL,
            'version' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ], false);

        $array = $wallet->toArray();
        $this->assertArrayHasKey('status_name', $array);
        $this->assertArrayHasKey('status_color', $array);
        $this->assertEquals('正常', $array['status_name']);
        $this->assertEquals('green', $array['status_color']);
    }

    public function testWalletCalculateAvailableUpdatesAvailableAndStatus()
    {
        $wallet = new Wallet([
            'balance' => 100.0,
            'frozen_amount' => 30.0,
        ], false);

        $wallet->calculateAvailable();
        $this->assertEquals(70.0, $wallet->availableAmount);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }
}
