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
            throw StateMachineException::validationFailed('订单不存在');
        }

        $validationResult = $order->checkCan($event);
        if (!$validationResult['allowed']) {
            throw StateMachineException::validationFailed(
                $validationResult['error_message'] . ' ' . ($validationResult['suggestion'] ?? '')
            );
        }

        return $order->apply($event, $operatorId, $remark);
    }

    public function validateEvent(int $orderId, string $event): array
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            return [
                'allowed' => false,
                'error_code' => 'order_not_found',
                'error_message' => '订单不存在',
                'suggestion' => '请检查订单ID是否正确',
            ];
        }

        $validationResult = $order->checkCan($event);

        if (!$validationResult['allowed']) {
            $eventLabel = OrderEvent::getLabel($event);
            $currentStatusLabel = OrderStatus::getLabel($order->getStatus());

            $validationResult['order_info'] = [
                'order_id' => $orderId,
                'order_no' => $order->getOrderNo(),
                'current_status' => $order->getStatus(),
                'current_status_label' => $currentStatusLabel,
            ];

            $validationResult['requested_action'] = [
                'event' => $event,
                'event_label' => $eventLabel,
            ];
        }

        return $validationResult;
    }

    public function batchValidateEvents(int $orderId, array $events): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[$event] = $this->validateEvent($orderId, $event);
        }
        return $results;
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

    public function listOrders(int $page = 1, int $pageSize = 20, string $status = '', int $userId = 0, array $filters = []): array
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

        if (!empty($filters['audit_status'])) {
            $where[] = 'audit_status = ?';
            $params[] = $filters['audit_status'];
        }

        if (!empty($filters['exception_type'])) {
            $where[] = 'exception_type = ?';
            $params[] = $filters['exception_type'];
        }

        if (isset($filters['has_exception']) && $filters['has_exception'] !== '') {
            if ($filters['has_exception'] === true || $filters['has_exception'] === 'true' || $filters['has_exception'] === '1') {
                $where[] = '(status = ? OR exception_level > 0)';
                $params[] = \Order\Enums\OrderStatus::EXCEPTION;
            } elseif ($filters['has_exception'] === false || $filters['has_exception'] === 'false' || $filters['has_exception'] === '0') {
                $where[] = '(status != ? AND exception_level = 0)';
                $params[] = \Order\Enums\OrderStatus::EXCEPTION;
            }
        }

        if (!empty($filters['exception_level'])) {
            $where[] = 'exception_level >= ?';
            $params[] = (int) $filters['exception_level'];
        }

        if (isset($filters['rollback_protected']) && $filters['rollback_protected'] !== '') {
            $where[] = 'rollback_protected = ?';
            $params[] = ($filters['rollback_protected'] ? 1 : 0);
        }

        if (!empty($filters['writeback_status'])) {
            $where[] = 'writeback_status = ?';
            $params[] = $filters['writeback_status'];
        }

        if (!empty($filters['min_amount'])) {
            $where[] = 'total_amount >= ?';
            $params[] = (float) $filters['min_amount'];
        }

        if (!empty($filters['max_amount'])) {
            $where[] = 'total_amount <= ?';
            $params[] = (float) $filters['max_amount'];
        }

        if (!empty($filters['keyword'])) {
            $where[] = '(order_no LIKE ? OR CAST(user_id AS CHAR) LIKE ?)';
            $keyword = '%' . $filters['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
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
            $order = $this->createOrderFromRow($row);
            $orders[] = $order->toArray();
        }

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => (int) ceil($total / $pageSize),
            'items' => $orders,
            'filters' => $filters,
        ];
    }

    private function createOrderFromRow(array $row): Order
    {
        $order = new Order(
            $row['order_no'],
            (int) $row['user_id'],
            (float) $row['total_amount'],
            $row['status'],
            $this->config,
            (int) $row['id']
        );

        $reflection = new \ReflectionClass($order);

        $createdAtProp = $reflection->getProperty('createdAt');
        $createdAtProp->setAccessible(true);
        $createdAtProp->setValue($order, $row['created_at']);

        $updatedAtProp = $reflection->getProperty('updatedAt');
        $updatedAtProp->setAccessible(true);
        $updatedAtProp->setValue($order, $row['updated_at']);

        $extraData = [];
        if (!empty($row['extra_data'])) {
            $decoded = json_decode($row['extra_data'], true);
            if (is_array($decoded)) {
                $extraData = $decoded;
            }
        }

        $extraDataProp = $reflection->getProperty('extraData');
        $extraDataProp->setAccessible(true);
        $extraDataProp->setValue($order, $extraData);

        $stateMachine = $order->getStateMachine();
        if (isset($extraData['_state_snapshot']) && is_array($extraData['_state_snapshot'])) {
            $stateMachine->restoreFromSnapshot($extraData['_state_snapshot']);
        }

        $isDirtyProp = $reflection->getProperty('isDirty');
        $isDirtyProp->setAccessible(true);
        $isDirtyProp->setValue($order, false);

        return $order;
    }

    public function getOrderDetail(int $orderId): ?array
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            return null;
        }

        $detail = $order->toArray();
        $detail['status_logs'] = $this->getOrderStatusLogs($orderId);
        $detail['consistency_check'] = $order->getStatusConsistencyCheck();

        return $detail;
    }

    public function checkStatusConsistency(int $orderId): array
    {
        $order = $this->getOrderById($orderId);
        if ($order === null) {
            throw StateMachineException::validationFailed('Order not found');
        }

        return $order->getStatusConsistencyCheck();
    }
}
