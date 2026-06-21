<?php

use Dealer\Wallet\StateMachine\WalletStateMachine;
use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Exception\WalletStateException;
use Dealer\Wallet\Model\Wallet;

class WalletStateMachineTest extends TestCase
{
    public function testNormalCanTransitionToPartiallyFrozen(): void
    {
        $wallet = new Wallet(['dealer_id' => 1, 'balance' => 1000, 'frozen_amount' => 0]);
        $sm = WalletStateMachine::fromWallet($wallet);
        $this->assertTrue($sm->canTransitionTo(WalletStatus::PARTIALLY_FROZEN));
    }

    public function testCalculateStatusFromAmounts(): void
    {
        $this->assertSame(WalletStatus::NORMAL, WalletStateMachine::calculateStatus(1000, 0));
        $this->assertSame(WalletStatus::PARTIALLY_FROZEN, WalletStateMachine::calculateStatus(1000, 500));
        $this->assertSame(WalletStatus::FULLY_FROZEN, WalletStateMachine::calculateStatus(1000, 1000));
    }

    public function testFrozenMoreThanBalanceThrowsStateException(): void
    {
        try {
            WalletStateMachine::validateAmounts(1000, 2000);
            $this->assertTrue(false);
        } catch (WalletStateException $e) {
            $this->assertTrue(true);
        }
    }

    public function testApplyToWallet(): void
    {
        $wallet = new Wallet(['dealer_id' => 1, 'balance' => 1000, 'frozen_amount' => 0, 'status' => WalletStatus::NORMAL]);
        $sm = WalletStateMachine::fromWallet($wallet);
        $result = $sm->applyToWallet($wallet, 1000, 500);
        $this->assertTrue($result['changed']);
        $this->assertSame(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }
}
