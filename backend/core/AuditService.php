<?php

require_once __DIR__ . '/../src/Enums/AuditType.php';
require_once __DIR__ . '/../src/Enums/AuditStatus.php';
require_once __DIR__ . '/../src/Enums/ExceptionType.php';
require_once __DIR__ . '/../src/Enums/ExceptionLevel.php';
require_once __DIR__ . '/../src/Enums/RollbackProtectionType.php';
require_once __DIR__ . '/../src/Enums/WritebackType.php';
require_once __DIR__ . '/../src/Enums/WritebackStatus.php';
require_once __DIR__ . '/../src/Enums/TargetSystem.php';

use App\Enums\AuditType;
use App\Enums\AuditStatus;
use App\Enums\ExceptionType;
use App\Enums\ExceptionLevel;
use App\Enums\RollbackProtectionType;
use App\Enums\WritebackType;
use App\Enums\WritebackStatus;
use App\Enums\TargetSystem;

class AuditService
{
    private $db;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public function log(string $action, array $context = []): void
    {
        $logData = [
            'action' => $action,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'operator_id' => $context['operator_id'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->insert('audit_logs', $logData);
    }

    public function submitAudit(
        int $orderId,
        string $auditType,
        string $applicantId,
        string $reason,
        array $context = []
    ): array {
        $auditConfig = $this->config['order_audit'] ?? [];

        if (!$auditConfig['enabled'] ?? false) {
            throw new Exception('审核功能未启用');
        }

        if (!AuditType::exists($auditType)) {
            throw new Exception('无效的审核类型');
        }

        if (empty($reason)) {
            throw new Exception('申请原因不能为空');
        }

        $existingPending = $this->getPendingAuditByOrderAndType($orderId, $auditType);
        if ($existingPending) {
            throw new Exception('该订单已有同类型待审核申请');
        }

        $auditData = [
            'order_id' => $orderId,
            'audit_type' => $auditType,
            'audit_status' => AuditStatus::PENDING,
            'applicant_id' => $applicantId,
            'reason' => $reason,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $auditId = $this->db->insert('order_audit_records', $auditData);

        $this->log('submit_audit', [
            'order_id' => $orderId,
            'audit_id' => $auditId,
            'audit_type' => $auditType,
            'applicant_id' => $applicantId,
        ]);

        $auditData['id'] = $auditId;
        $auditData['context'] = $context;
        return $auditData;
    }

    public function approveAudit(
        int $auditId,
        string $auditorId,
        string $auditRemark = ''
    ): array {
        $auditRecord = $this->getAuditById($auditId);
        if (!$auditRecord) {
            throw new Exception('审核记录不存在');
        }

        if ($auditRecord['audit_status'] !== AuditStatus::PENDING) {
            throw new Exception('该审核记录不处于待审核状态');
        }

        $updateData = [
            'audit_status' => AuditStatus::APPROVED,
            'auditor_id' => $auditorId,
            'audit_remark' => $auditRemark,
            'audited_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->update('order_audit_records', $updateData, 'id = ?', [$auditId]);

        $this->log('approve_audit', [
            'audit_id' => $auditId,
            'order_id' => $auditRecord['order_id'],
            'audit_type' => $auditRecord['audit_type'],
            'auditor_id' => $auditorId,
        ]);

        $auditRecord = array_merge($auditRecord, $updateData);
        $auditRecord['context'] = json_decode($auditRecord['context'], true) ?: [];

        return $auditRecord;
    }

    public function rejectAudit(
        int $auditId,
        string $auditorId,
        string $auditRemark = ''
    ): array {
        $auditRecord = $this->getAuditById($auditId);
        if (!$auditRecord) {
            throw new Exception('审核记录不存在');
        }

        if ($auditRecord['audit_status'] !== AuditStatus::PENDING) {
            throw new Exception('该审核记录不处于待审核状态');
        }

        if (empty($auditRemark)) {
            throw new Exception('拒绝原因不能为空');
        }

        $updateData = [
            'audit_status' => AuditStatus::REJECTED,
            'auditor_id' => $auditorId,
            'audit_remark' => $auditRemark,
            'audited_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->update('order_audit_records', $updateData, 'id = ?', [$auditId]);

        $this->log('reject_audit', [
            'audit_id' => $auditId,
            'order_id' => $auditRecord['order_id'],
            'audit_type' => $auditRecord['audit_type'],
            'auditor_id' => $auditorId,
        ]);

        $auditRecord = array_merge($auditRecord, $updateData);
        $auditRecord['context'] = json_decode($auditRecord['context'], true) ?: [];

        return $auditRecord;
    }

    public function cancelAudit(int $auditId, string $operatorId): array
    {
        $auditRecord = $this->getAuditById($auditId);
        if (!$auditRecord) {
            throw new Exception('审核记录不存在');
        }

        if ($auditRecord['audit_status'] !== AuditStatus::PENDING) {
            throw new Exception('该审核记录不处于待审核状态');
        }

        $updateData = [
            'audit_status' => AuditStatus::CANCELLED,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->update('order_audit_records', $updateData, 'id = ?', [$auditId]);

        $this->log('cancel_audit', [
            'audit_id' => $auditId,
            'order_id' => $auditRecord['order_id'],
            'operator_id' => $operatorId,
        ]);

        $auditRecord = array_merge($auditRecord, $updateData);
        $auditRecord['context'] = json_decode($auditRecord['context'], true) ?: [];

        return $auditRecord;
    }

    public function getAuditById(int $auditId): ?array
    {
        $sql = "SELECT * FROM order_audit_records WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$auditId]);

        if ($result) {
            $result['context'] = json_decode($result['context'], true) ?: [];
        }

        return $result ?: null;
    }

    public function getPendingAuditByOrderAndType(int $orderId, string $auditType): ?array
    {
        $sql = "SELECT * FROM order_audit_records 
                WHERE order_id = ? AND audit_type = ? AND audit_status = ?
                ORDER BY id DESC LIMIT 1";
        $result = $this->db->fetchOne($sql, [$orderId, $auditType, AuditStatus::PENDING]);

        if ($result) {
            $result['context'] = json_decode($result['context'], true) ?: [];
        }

        return $result ?: null;
    }

    public function getAuditList(
        string $auditType = '',
        string $auditStatus = '',
        int $page = 1,
        int $pageSize = 20
    ): array {
        $where = [];
        $params = [];

        if (!empty($auditType)) {
            $where[] = 'audit_type = ?';
            $params[] = $auditType;
        }

        if (!empty($auditStatus)) {
            $where[] = 'audit_status = ?';
            $params[] = $auditStatus;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) as total FROM order_audit_records $whereClause";
        $total = (int) $this->db->fetchOne($countSql, $params)['total'];

        $offset = ($page - 1) * $pageSize;
        $listSql = "SELECT * FROM order_audit_records $whereClause
                    ORDER BY id DESC LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $offset;

        $records = $this->db->fetchAll($listSql, $params);

        foreach ($records as &$record) {
            $record['context'] = json_decode($record['context'], true) ?: [];
        }

        return [
            'list' => $records,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => (int) ceil($total / $pageSize),
        ];
    }

    public function getAuditRecordsByOrder(int $orderId): array
    {
        $sql = "SELECT * FROM order_audit_records WHERE order_id = ? ORDER BY id DESC";
        $records = $this->db->fetchAll($sql, [$orderId]);

        foreach ($records as &$record) {
            $record['context'] = json_decode($record['context'], true) ?: [];
        }

        return $records;
    }

    public function countPendingAuditByType(string $auditType = ''): int
    {
        $sql = "SELECT COUNT(*) as count FROM order_audit_records WHERE audit_status = ?";
        $params = [AuditStatus::PENDING];

        if (!empty($auditType)) {
            $sql .= " AND audit_type = ?";
            $params[] = $auditType;
        }

        $result = $this->db->fetchOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    public function createWritebackLog(
        int $orderId,
        string $targetSystem,
        string $writebackType,
        array $writebackData,
        string $operatorId = ''
    ): int {
        $writebackConfig = $this->config['order_writeback'] ?? [];
        $maxRetryCount = $writebackConfig['max_retry_count'] ?? 3;

        $logData = [
            'order_id' => $orderId,
            'target_system' => $targetSystem,
            'writeback_type' => $writebackType,
            'writeback_data' => json_encode($writebackData, JSON_UNESCAPED_UNICODE),
            'writeback_status' => WritebackStatus::PENDING,
            'retry_count' => 0,
            'max_retry_count' => $maxRetryCount,
            'operator_id' => $operatorId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        return $this->db->insert('order_writeback_logs', $logData);
    }

    public function markWritebackSuccess(int $logId, string $message = ''): bool
    {
        $updateData = [
            'writeback_status' => WritebackStatus::SUCCESS,
            'error_message' => $message,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $affected = $this->db->update('order_writeback_logs', $updateData, 'id = ?', [$logId]);

        if ($affected > 0) {
            $log = $this->getWritebackLogById($logId);
            if ($log) {
                $this->updateOrderWritebackStatus($log['order_id']);
            }
        }

        return $affected > 0;
    }

    public function markWritebackFailed(int $logId, string $errorMessage): bool
    {
        $log = $this->getWritebackLogById($logId);
        if (!$log) {
            return false;
        }

        $retryCount = (int) $log['retry_count'] + 1;
        $maxRetryCount = (int) $log['max_retry_count'];

        $status = WritebackStatus::FAILED;
        $completedAt = null;

        if ($retryCount < $maxRetryCount) {
            $status = WritebackStatus::RETRYING;
        } else {
            $completedAt = date('Y-m-d H:i:s');
        }

        $updateData = [
            'writeback_status' => $status,
            'retry_count' => $retryCount,
            'error_message' => $errorMessage,
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($completedAt) {
            $updateData['completed_at'] = $completedAt;
        }

        $affected = $this->db->update('order_writeback_logs', $updateData, 'id = ?', [$logId]);

        if ($affected > 0) {
            $this->updateOrderWritebackStatus($log['order_id']);
        }

        return $affected > 0;
    }

    public function retryWriteback(int $logId, string $operatorId = null): bool
    {
        $log = $this->getWritebackLogById($logId);
        if (!$log) {
            throw new Exception('回写记录不存在');
        }

        $writebackStatus = $log['writeback_status'];
        if (!in_array($writebackStatus, [WritebackStatus::FAILED, WritebackStatus::PENDING, WritebackStatus::RETRYING])) {
            throw new Exception('该回写记录状态不支持重试');
        }

        $updateData = [
            'writeback_status' => WritebackStatus::RETRYING,
            'retry_count' => (int) $log['retry_count'] + 1,
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($operatorId) {
            $updateData['operator_id'] = $operatorId;
        }

        $this->db->update('order_writeback_logs', $updateData, 'id = ?', [$logId]);

        $this->log('retry_writeback', [
            'log_id' => $logId,
            'order_id' => $log['order_id'],
            'operator_id' => $operatorId,
        ]);

        return true;
    }

    public function getWritebackLogById(int $logId): ?array
    {
        $sql = "SELECT * FROM order_writeback_logs WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$logId]);

        if ($result) {
            $result['writeback_data'] = json_decode($result['writeback_data'], true) ?: [];
        }

        return $result ?: null;
    }

    public function getWritebackLogsByOrder(int $orderId): array
    {
        $sql = "SELECT * FROM order_writeback_logs WHERE order_id = ? ORDER BY id DESC";
        $logs = $this->db->fetchAll($sql, [$orderId]);

        foreach ($logs as &$log) {
            $log['writeback_data'] = json_decode($log['writeback_data'], true) ?: [];
        }

        return $logs;
    }

    public function getPendingOrFailedWritebacks(int $limit = 100): array
    {
        $sql = "SELECT * FROM order_writeback_logs 
                WHERE writeback_status IN (?, ?, ?)
                ORDER BY id ASC LIMIT ?";
        $logs = $this->db->fetchAll($sql, [
            WritebackStatus::PENDING,
            WritebackStatus::RETRYING,
            WritebackStatus::FAILED,
            $limit,
        ]);

        foreach ($logs as &$log) {
            $log['writeback_data'] = json_decode($log['writeback_data'], true) ?: [];
        }

        return $logs;
    }

    public function countPendingOrFailedWritebacks(): int
    {
        $sql = "SELECT COUNT(*) as count FROM order_writeback_logs 
                WHERE writeback_status IN (?, ?, ?)";
        $result = $this->db->fetchOne($sql, [
            WritebackStatus::PENDING,
            WritebackStatus::RETRYING,
            WritebackStatus::FAILED,
        ]);

        return (int) ($result['count'] ?? 0);
    }

    public function updateOrderWritebackStatus(int $orderId): void
    {
        $sql = "SELECT writeback_status FROM order_writeback_logs WHERE order_id = ?";
        $logs = $this->db->fetchAll($sql, [$orderId]);

        if (empty($logs)) {
            return;
        }

        $statuses = array_column($logs, 'writeback_status');
        $overallStatus = $this->calculateOverallWritebackStatus($statuses);

        $this->db->update('orders', [
            'writeback_status' => $overallStatus,
            'last_writeback_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$orderId]);
    }

    private function calculateOverallWritebackStatus(array $statuses): string
    {
        if (empty($statuses)) {
            return WritebackStatus::PENDING;
        }

        $hasFailed = in_array(WritebackStatus::FAILED, $statuses);
        $hasRetrying = in_array(WritebackStatus::RETRYING, $statuses);
        $hasPending = in_array(WritebackStatus::PENDING, $statuses);
        $allSuccess = count(array_filter($statuses, function ($s) {
            return $s === WritebackStatus::SUCCESS;
        })) === count($statuses);

        if ($hasFailed && !$hasRetrying && !$hasPending) {
            return WritebackStatus::FAILED;
        }

        if ($hasPending || $hasRetrying) {
            return WritebackStatus::RETRYING;
        }

        if ($hasFailed && $allSuccess === false) {
            return WritebackStatus::PARTIAL;
        }

        if ($allSuccess) {
            return WritebackStatus::SUCCESS;
        }

        return WritebackStatus::PARTIAL;
    }

    public function setRollbackProtection(
        int $orderId,
        string $protectionType,
        string $protectedBy,
        string $protectionReason,
        $thresholdAmount = null,
        $protectUntil = null,
        array $context = []
    ): array {
        if (!RollbackProtectionType::exists($protectionType)) {
            throw new Exception('无效的回滚保护类型');
        }

        if (empty($protectionReason)) {
            throw new Exception('保护原因不能为空');
        }

        $protectionData = [
            'order_id' => $orderId,
            'protection_type' => $protectionType,
            'protected_by' => $protectedBy,
            'protection_reason' => $protectionReason,
            'threshold_amount' => $thresholdAmount,
            'protect_until' => $protectUntil,
            'is_active' => 1,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $protectionId = $this->db->insert('order_rollback_protections', $protectionData);

        $this->db->update('orders', [
            'rollback_protected' => 1,
        ], 'id = ?', [$orderId]);

        $this->log('set_rollback_protection', [
            'order_id' => $orderId,
            'protection_id' => $protectionId,
            'protection_type' => $protectionType,
            'protected_by' => $protectedBy,
        ]);

        $protectionData['id'] = $protectionId;
        $protectionData['context'] = $context;
        return $protectionData;
    }

    public function deactivateProtection(int $protectionId, string $operatorId = ''): bool
    {
        $protection = $this->getProtectionById($protectionId);
        if (!$protection) {
            return false;
        }

        $this->db->update('order_rollback_protections', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$protectionId]);

        $hasActive = $this->hasActiveProtection($protection['order_id']);
        if (!$hasActive) {
            $this->db->update('orders', [
                'rollback_protected' => 0,
            ], 'id = ?', [$protection['order_id']]);
        }

        $this->log('deactivate_rollback_protection', [
            'protection_id' => $protectionId,
            'order_id' => $protection['order_id'],
            'operator_id' => $operatorId,
        ]);

        return true;
    }

    public function deactivateAllForOrder(int $orderId, string $operatorId = ''): int
    {
        $protections = $this->getActiveProtectionsByOrder($orderId);
        $count = 0;

        foreach ($protections as $protection) {
            if ($this->deactivateProtection($protection['id'], $operatorId)) {
                $count++;
            }
        }

        return $count;
    }

    public function getProtectionById(int $protectionId): ?array
    {
        $sql = "SELECT * FROM order_rollback_protections WHERE id = ?";
        $result = $this->db->fetchOne($sql, [$protectionId]);

        if ($result) {
            $result['context'] = json_decode($result['context'], true) ?: [];
            $result['is_active'] = (bool) $result['is_active'];
        }

        return $result ?: null;
    }

    public function getProtectionsByOrder(int $orderId): array
    {
        $sql = "SELECT * FROM order_rollback_protections WHERE order_id = ? ORDER BY id DESC";
        $protections = $this->db->fetchAll($sql, [$orderId]);

        foreach ($protections as &$protection) {
            $protection['context'] = json_decode($protection['context'], true) ?: [];
            $protection['is_active'] = (bool) $protection['is_active'];
        }

        return $protections;
    }

    public function getActiveProtectionsByOrder(int $orderId): array
    {
        $sql = "SELECT * FROM order_rollback_protections 
                WHERE order_id = ? AND is_active = 1
                ORDER BY id DESC";
        $protections = $this->db->fetchAll($sql, [$orderId]);

        foreach ($protections as &$protection) {
            $protection['context'] = json_decode($protection['context'], true) ?: [];
            $protection['is_active'] = (bool) $protection['is_active'];
        }

        return $protections;
    }

    public function hasActiveProtection(int $orderId): bool
    {
        $sql = "SELECT COUNT(*) as count FROM order_rollback_protections 
                WHERE order_id = ? AND is_active = 1";
        $result = $this->db->fetchOne($sql, [$orderId]);

        return (int) ($result['count'] ?? 0) > 0;
    }

    public function isProtectionValid(array $protection, float $orderAmount = 0): bool
    {
        if (!$protection['is_active']) {
            return false;
        }

        if (!empty($protection['protect_until'])) {
            $protectUntil = strtotime($protection['protect_until']);
            if ($protectUntil < time()) {
                return false;
            }
        }

        if ($protection['protection_type'] === RollbackProtectionType::AMOUNT_THRESHOLD) {
            $thresholdAmount = (float) $protection['threshold_amount'];
            if ($thresholdAmount > 0 && $orderAmount > $thresholdAmount) {
                return false;
            }
        }

        return true;
    }

    public function getStatistics(): array
    {
        return [
            'pending_audit_count' => $this->countPendingAuditByType(),
            'pending_rollback_audit_count' => $this->countPendingAuditByType(AuditType::ROLLBACK),
            'pending_exception_audit_count' => $this->countPendingAuditByType(AuditType::EXCEPTION_MARK),
            'pending_writeback_count' => $this->countPendingOrFailedWritebacks(),
            'rollback_protected_order_count' => $this->countRollbackProtectedOrders(),
        ];
    }

    private function countRollbackProtectedOrders(): int
    {
        $sql = "SELECT COUNT(*) as count FROM orders WHERE rollback_protected = 1";
        $result = $this->db->fetchOne($sql);

        return (int) ($result['count'] ?? 0);
    }

    public function isAuditRequired(string $auditType, float $amount = 0): bool
    {
        $auditConfig = $this->config['order_audit'] ?? [];

        if (!($auditConfig['enabled'] ?? false)) {
            return false;
        }

        $typeThreshold = $auditConfig[$auditType . '_threshold'] ?? null;
        if ($typeThreshold !== null && (float) $typeThreshold > 0 && $amount >= (float) $typeThreshold) {
            return true;
        }

        $typeConfig = $auditConfig[$auditType . '_required'] ?? false;
        return (bool) $typeConfig;
    }

    public function triggerWriteback(
        int $orderId,
        string $writebackType,
        string $operatorId = ''
    ): array {
        $writebackConfig = $this->config['order_writeback'] ?? [];

        if (!($writebackConfig['enabled'] ?? false)) {
            return [];
        }

        $targetSystems = WritebackType::TARGET_SYSTEMS[$writebackType] ?? [];
        $logIds = [];

        $orderData = $this->getOrderDataForWriteback($orderId);

        foreach ($targetSystems as $targetSystem) {
            $writebackData = array_merge($orderData, [
                'writeback_type' => $writebackType,
                'target_system' => $targetSystem,
                'timestamp' => time(),
            ]);

            $logId = $this->createWritebackLog(
                $orderId,
                $targetSystem,
                $writebackType,
                $writebackData,
                $operatorId
            );

            $logIds[] = $logId;
        }

        if (!empty($logIds)) {
            $this->log('trigger_writeback', [
                'order_id' => $orderId,
                'writeback_type' => $writebackType,
                'target_systems' => $targetSystems,
                'log_ids' => $logIds,
            ]);
        }

        return $logIds;
    }

    private function getOrderDataForWriteback(int $orderId): array
    {
        $sql = "SELECT id, order_no, status, amount, user_id, created_at 
                FROM orders WHERE id = ?";
        $order = $this->db->fetchOne($sql, [$orderId]);

        return $order ?: [];
    }

    public function getExceptionStatistics(): array
    {
        $sql = "SELECT exception_type, COUNT(*) as count 
                FROM orders 
                WHERE status = ? OR exception_level > 0
                GROUP BY exception_type";
        $rows = $this->db->fetchAll($sql, ['exception']);

        $byType = [];
        foreach (ExceptionType::all() as $type) {
            $byType[$type] = 0;
        }
        foreach ($rows as $row) {
            if ($row['exception_type']) {
                $byType[$row['exception_type']] = (int) $row['count'];
            }
        }

        $sql = "SELECT exception_level, COUNT(*) as count 
                FROM orders 
                WHERE status = ? OR exception_level > 0
                GROUP BY exception_level";
        $rows = $this->db->fetchAll($sql, ['exception']);

        $byLevel = [];
        foreach (ExceptionLevel::all() as $level) {
            $byLevel[$level] = 0;
        }
        foreach ($rows as $row) {
            $byLevel[$row['exception_level']] = (int) $row['count'];
        }

        $sql = "SELECT COUNT(*) as total FROM orders 
                WHERE status = ? OR exception_level > 0";
        $total = (int) $this->db->fetchOne($sql, ['exception'])['total'];

        return [
            'total' => $total,
            'by_type' => $byType,
            'by_level' => $byLevel,
        ];
    }

    public function getWritebackStatistics(): array
    {
        $sql = "SELECT writeback_status, COUNT(*) as count 
                FROM order_writeback_logs 
                GROUP BY writeback_status";
        $rows = $this->db->fetchAll($sql);

        $byStatus = [];
        foreach (WritebackStatus::all() as $status) {
            $byStatus[$status] = 0;
        }
        foreach ($rows as $row) {
            $byStatus[$row['writeback_status']] = (int) $row['count'];
        }

        $sql = "SELECT target_system, COUNT(*) as count 
                FROM order_writeback_logs 
                GROUP BY target_system";
        $rows = $this->db->fetchAll($sql);

        $bySystem = [];
        foreach (TargetSystem::all() as $system) {
            $bySystem[$system] = 0;
        }
        foreach ($rows as $row) {
            $bySystem[$row['target_system']] = (int) $row['count'];
        }

        $sql = "SELECT COUNT(DISTINCT order_id) as affected_orders 
                FROM order_writeback_logs 
                WHERE writeback_status IN (?, ?, ?)";
        $affectedOrders = (int) $this->db->fetchOne($sql, [
            WritebackStatus::PENDING,
            WritebackStatus::RETRYING,
            WritebackStatus::FAILED,
        ])['affected_orders'];

        return [
            'by_status' => $byStatus,
            'by_system' => $bySystem,
            'affected_orders' => $affectedOrders,
        ];
    }

    public function getAuditStatistics(): array
    {
        $sql = "SELECT audit_status, COUNT(*) as count 
                FROM order_audit_records 
                GROUP BY audit_status";
        $rows = $this->db->fetchAll($sql);

        $byStatus = [];
        foreach (AuditStatus::all() as $status) {
            $byStatus[$status] = 0;
        }
        foreach ($rows as $row) {
            $byStatus[$row['audit_status']] = (int) $row['count'];
        }

        $sql = "SELECT audit_type, COUNT(*) as count 
                FROM order_audit_records 
                GROUP BY audit_type";
        $rows = $this->db->fetchAll($sql);

        $byType = [];
        foreach (AuditType::all() as $type) {
            $byType[$type] = 0;
        }
        foreach ($rows as $row) {
            $byType[$row['audit_type']] = (int) $row['count'];
        }

        $sql = "SELECT COUNT(DISTINCT order_id) as pending_orders 
                FROM order_audit_records 
                WHERE audit_status = ?";
        $pendingOrders = (int) $this->db->fetchOne($sql, [AuditStatus::PENDING])['pending_orders'];

        return [
            'by_status' => $byStatus,
            'by_type' => $byType,
            'pending_orders' => $pendingOrders,
        ];
    }
}
