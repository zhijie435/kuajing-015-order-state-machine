<?php

use PHPUnit\Framework\TestCase;
use Order\Core\StateMachine;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;

class StateConsistencyTest extends TestCase
{
    public function testStatusSnapshotPersistence()
    {
        $sm = new StateMachine(OrderStatus::PENDING);

        $sm->apply(OrderEvent::PAY);
        $sm->apply(OrderEvent::SHIP);

        $snapshot = $sm->getSnapshot();

        $this->assertEquals(OrderStatus::SHIPPED, $snapshot['current_status']);
        $this->assertEquals(OrderStatus::PAID, $snapshot['previous_status']);
        $this->assertCount(2, $snapshot['rollback_stack']);

        $sm2 = new StateMachine(OrderStatus::PENDING);
        $sm2->restoreFromSnapshot($snapshot);

        $this->assertEquals(OrderStatus::SHIPPED, $sm2->getCurrentStatus());
        $this->assertEquals(OrderStatus::PAID, $sm2->getPreviousStatus());
        $this->assertCount(2, $sm2->getRollbackStack());
        $this->assertTrue($sm2->can(OrderEvent::ROLLBACK));
    }

    public function testRestoreSnapshotPreservesRollbackCapability()
    {
        $sm = new StateMachine(OrderStatus::PENDING, [
            'rollback_enabled' => true,
            'max_rollback_depth' => 5,
        ]);

        $sm->apply(OrderEvent::PAY);
        $sm->apply(OrderEvent::SHIP);
        $sm->apply(OrderEvent::CONFIRM_RECEIPT);

        $snapshot = $sm->getSnapshot();
        $this->assertCount(3, $snapshot['rollback_stack']);

        $sm2 = new StateMachine(OrderStatus::PENDING, [
            'rollback_enabled' => true,
            'max_rollback_depth' => 5,
        ]);
        $sm2->restoreFromSnapshot($snapshot);

        $this->assertTrue($sm2->can(OrderEvent::ROLLBACK));
        $this->assertCount(3, $sm2->getRollbackStack());

        $result = $sm2->rollback();
        $this->assertEquals(OrderStatus::DELIVERED, $result->getFromStatus());
        $this->assertEquals(OrderStatus::SHIPPED, $result->getToStatus());
        $this->assertCount(2, $sm2->getRollbackStack());
    }

    public function testExceptionStateSnapshotAndRestore()
    {
        $sm = new StateMachine(OrderStatus::PAID);
        $sm->apply(OrderEvent::MARK_EXCEPTION, null, 'admin', 'Payment anomaly detected');

        $snapshot = $sm->getSnapshot();

        $this->assertEquals(OrderStatus::EXCEPTION, $snapshot['current_status']);
        $this->assertEquals('Payment anomaly detected', $snapshot['exception_reason']);

        $sm2 = new StateMachine(OrderStatus::PENDING);
        $sm2->restoreFromSnapshot($snapshot);

        $this->assertEquals(OrderStatus::EXCEPTION, $sm2->getCurrentStatus());
        $this->assertEquals('Payment anomaly detected', $sm2->getExceptionReason());
        $this->assertFalse($sm2->can(OrderEvent::SHIP));
        $this->assertTrue($sm2->can(OrderEvent::RESOLVE_EXCEPTION));
    }

    public function testSyncStatusDoesNotAffectRollbackStack()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $sm->apply(OrderEvent::PAY);
        $sm->apply(OrderEvent::SHIP);

        $initialStack = $sm->getRollbackStack();
        $this->assertCount(2, $initialStack);

        $sm->syncStatus(OrderStatus::EXCEPTION);

