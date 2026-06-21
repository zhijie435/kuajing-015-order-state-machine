<?php

namespace Order\Controllers;

use Order\Services\OrderService;
use Order\Exceptions\StateMachineException;
use Order\Enums\OrderEvent;

class OrderController
{
    private OrderService $orderService;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->orderService = new OrderService($config);
    }

    public function handleRequest(string $action, array $params): array
    {
        try {
            switch ($action) {
                case 'create':
                    return $this->create($params);
                case 'list':
                    return $this->list($params);
                case 'detail':
                    return $this->detail($params);
                case 'validate':
                    return $this->validate($params);
                case 'apply_event':
                    return $this->applyEvent($params);
                case 'pay':
                    return $this->pay($params);
                case 'ship':
                    return $this->ship($params);
                case 'confirm_receipt':
                    return $this->confirmReceipt($params);
                case 'complete':
                    return $this->complete($params);
                case 'cancel':
                    return $this->cancel($params);
                case 'apply_refund':
                    return $this->applyRefund($params);
                case 'approve_refund':
                    return $this->approveRefund($params);
                case 'reject_refund':
                    return $this->rejectRefund($params);
                case 'mark_exception':
                    return $this->markException($params);
                case 'resolve_exception':
                    return $this->resolveException($params);
                case 'rollback':
                    return $this->rollback($params);
                case 'status_logs':
                    return $this->statusLogs($params);
                case 'state_machine_config':
                    return $this->stateMachineConfig();
                case 'check_consistency':
                    return $this->checkConsistency($params);
                default:
                    return ApiResponse::error('Invalid action', 40001);
            }
        } catch (StateMachineException $e) {
            return ApiResponse::fromStateMachineException($e);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 50000);
        }
    }

    private function create(array $params): array
    {
        $userId = (int) ($params['user_id'] ?? 0);
        $totalAmount = (float) ($params['total_amount'] ?? 0);
        $extraData = $params['extra_data'] ?? [];

        if ($userId <= 0) {
            return ApiResponse::error('用户ID不能为空', 40002);
        }

        if ($totalAmount <= 0) {
            return ApiResponse::error('订单金额必须大于0', 40003);
        }

        $order = $this->orderService->createOrder($userId, $totalAmount, $extraData);

        return ApiResponse::success($order->toArray(), '订单创建成功');
    }

    private function list(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);
        $status = $params['status'] ?? '';
        $userId = (int) ($params['user_id'] ?? 0);

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        $result = $this->orderService->listOrders($page, $pageSize, $status, $userId);

        return ApiResponse::success($result);
    }

    private function detail(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $detail = $this->orderService->getOrderDetail($orderId);

        if ($detail === null) {
            return ApiResponse::error('订单不存在', 40401);
        }

        return ApiResponse::success($detail);
    }

    private function validate(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $event = $params['event'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($event)) {
            return ApiResponse::error('操作事件不能为空', 40005);
        }

        $result = $this->orderService->validateEvent($orderId, $event);

        if ($result['allowed']) {
            return ApiResponse::success([
                'allowed' => true,
                'event' => $event,
                'event_label' => OrderEvent::getLabel($event),
            ], '操作允许执行');
        }

        return ApiResponse::error(
            $result['error_message'],
            40006,
            [
                'error_code' => $result['error_code'],
                'suggestion' => $result['suggestion'] ?? '',
                'details' => $result,
            ]
        );
    }

    private function applyEvent(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $event = $params['event'] ?? '';
        $operatorId = $params['operator_id'] ?? '';
        $remark = $params['remark'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($event)) {
            return ApiResponse::error('操作事件不能为空', 40005);
        }

        $validationResult = $this->orderService->validateEvent($orderId, $event);
        if (!$validationResult['allowed']) {
            return ApiResponse::error(
                $validationResult['error_message'],
                40006,
                [
                    'error_code' => $validationResult['error_code'],
                    'suggestion' => $validationResult['suggestion'] ?? '',
                    'details' => $validationResult,
                ]
            );
        }

        $result = $this->orderService->applyEvent($orderId, $event, $operatorId, $remark);

        return ApiResponse::success([
            'transition' => $result->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], sprintf('操作成功: %s', OrderEvent::getLabel($event)));
    }

    private function pay(array $params): array
    {
        $params['event'] = OrderEvent::PAY;
        return $this->applyEvent($params);
    }

    private function ship(array $params): array
    {
        $params['event'] = OrderEvent::SHIP;
        return $this->applyEvent($params);
    }

    private function confirmReceipt(array $params): array
    {
        $params['event'] = OrderEvent::CONFIRM_RECEIPT;
        return $this->applyEvent($params);
    }

    private function complete(array $params): array
    {
        $params['event'] = OrderEvent::COMPLETE;
        return $this->applyEvent($params);
    }

    private function cancel(array $params): array
    {
        $params['event'] = OrderEvent::CANCEL;
        return $this->applyEvent($params);
    }

    private function applyRefund(array $params): array
    {
        $params['event'] = OrderEvent::APPLY_REFUND;
        return $this->applyEvent($params);
    }

    private function approveRefund(array $params): array
    {
        $params['event'] = OrderEvent::APPROVE_REFUND;
        return $this->applyEvent($params);
    }

    private function rejectRefund(array $params): array
    {
        $params['event'] = OrderEvent::REJECT_REFUND;
        return $this->applyEvent($params);
    }

    private function markException(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $reason = $params['reason'] ?? '';
        $operatorId = $params['operator_id'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($reason)) {
            return ApiResponse::error('异常原因不能为空', 40007);
        }

        $result = $this->orderService->markException($orderId, $reason, $operatorId);

        return ApiResponse::success([
            'transition' => $result->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '异常标记成功');
    }

    private function resolveException(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $targetStatus = $params['target_status'] ?? '';
        $operatorId = $params['operator_id'] ?? '';
        $remark = $params['remark'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($targetStatus)) {
            return ApiResponse::error('目标状态不能为空', 40008);
        }

        $result = $this->orderService->resolveException($orderId, $targetStatus, $operatorId, $remark);

        return ApiResponse::success([
            'transition' => $result->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '异常已解决');
    }

    private function rollback(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $operatorId = $params['operator_id'] ?? '';
        $remark = $params['remark'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $result = $this->orderService->rollback($orderId, $operatorId, $remark);

        return ApiResponse::success([
            'transition' => $result->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '状态回滚成功');
    }

    private function statusLogs(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $logs = $this->orderService->getOrderStatusLogs($orderId);

        return ApiResponse::success([
            'order_id' => $orderId,
            'logs' => $logs,
        ]);
    }

    private function stateMachineConfig(): array
    {
        $config = $this->orderService->getStateMachineConfig();

        return ApiResponse::success($config);
    }

    private function checkConsistency(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $result = $this->orderService->checkStatusConsistency($orderId);

        return ApiResponse::success($result, $result['is_consistent'] ? '状态一致' : '状态不一致');
    }
}
