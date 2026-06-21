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
        $this->initOperatorContext($params);

        try {
            switch ($action) {
                case 'create':
                    return $this->create($params);
                case 'list':
                    return $this->list($params);
                case 'list_exception':
                    return $this->listException($params);
                case 'list_pending_audit':
                    return $this->listPendingAudit($params);
                case 'list_rollback_protected':
                    return $this->listRollbackProtected($params);
                case 'list_writeback_failed':
                    return $this->listWritebackFailed($params);
                case 'detail':
                    return $this->detail($params);
                case 'detail_full':
                    return $this->detailFull($params);
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
                case 'submit_rollback_audit':
                    return $this->submitRollbackAudit($params);
                case 'approve_rollback':
                    return $this->approveRollback($params);
                case 'reject_rollback':
                    return $this->rejectRollback($params);
                case 'set_rollback_protection':
                    return $this->setRollbackProtection($params);
                case 'remove_rollback_protection':
                    return $this->removeRollbackProtection($params);
                case 'get_rollback_protections':
                    return $this->getRollbackProtections($params);
                case 'get_audit_records':
                    return $this->getAuditRecords($params);
                case 'get_audit_list':
                    return $this->getAuditList($params);
                case 'get_writeback_logs':
                    return $this->getWritebackLogs($params);
                case 'retry_writeback':
                    return $this->retryWriteback($params);
                case 'status_logs':
                    return $this->statusLogs($params);
                case 'state_machine_config':
                    return $this->stateMachineConfig();
                case 'check_consistency':
                    return $this->checkConsistency($params);
                case 'exception_statistics':
                    return $this->exceptionStatistics();
                case 'audit_statistics':
                    return $this->auditStatistics();
                case 'writeback_statistics':
                    return $this->writebackStatistics();
                default:
                    return ApiResponse::error('Invalid action', 40001);
            }
        } catch (StateMachineException $e) {
            return ApiResponse::fromStateMachineException($e, $this->buildExceptionContext($params));
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
        $exceptionType = $params['exception_type'] ?? \Order\Enums\ExceptionType::OTHER;
        $exceptionLevel = isset($params['exception_level']) ? (int) $params['exception_level'] : \Order\Enums\ExceptionLevel::MEDIUM;

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($reason)) {
            return ApiResponse::error('异常原因不能为空', 40007);
        }

        $result = $this->orderService->markExceptionWithType($orderId, $reason, $operatorId, $exceptionType, $exceptionLevel);

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

    private function listException(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        $filters = [];
        if (!empty($params['exception_type'])) {
            $filters['exception_type'] = $params['exception_type'];
        }
        if (isset($params['exception_level']) && $params['exception_level'] !== '') {
            $filters['exception_level'] = (int) $params['exception_level'];
        }
        if (!empty($params['keyword'])) {
            $filters['keyword'] = $params['keyword'];
        }

        $result = $this->orderService->listExceptionOrders($page, $pageSize, $filters);

        return ApiResponse::success($result);
    }

    private function listPendingAudit(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        $result = $this->orderService->listPendingAuditOrders($page, $pageSize);

        return ApiResponse::success($result);
    }

    private function listRollbackProtected(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        $result = $this->orderService->listRollbackProtectedOrders($page, $pageSize);

        return ApiResponse::success($result);
    }

    private function listWritebackFailed(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        $result = $this->orderService->listWritebackFailedOrders($page, $pageSize);

        return ApiResponse::success($result);
    }

    private function detailFull(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $detail = $this->orderService->getOrderDetailFull($orderId);

        if ($detail === null) {
            return ApiResponse::error('订单不存在', 40401);
        }

        return ApiResponse::success($detail);
    }

    private function submitRollbackAudit(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $applicantId = $params['applicant_id'] ?? '';
        $reason = $params['reason'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($reason)) {
            return ApiResponse::error('申请原因不能为空', 40009);
        }

        $context = $params['context'] ?? [];
        if (is_string($context)) {
            $context = json_decode($context, true) ?: [];
        }

        $auditRecord = $this->orderService->submitRollbackAudit($orderId, $applicantId, $reason, $context);

        return ApiResponse::success([
            'audit_record' => $auditRecord->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '回滚审核申请已提交');
    }

    private function approveRollback(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $auditorId = $params['auditor_id'] ?? '';
        $auditRemark = $params['audit_remark'] ?? '';
        $remark = $params['remark'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $result = $this->orderService->approveRollback($orderId, $auditorId, $auditRemark, $remark);

        return ApiResponse::success([
            'transition' => $result->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '回滚审核通过，状态已回滚');
    }

    private function rejectRollback(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $auditorId = $params['auditor_id'] ?? '';
        $auditRemark = $params['audit_remark'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($auditRemark)) {
            return ApiResponse::error('拒绝原因不能为空', 40010);
        }

        $auditRecord = $this->orderService->rejectRollback($orderId, $auditorId, $auditRemark);

        return ApiResponse::success([
            'audit_record' => $auditRecord->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '回滚审核已拒绝');
    }

    private function setRollbackProtection(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $protectionType = $params['protection_type'] ?? '';
        $protectedBy = $params['protected_by'] ?? '';
        $protectionReason = $params['protection_reason'] ?? '';
        $thresholdAmount = isset($params['threshold_amount']) ? (float) $params['threshold_amount'] : null;
        $protectUntil = $params['protect_until'] ?? null;

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        if (empty($protectionType)) {
            return ApiResponse::error('保护类型不能为空', 40011);
        }

        if (empty($protectionReason)) {
            return ApiResponse::error('保护原因不能为空', 40012);
        }

        $context = $params['context'] ?? [];
        if (is_string($context)) {
            $context = json_decode($context, true) ?: [];
        }

        $protection = $this->orderService->setRollbackProtection(
            $orderId,
            $protectionType,
            $protectedBy,
            $protectionReason,
            $thresholdAmount,
            $protectUntil,
            $context
        );

        return ApiResponse::success([
            'protection' => $protection->toArray(),
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '回滚保护设置成功');
    }

    private function removeRollbackProtection(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $operatorId = $params['operator_id'] ?? '';

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $count = $this->orderService->removeRollbackProtection($orderId, $operatorId);

        return ApiResponse::success([
            'removed_count' => $count,
            'order' => $this->orderService->getOrderById($orderId)?->toArray(),
        ], '回滚保护已解除');
    }

    private function getRollbackProtections(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $protections = $this->orderService->getRollbackProtections($orderId);

        return ApiResponse::success([
            'order_id' => $orderId,
            'protections' => $protections,
        ]);
    }

    private function getAuditRecords(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $records = $this->orderService->getAuditRecords($orderId);

        return ApiResponse::success([
            'order_id' => $orderId,
            'audit_records' => $records,
        ]);
    }

    private function getAuditList(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);
        $auditType = $params['audit_type'] ?? '';

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }

        $result = $this->orderService->getAuditList($auditType, $page, $pageSize);

        return ApiResponse::success($result);
    }

    private function getWritebackLogs(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);

        if ($orderId <= 0) {
            return ApiResponse::error('订单ID不能为空', 40004);
        }

        $logs = $this->orderService->getWritebackLogs($orderId);

        return ApiResponse::success([
            'order_id' => $orderId,
            'writeback_logs' => $logs,
        ]);
    }

    private function retryWriteback(array $params): array
    {
        $logId = (int) ($params['log_id'] ?? 0);
        $operatorId = $params['operator_id'] ?? null;

        if ($logId <= 0) {
            return ApiResponse::error('回写记录ID不能为空', 40013);
        }

        $result = $this->orderService->retryWriteback($logId, $operatorId);

        return ApiResponse::success([
            'retry_success' => $result,
        ], $result ? '回写重试已发起' : '回写重试失败');
    }

    private function exceptionStatistics(): array
    {
        $result = $this->orderService->getExceptionStatistics();

        return ApiResponse::success($result);
    }

    private function auditStatistics(): array
    {
        $result = $this->orderService->getAuditStatistics();

        return ApiResponse::success($result);
    }

    private function writebackStatistics(): array
    {
        $result = $this->orderService->getWritebackStatistics();

        return ApiResponse::success($result);
    }

    private function buildExceptionContext(array $params): array
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        $context = [
            'failed_event' => $params['event'] ?? null,
        ];

        if ($orderId > 0) {
            try {
                $order = $this->orderService->getOrderById($orderId);
                if ($order !== null) {
                    $context['order_id'] = $orderId;
                    $context['current_status'] = $order->getStatus();
                    $context['can_rollback'] = !empty($order->getRollbackStack());
                    $context['rollback_depth'] = count($order->getRollbackStack());
                }
            } catch (\Exception $e) {
            }
        }

        return $context;
    }

    private function initOperatorContext(array $params): void
    {
        $operatorId = $params['operator_id'] ?? '';
        $role = $params['operator_role'] ?? '';
        $dealerId = isset($params['dealer_id']) ? (int) $params['dealer_id'] : null;

        if ($operatorId !== '' || $role !== '') {
            \PermissionService::setOperatorContext(
                $operatorId ?: null,
                $role ?: null,
                $dealerId
            );
        }
    }
}