        $this->assertEquals(OrderStatus::EXCEPTION, $sm->getCurrentStatus());
        $this->assertCount(2, $sm->getRollbackStack());
    }

    public function testPartialSnapshotData()
    {
        $sm = new StateMachine(OrderStatus::PENDING);
        $sm->apply(OrderEvent::PAY);

        $partialSnapshot = [
            'current_status' => OrderStatus::SHIPPED,
        ];

        $sm2 = new StateMachine(OrderStatus::PENDING);
        $sm2->restoreFromSnapshot($partialSnapshot);

        $this->assertEquals(OrderStatus::SHIPPED, $sm2->getCurrentStatus());
        $this->assertEquals(OrderStatus::PENDING, $sm2->getPreviousStatus());
        $this->assertEmpty($sm2->getRollbackStack());
    }

    public function testInvalidStatusInSnapshotIsIgnored()
    {
        $sm = new StateMachine(OrderStatus::PENDING);

        $invalidSnapshot = [
            'current_status' => 'invalid_status',
            'previous_status' => 'another_invalid',
        ];

        $sm->restoreFromSnapshot($invalidSnapshot);

        $this->assertEquals(OrderStatus::PENDING, $sm->getCurrentStatus());
    }

    public function testSnapshotIntegrityAfterMultipleTransitions()
    {
        $sm = new StateMachine(OrderStatus::PENDING, [
            'rollback_enabled' => true,
            'max_rollback_depth' => 10,
        ]);

        $events = [
            OrderEvent::PAY,
            OrderEvent::APPLY_REFUND,
            OrderEvent::REJECT_REFUND,
            OrderEvent::SHIP,
            OrderEvent::CONFIRM_RECEIPT,
        ];

        foreach ($events as $event) {
            $sm->apply($event);
        }

        $this->assertEquals(OrderStatus::DELIVERED, $sm->getCurrentStatus());

        $snapshot = $sm->getSnapshot();
        $this->assertCount(5, $snapshot['rollback_stack']);

        $sm2 = new StateMachine(OrderStatus::PENDING, [
            'rollback_enabled' => true,
            'max_rollback_depth' => 10,
        ]);
        $sm2->restoreFromSnapshot($snapshot);

        $this->assertEquals(OrderStatus::DELIVERED, $sm2->getCurrentStatus());
        $this->assertEquals(OrderStatus::SHIPPED, $sm2->getPreviousStatus());

        $this->assertTrue($sm2->can(OrderEvent::COMPLETE));
        $this->assertTrue($sm2->can(OrderEvent::APPLY_REFUND));
        $this->assertTrue($sm2->can(OrderEvent::ROLLBACK));
    }

    public function testRollbackStackSizeLimitInSnapshot()
    {
        $sm = new StateMachine(OrderStatus::PENDING, [
            'rollback_enabled' => true,
            'max_rollback_depth' => 3,
        ]);

        $sm->apply(OrderEvent::PAY);
        $sm->apply(OrderEvent::SHIP);
        $sm->apply(OrderEvent::CONFIRM_RECEIPT);

        $snapshot = $sm->getSnapshot();
        $this->assertCount(3, $snapshot['rollback_stack']);

        $sm->apply(OrderEvent::COMPLETE);

        $snapshot2 = $sm->getSnapshot();
        $this->assertCount(3, $snapshot2['rollback_stack']);
    }

    public function testCheckCanProvidesCorrectSuggestions()
    {
        $sm = new StateMachine(OrderStatus::PENDING);

        $result = $sm->checkCan(OrderEvent::SHIP);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('待支付', $result['error_message']);
        $this->assertStringContainsString('支付', $result['suggestion']);
        $this->assertStringContainsString('取消订单', $result['suggestion']);
        $this->assertStringContainsString('标记异常', $result['suggestion']);
    }

    public function testExceptionStateCheckCan()
    {
        $sm = new StateMachine(OrderStatus::PAID);
        $sm->apply(OrderEvent::MARK_EXCEPTION, null, 'admin', 'Test');

        $result = $sm->checkCan(OrderEvent::SHIP);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('exception_state', $result['error_code']);
        $this->assertStringContainsString('异常状态', $result['error_message']);
        $this->assertStringContainsString('解决异常', $result['suggestion']);

        $result2 = $sm->checkCan(OrderEvent::RESOLVE_EXCEPTION);
        $this->assertTrue($result2['allowed']);
    }

    public function testTerminalStatusCheckCan()
    {
        $sm = new StateMachine(OrderStatus::COMPLETED);

        $result = $sm->checkCan(OrderEvent::MARK_EXCEPTION);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('terminal_status', $result['error_code']);
        $this->assertStringContainsString('终态', $result['error_message']);
    }

    public function testRollbackCheckCanReturnsDetailedErrors()
    {
        $sm1 = new StateMachine(OrderStatus::PENDING, ['rollback_enabled' => false]);
        $result = $sm1->checkCan(OrderEvent::ROLLBACK);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('rollback_disabled', $result['error_code']);

        $sm2 = new StateMachine(OrderStatus::PENDING, ['rollback_enabled' => true]);
        $result2 = $sm2->checkCan(OrderEvent::ROLLBACK);
        $this->assertFalse($result2['allowed']);
        $this->assertEquals('no_rollback_history', $result2['error_code']);

        $sm3 = new StateMachine(OrderStatus::PENDING, [
            'rollback_enabled' => true,
            'max_rollback_depth' => 1,
        ]);
        $sm3->apply(OrderEvent::PAY);
        $sm3->apply(OrderEvent::SHIP);
        $result3 = $sm3->checkCan(OrderEvent::ROLLBACK);
        $this->assertFalse($result3['allowed']);
        $this->assertEquals('rollback_depth_exceeded', $result3['error_code']);
    }
}
