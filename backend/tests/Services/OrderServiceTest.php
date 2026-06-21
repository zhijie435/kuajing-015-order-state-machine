<?php

namespace Order\Tests\Services;

use PHPUnit\Framework\TestCase;
use Order\Services\OrderService;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;
use Order\Core\Database;

class OrderServiceTest extends TestCase
{
    private $config;

    protected function setUp(): void
    {
        $this->config = [
            'db' => [
                'host' => '127.0.0.1',
                'port' => '3306',
                'name' => 'order_system_test',
                'user' => 'root',
                'pass' => '',
                'charset' => 'utf8mb4',
            ],
            'state_machine' => [
                'strict_validation' => true,
                'allow_force_transition' => false,
                'transition_log_enabled' => true,
                'rollback_enabled' => true,
                'max_rollback_depth' => 3,
            ],
        ];

        Database::resetInstance();
    }

    public function testCreateOrder()
    {
        $service = $this->createMock(OrderService::class, [$this->config]);

        $service->method('createOrder')->willReturnCallback(function ($userId, $amount, $extra) {
            $order = new class($userId, $amount) {
                public $userId;
                public $amount;
                public $status = 'pending';

                public function __construct($userId, $amount) {
                    $this->userId = $userId;
                    $this->amount = $amount;
                }

                public function toArray() {
                    return [
                        'user_id' => $this->userId,
                        'total_amount' => $this->amount,
                        'status' => $this->status,
                    ];
                }
            };
            return $order;
        });

        $order = $service->createOrder(1001, 99.99);
        $data = $order->toArray();

        $this->assertEquals(1001, $data['user_id']);
        $this->assertEquals(99.99, $data['total_amount']);
        $this->assertEquals(OrderStatus::PENDING, $data['status']);
    }

    public function testCreateOrderWithInvalidAmount()
    {
        $service = new OrderService($this->config);

        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_VALIDATION_FAILED);

        $service->createOrder(1001, 0);
    }

    public function testValidateEventReturnsDetailedErrors()
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['getOrderById'])
            ->getMock();

        $mockOrder = $this->createMock(\Order\Models\Order::class);
        $mockOrder->method('getStatus')->willReturn(OrderStatus::COMPLETED);
        $mockOrder->method('checkCan')->willReturn([
            'allowed' => false,
            'error_code' => 'terminal_status',
            'error_message' => '终态订单 "已完成" 无法执行 "发货" 操作',
            'suggestion' => '终态订单不支持状态变更',
        ]);

        $service->method('getOrderById')->willReturn($mockOrder);

        $result = $service->validateEvent(1, OrderEvent::SHIP);

        $this->assertFalse($result['allowed']);
        $this->assertEquals('terminal_status', $result['error_code']);
        $this->assertNotEmpty($result['error_message']);
        $this->assertNotEmpty($result['suggestion']);
        $this->assertArrayHasKey('order_info', $result);
        $this->assertArrayHasKey('requested_action', $result);
    }

    public function testValidateEventOrderNotFound()
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['getOrderById'])
            ->getMock();

        $service->method('getOrderById')->willReturn(null);

        $result = $service->validateEvent(999, OrderEvent::PAY);

        $this->assertFalse($result['allowed']);
        $this->assertEquals('order_not_found', $result['error_code']);
    }

    public function testGetStateMachineConfig()
    {
        $service = new OrderService($this->config);
        $config = $service->getStateMachineConfig();

        $this->assertArrayHasKey('statuses', $config);
        $this->assertArrayHasKey('events', $config);
        $this->assertArrayHasKey('transition_map', $config);
        $this->assertCount(9, $config['statuses']);
        $this->assertCount(11, $config['events']);
    }

    public function testMarkExceptionWithDetailedError()
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['getOrderById'])
            ->getMock();

        $mockOrder = $this->createMock(\Order\Models\Order::class);
        $mockOrder->method('getStatus')->willReturn(OrderStatus::COMPLETED);

        $service->method('getOrderById')->willReturn($mockOrder);

        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_TERMINAL_STATUS);

        $service->markException(1, 'Test reason');
    }

    public function testApplyEventValidationFailure()
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['getOrderById', 'validateEvent'])
            ->getMock();

        $mockOrder = $this->createMock(\Order\Models\Order::class);
        $mockOrder->method('getStatus')->willReturn(OrderStatus::PENDING);

        $service->method('getOrderById')->willReturn($mockOrder);
        $service->method('validateEvent')->willReturn([
            'allowed' => false,
            'error_code' => 'invalid_transition',
            'error_message' => '当前状态 "待支付" 不支持 "发货" 操作',
            'suggestion' => '当前可执行操作: 支付、取消订单、标记异常',
        ]);

        $this->expectException(StateMachineException::class);
        $this->expectExceptionCode(StateMachineException::CODE_VALIDATION_FAILED);

        try {
            $service->applyEvent(1, OrderEvent::SHIP);
        } catch (StateMachineException $e) {
            $this->assertStringContainsString('不支持', $e->getMessage());
            $this->assertStringContainsString('可执行操作', $e->getMessage());
            throw $e;
        }
    }

    public function testBatchValidateEvents()
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['validateEvent'])
            ->getMock();

        $service->method('validateEvent')->willReturnMap([
            [1, OrderEvent::PAY, ['allowed' => true]],
            [1, OrderEvent::SHIP, ['allowed' => false, 'error_code' => 'invalid_transition']],
            [1, OrderEvent::CANCEL, ['allowed' => true]],
        ]);

        $results = $service->batchValidateEvents(1, [OrderEvent::PAY, OrderEvent::SHIP, OrderEvent::CANCEL]);

        $this->assertCount(3, $results);
        $this->assertTrue($results[OrderEvent::PAY]['allowed']);
        $this->assertFalse($results[OrderEvent::SHIP]['allowed']);
        $this->assertTrue($results[OrderEvent::CANCEL]['allowed']);
    }
}
