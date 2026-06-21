<?php

namespace Order\Services;

use Order\Core\StateMachine;
use Order\Core\TransitionResult;
use Order\Models\Order;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;

class OrderService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function createOrder(int $userId, float $totalAmount, array $extraData = []): Order
    {
        if ($totalAmount <= 0) {
            throw StateMachineException::validationFailed('Order amount must be greater than 0');
        }

        $orderNo = Order::generateOrderNo();
        $order = new Order($orderNo, $userId, $totalAmount, OrderStatus::PENDING, $this->config);

        foreach ($extraData as $key => $value) {
            $order->setExtraData($key, $value);
        }

        $order->save();

        return $order;
    }

    public function getOrderById(int $orderId): ?Order
    {
        return Order::findById($orderId, $this->config);
    }

    public function getOrderByNo(string $orderNo): ?Order
    {
        return Order::findByOrderNo($orderNo, $this->config);
    }

    public function applyEvent(int $orderId, string $event, string $operatorId = '', string $remark = ''): TransitionResult
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        if (!$order->can($event)) {
            throw StateMachineException::invalidTransition($order->getStatus(), $event);
        }

        return $order->apply($event, $operatorId, $remark);
    }

    public function pay(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::PAY, $operatorId, $remark);
    }

    public function ship(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::SHIP, $operatorId, $remark);
    }

    public function confirmReceipt(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::CONFIRM_RECEIPT, $operatorId, $remark);
    }

    public function complete(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::COMPLETE, $operatorId, $remark);
    }

    public function cancel(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::CANCEL, $operatorId, $remark);
    }

    public function applyRefund(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::APPLY_REFUND, $operatorId, $remark);
    }

    public function approveRefund(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::APPROVE_REFUND, $operatorId, $remark);
    }

    public function rejectRefund(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->applyEvent($orderId, OrderEvent::REJECT_REFUND, $operatorId, $remark);
    }

    public function markException(int $orderId, string $reason, string $operatorId = ''): TransitionResult
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        if (OrderStatus::isTerminal($order->getStatus())) {
            throw StateMachineException::terminalStatus($order->getStatus());
        }

        return $order->markException($reason, $operatorId);
    }

    public function resolveException(int $orderId, string $targetStatus, string $operatorId = '', string $remark = ''): TransitionResult
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        if ($order->getStatus() !== OrderStatus::EXCEPTION) {
            throw StateMachineException::invalidTransition($order->getStatus(), OrderEvent::RESOLVE_EXCEPTION);
        }

        if (!OrderStatus::exists($targetStatus)) {
            throw StateMachineException::invalidStatus($targetStatus);
        }

        return $order->resolveException($targetStatus, $operatorId, $remark);
    }

    public function rollback(int $orderId, string $operatorId = '', string $remark = ''): TransitionResult
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        return $order->rollback($operatorId, $remark);
    }

    public function getOrderStatusLogs(int $orderId): array
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        $logs = $order->getTransitionHistory();
        foreach ($logs as &$log) {
            $log['from_status_label'] = OrderStatus::getLabel($log['from_status']);
            $log['to_status_label'] = OrderStatus::getLabel($log['to_status']);
            $log['event_label'] = OrderEvent::getLabel($log['event']);
            if (!empty($log['context'])) {
                $log['context'] = json_decode($log['context'], true);
            }
        }

        return $logs;
    }

    public function getAvailableEvents(int $orderId): array
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        $events = $order->getAvailableEvents();
        $result = [];
        foreach ($events as $event) {
            $result[] = [
                'event' => $event,
                'label' => OrderEvent::getLabel($event),
            ];
        }

        return $result;
    }

    public function getStateMachineConfig(): array
    {
        $stateMachine = new StateMachine(OrderStatus::PENDING, $this->config['state_machine'] ?? []);
        $transitionMap = $stateMachine->getTransitionMap();

        $formattedMap = [];
        foreach ($transitionMap as $from => $transitions) {
            $formattedMap[] = [
                'from_status' => $from,
                'from_status_label' => OrderStatus::getLabel($from),
                'transitions' => $transitions,
            ];
        }

        return [
            'statuses' => array_map(function ($status) {
                return [
                    'status' => $status,
                    'label' => OrderStatus::getLabel($status),
                    'color' => OrderStatus::getColor($status),
                    'is_terminal' => OrderStatus::isTerminal($status),
                ];
            }, OrderStatus::ALL),
            'events' => array_map(function ($event) {
                return [
                    'event' => $event,
                    'label' => OrderEvent::getLabel($event),
                ];
            }, OrderEvent::ALL),
            'transition_map' => $formattedMap,
            'config' => $this->config['state_machine'] ?? [],
        ];
    }

    public function listOrders(int $page = 1, int $pageSize = 20, string $status = '', int $userId = 0): array
    {
        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        if ($userId > 0) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $pageSize;

        $db = \Order\Core\Database::getInstance($this->config['db'] ?? []);

        $countSql = "SELECT COUNT(*) as total FROM orders {$whereSql}";
        $countRow = $db->fetchOne($countSql, $params);
        $total = (int) ($countRow['total'] ?? 0);

        $sql = "SELECT * FROM orders {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$pageSize}";
        $rows = $db->fetchAll($sql, $params);

        $orders = [];
        foreach ($rows as $row) {
            $order = Order::findById((int) $row['id'], $this->config);
            if ($order !== null) {
                $orders[] = $order->toArray();
            }
        }

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => (int) ceil($total / $pageSize),
            'items' => $orders,
        ];
    }
}
