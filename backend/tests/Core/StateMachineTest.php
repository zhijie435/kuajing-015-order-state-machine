<?php

namespace Order\Tests\Core;

use PHPUnit\Framework\TestCase;
use Order\Core\StateMachine;
use Order\Core\Transition;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;

class StateMachineTest extends TestCase
{
    public function testInitialState()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $this->assertEquals(OrderStatus::PENDING, $sm->getCurrentStatus());
    }

    public function testInvalidInitialStatus()
    {
        $this->expectException(StateMachineException::class);
        new StateMachine('invalid_status');
    }

    public function testCanTransition()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $this->assertTrue($sm->can(OrderEvent::PAY));
        $this->assertTrue($sm->can(OrderEvent::CANCEL));
        $this->assertFalse($sm->can(OrderEvent::SHIP));
    }

    public function testApplyTransition()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $result = $sm->apply(OrderEvent::PAY);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(OrderStatus::PENDING, $result->getFromStatus());
        $this->assertEquals(OrderStatus::PAID, $result->getToStatus());
        $this->assertEquals(OrderEvent::PAY, $result->getEvent());
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());
    }

    public function testInvalidTransition()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_INVALID_TRANSITION);
        $sm->apply(OrderEvent::SHIP);
    }

    public function testNormalFlow()
    {
        $sm = new StateMachine(OrderStatus::PENDING);

        $sm->apply(OrderEvent::PAY);
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());

        $sm->apply(OrderEvent::SHIP);
        $this->assertEquals(OrderStatus::SHIPPED, $sm->getCurrentStatus());

        $sm->apply(OrderEvent::CONFIRM_RECEIPT);
        $this->assertEquals(OrderStatus::DELIVERED, $sm->getCurrentStatus());

        $sm->apply(OrderEvent::COMPLETE);
        $this->assertEquals(OrderStatus::COMPLETED, $sm->getCurrentStatus());
    }

    public function testTerminalStatusCannotTransition()
    {
        $sm = new StateMachine(OrderStatus::COMPLETED);
        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_TERMINAL_STATUS);
        $sm->apply(OrderEvent::CANCEL);
    }

    public function testMarkException()
    {
        $sm = new StateMachine(OrderStatus::PAID);
        $result = $sm->apply(OrderEvent::MARK_EXCEPTION, null, 'admin', 'Payment issue');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(OrderStatus::EXCEPTION, $sm->getCurrentStatus());
        $this->assertEquals('Payment issue', $sm->getExceptionReason());
    }

    public function testExceptionStateBlocksOperations()
    {
        $sm = new StateMachine(OrderStatus::PAID);
        $sm->apply(OrderEvent::MARK_EXCEPTION, null, 'admin', 'Test');

        $this->assertFalse($sm->can(OrderEvent::SHIP));
        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_EXCEPTION_STATE);
        $sm->apply(OrderEvent::SHIP);
    }

    public function testResolveException()
    {
        $sm = new StateMachine(OrderStatus::PAID);
        $sm->apply(OrderEvent::MARK_EXCEPTION, null, 'admin', 'Test');
        $this->assertEquals(OrderStatus::EXCEPTION, $sm->getCurrentStatus());

        $result = $sm->resolveException(OrderStatus::PAID);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());
        $this->assertNull($sm->getExceptionReason());
    }

    public function testRollback()
    {
        $sm = new StateMachine(OrderStatus::PENDING, ['rollback_enabled' => true, 'max_rollback_depth' => 3]);

        $sm->apply(OrderEvent::PAY);
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());

        $sm->apply(OrderEvent::SHIP);
        $this->assertEquals(OrderStatus::SHIPPED, $sm->getCurrentStatus());

        $this->assertTrue($sm->can(OrderEvent::ROLLBACK));

        $result = $sm->rollback();
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(OrderStatus::SHIPPED, $result->getFromStatus());
        $this->assertEquals(OrderStatus::PAID, $result->getToStatus());
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());
    }

    public function testRollbackDisabled()
    {
        $sm = new StateMachine(OrderStatus::PENDING, ['rollback_enabled' => false]);
        $sm->apply(OrderEvent::PAY);

        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_ROLLBACK_DISABLED);
        $sm->rollback();
    }

    public function testNoRollbackHistory()
    {
        $sm = new StateMachine(OrderStatus::PENDING, ['rollback_enabled' => true]);
        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_NO_ROLLBACK_HISTORY);
        $sm->rollback();
    }

    public function testCheckCanReturnsDetailedErrors()
    {
        $sm = new StateMachine(OrderStatus::PENDING);

        $result = $sm->checkCan(OrderEvent::SHIP);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('invalid_transition', $result['error_code']);
        $this->assertNotEmpty($result['error_message']);
        $this->assertNotEmpty($result['suggestion']);
    }

    public function testGetAvailableEvents()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $events = $sm->getAvailableEvents();

        $this->assertContains(OrderEvent::PAY, $events);
        $this->assertContains(OrderEvent::CANCEL, $events);
        $this->assertContains(OrderEvent::MARK_EXCEPTION, $events);
        $this->assertNotContains(OrderEvent::SHIP, $events);
    }

    public function testSnapshotAndRestore()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $sm->apply(OrderEvent::PAY);
        $sm->apply(OrderEvent::SHIP);

        $snapshot = $sm->getSnapshot();
        $this->assertEquals(OrderStatus::SHIPPED, $snapshot['current_status']);
        $this->assertCount(2, $snapshot['rollback_stack']);

        $sm2 = new StateMachine(OrderStatus::PENDING);
        $sm2->restoreFromSnapshot($snapshot);

        $this->assertEquals(OrderStatus::SHIPPED, $sm2->getCurrentStatus());
        $this->assertCount(2, $sm2->getRollbackStack());
        $this->assertTrue($sm2->can(OrderEvent::ROLLBACK));
    }

    public function testSyncStatus()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $sm->syncStatus(OrderStatus::PAID);
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());
    }

    public function testGetValidationErrors()
    {
        $sm = new StateMachine(OrderStatus::COMPLETED);

        $errors = $sm->getValidationErrors(OrderEvent::SHIP);
        $this->assertNotEmpty($errors);
        $this->assertEquals('terminal_status', $errors['code']);
        $this->assertNotEmpty($errors['message']);
        $this->assertNotEmpty($errors['suggestion']);
    }

    public function testRefundFlow()
    {
        $sm = new StateMachine(OrderStatus::PAID);

        $sm->apply(OrderEvent::APPLY_REFUND);
        $this->assertEquals(OrderStatus::REFUNDING, $sm->getCurrentStatus());

        $sm->apply(OrderEvent::APPROVE_REFUND);
        $this->assertEquals(OrderStatus::REFUNDED, $sm->getCurrentStatus());
        $this->assertTrue(OrderStatus::isTerminal($sm->getCurrentStatus()));
    }

    public function testRejectRefund()
    {
        $sm = new StateMachine(OrderStatus::PAID);
        $sm->apply(OrderEvent::APPLY_REFUND);
        $this->assertEquals(OrderStatus::REFUNDING, $sm->getCurrentStatus());

        $sm->apply(OrderEvent::REJECT_REFUND);
        $this->assertEquals(OrderStatus::PAID, $sm->getCurrentStatus());
    }

    public function testCustomTransitionWithGuard()
    {
        $sm = new StateMachine(OrderStatus::PAID);

        $context = new class {
            public $amount = 0;
        };

        $guard = function ($ctx) {
            return $ctx->amount > 0;
        };

        $transition = new Transition(
            OrderStatus::PAID,
            'custom_event',
            OrderStatus::SHIPPED,
            $guard
        );
        $sm->addTransition($transition);

        $context->amount = 0;
        $this->assertFalse($sm->can('custom_event', $context));

        $context->amount = 100;
        $this->assertTrue($sm->can('custom_event', $context));
    }

    public function testHistoryLogging()
    {
        $sm = new StateMachine(OrderStatus::PENDING, ['transition_log_enabled' => true]);

        $sm->apply(OrderEvent::PAY);
        $sm->apply(OrderEvent::SHIP);

        $history = $sm->getHistory();
        $this->assertCount(2, $history);
        $this->assertEquals(OrderEvent::PAY, $history[0]->getEvent());
        $this->assertEquals(OrderEvent::SHIP, $history[1]->getEvent());
    }

    public function testTransitionFailsAndRollsBack()
    {
        $sm = new StateMachine(OrderStatus::PENDING);

        $transition = new Transition(
            OrderStatus::PAID,
            OrderEvent::SHIP,
            OrderStatus::SHIPPED,
            null,
            function () {
                throw new \RuntimeException('Failed before transition');
            }
        );

        $sm2 = new StateMachine(OrderStatus::PENDING);
        $sm2->addTransition($transition);

        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_TRANSACTION_FAILED);

        try {
            $sm2->apply(OrderEvent::PAY);
            $sm2->apply(OrderEvent::SHIP);
        } catch (StateMachineException $e) {
            $this->assertEquals(OrderStatus::PAID, $sm2->getCurrentStatus());
            throw $e;
        }
    }
}
