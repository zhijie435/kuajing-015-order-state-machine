<?php

namespace Order\Models;

use Order\Core\Database;
use Order\Core\StateMachine;
use Order\Core\TransitionResult;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Enums\AuditStatus;
use Order\Enums\AuditType;
use Order\Enums\ExceptionType;
use Order\Enums\ExceptionLevel;
use Order\Enums\RollbackProtectionType;
use Order\Enums\WritebackStatus;
use Order\Enums\WritebackType;
use Order\Exceptions\StateMachineException;

class Order
{
    private ?int $id;
    private string $orderNo;
    private int $userId;
    private float $totalAmount;
    private StateMachine $stateMachine;
    private Database $db;
    private array $config;
    private ?string $createdAt;
    private ?string $updatedAt;
    private array $extraData = [];
    private bool $isDirty = false;

    private string $auditStatus;
    private ?string $exceptionType;
    private int $exceptionLevel;
    private bool $rollbackProtected;
    private int $rollbackCount;
    private string $writebackStatus;
    private ?string $lastWritebackAt;

    public function __construct(
        string $orderNo,
        int $userId,
        float $totalAmount,
        string $initialStatus = OrderStatus::PENDING,
        array $config = [],
        ?int $id = null
    ) {
        $this->orderNo = $orderNo;
        $this->userId = $userId;
        $this->totalAmount = $totalAmount;
        $this->id = $id;
        $this->config = $config;

        $stateMachineConfig = $config['state_machine'] ?? [];
        $this->stateMachine = new StateMachine($initialStatus, $stateMachineConfig);

        $this->db = Database::getInstance($config['db'] ?? []);

        $this->auditStatus = AuditStatus::NONE;
        $this->exceptionType = null;
        $this->exceptionLevel = ExceptionLevel::NONE;
        $this->rollbackProtected = false;
        $this->rollbackCount = 0;
        $this->writebackStatus = WritebackStatus::PENDING;
        $this->lastWritebackAt = null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderNo(): string
    {
        return $this->orderNo;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getStatus(): string
    {
        return $this->stateMachine->getCurrentStatus();
    }

    public function getPreviousStatus(): string
    {
        return $this->stateMachine->getPreviousStatus();
    }

    public function getStateMachine(): StateMachine
    {
        return $this->stateMachine;
    }

    public function getAuditStatus(): string
    {
        return $this->auditStatus;
    }

    public function getAuditStatusLabel(): string
    {
        return AuditStatus::getLabel($this->auditStatus);
    }

    public function getAuditStatusColor(): string
    {
        return AuditStatus::getColor($this->auditStatus);
    }

    public function setAuditStatus(string $auditStatus): void
    {
        $this->auditStatus = $auditStatus;
        $this->isDirty = true;
    }

    public function getExceptionType(): ?string
    {
        return $this->exceptionType;
    }

    public function getExceptionTypeLabel(): string
    {
        return $this->exceptionType ? ExceptionType::getLabel($this->exceptionType) : '';
    }

    public function setExceptionType(?string $exceptionType): void
    {
        $this->exceptionType = $exceptionType;
        $this->isDirty = true;
    }

    public function getExceptionLevel(): int
    {
        return $this->exceptionLevel;
    }

    public function getExceptionLevelLabel(): string
    {
        return ExceptionLevel::getLabel($this->exceptionLevel);
    }

    public function getExceptionLevelColor(): string
    {
        return ExceptionLevel::getColor($this->exceptionLevel);
    }

    public function setExceptionLevel(int $exceptionLevel): void
    {
        $this->exceptionLevel = $exceptionLevel;
        $this->isDirty = true;
    }

    public function isRollbackProtected(): bool
    {
        return $this->rollbackProtected;
    }

    public function setRollbackProtected(bool $rollbackProtected): void
    {
        $this->rollbackProtected = $rollbackProtected;
        $this->isDirty = true;
    }

    public function getRollbackCount(): int
    {
        return $this->rollbackCount;
    }

    public function incrementRollbackCount(): void
    {
        $this->rollbackCount++;
        $this->isDirty = true;
    }

    public function getWritebackStatus(): string
    {
        return $this->writebackStatus;
    }

    public function getWritebackStatusLabel(): string
    {
        return WritebackStatus::getLabel($this->writebackStatus);
    }

    public function getWritebackStatusColor(): string
    {
        return WritebackStatus::getColor($this->writebackStatus);
    }

    public function setWritebackStatus(string $writebackStatus): void
    {
        $this->writebackStatus = $writebackStatus;
        $this->isDirty = true;
    }

    public function getLastWritebackAt(): ?string
    {
        return $this->lastWritebackAt;
    }

    public function updateLastWritebackAt(): void
    {
        $this->lastWritebackAt = date('Y-m-d H:i:s');
        $this->isDirty = true;
    }

    public function hasException(): bool
    {
        return $this->getStatus() === OrderStatus::EXCEPTION || $this->exceptionLevel > ExceptionLevel::NONE;
    }

    public function requiresRollbackAudit(): bool
    {
        if ($this->rollbackProtected) {
            return true;
        }
        $rollbackConfig = $this->config['rollback_protection'] ?? [];
        $amountThreshold = $rollbackConfig['amount_threshold'] ?? 10000.00;
        if ($this->totalAmount >= $amountThreshold) {
            return true;
        }
        if (OrderStatus::isTerminal($this->getStatus())) {
            return true;
        }
        return false;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function setExtraData(string $key, $value): void
    {
        $this->extraData[$key] = $value;
        $this->isDirty = true;
    }

    public function getExtraData(string $key = null)
    {
        if ($key === null) {
            return $this->extraData;
        }
        return $this->extraData[$key] ?? null;
    }

    public function setTotalAmount(float $amount): void
    {
        $this->totalAmount = $amount;
        $this->isDirty = true;
    }

    public function can(string $event): bool
    {
        return $this->stateMachine->can($event, $this);
    }

    public function checkCan(string $event): array
    {
        return $this->stateMachine->checkCan($event, $this);
    }

    public function getValidationErrors(string $event): array
    {
        return $this->stateMachine->getValidationErrors($event, $this);
    }

    public function validateAndApply(string $event, string $operatorId = '', string $remark = ''): TransitionResult
    {
        $validationResult = $this->checkCan($event);
        if (!$validationResult['allowed']) {
            throw StateMachineException::validationFailed(
                $validationResult['error_message'] . ' ' . ($validationResult['suggestion'] ?? '')
            );
        }

        return $this->apply($event, $operatorId, $remark);
    }

    public function apply(string $event, string $operatorId = '', string $remark = ''): TransitionResult
    {
        $validationResult = $this->checkCan($event);
        if (!$validationResult['allowed']) {
            throw StateMachineException::invalidTransition($this->getStatus(), $event);
        }

        return $this->db->transactional(function () use ($event, $operatorId, $remark) {
            $result = $this->stateMachine->apply($event, $this, $operatorId, $remark);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->logTransition($result);
            $this->save();

            return $result;
        });
    }

    public function markException(
        string $reason,
        string $operatorId = '',
        string $exceptionType = ExceptionType::OTHER,
        int $exceptionLevel = ExceptionLevel::MEDIUM
    ): TransitionResult {
        return $this->db->transactional(function () use ($reason, $operatorId, $exceptionType, $exceptionLevel) {
            $result = $this->stateMachine->apply(OrderEvent::MARK_EXCEPTION, $this, $operatorId, $reason);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $reason);
            $this->setExceptionType($exceptionType);
            $this->setExceptionLevel($exceptionLevel);
            $this->setAuditStatus(ExceptionLevel::requiresAudit($exceptionLevel) ? AuditStatus::PENDING : AuditStatus::NONE);
            $this->logTransition($result);
            $this->save();

            $this->triggerWriteback(WritebackType::STATUS_UPDATE, $operatorId);

            return $result;
        });
    }

    public function resolveException(string $targetStatus, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($targetStatus, $operatorId, $remark) {
            $result = $this->stateMachine->resolveException($targetStatus, $this, $operatorId, $remark);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->setExceptionType(null);
            $this->setExceptionLevel(ExceptionLevel::NONE);
            $this->setAuditStatus(AuditStatus::APPROVED);
            $this->logTransition($result);
            $this->save();

            $this->triggerWriteback(WritebackType::STATUS_UPDATE, $operatorId);

            return $result;
        });
    }

    public function rollback(string $operatorId = '', string $remark = ''): TransitionResult
    {
        if ($this->requiresRollbackAudit()) {
            throw StateMachineException::validationFailed(
                '该订单受回滚保护，需要审核通过后才能执行回滚操作'
            );
        }

        return $this->db->transactional(function () use ($operatorId, $remark) {
            $result = $this->stateMachine->rollback($this, $operatorId, $remark);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->incrementRollbackCount();
            $this->logTransition($result);
            $this->save();

            $this->triggerWriteback(WritebackType::STATUS_UPDATE, $operatorId);

            return $result;
        });
    }

    public function getAvailableEvents(): array
    {
        return $this->stateMachine->getAvailableEvents();
    }

    public function getTransitionHistory(int $limit = 50): array
    {
        if ($this->id === null) {
            return [];
        }

        $sql = 'SELECT * FROM order_status_logs WHERE order_id = ? ORDER BY id DESC LIMIT ' . (int) $limit;
        return $this->db->fetchAll($sql, [$this->id]);
    }

    public function getRollbackStack(): array
    {
        return $this->stateMachine->getRollbackStack();
    }

    public function triggerWriteback(string $writebackType, string $operatorId = null): array
    {
        if ($this->id === null) {
            return [];
        }

        $writebackData = [
            'order_id' => $this->id,
            'order_no' => $this->orderNo,
            'status' => $this->getStatus(),
            'total_amount' => $this->totalAmount,
            'user_id' => $this->userId,
        ];

        $logs = OrderWritebackLog::batchCreateForOrder(
            $this->id,
            $writebackType,
            $writebackData,
            $this->config,
            $operatorId
        );

        $this->updateWritebackStatusFromLogs();

        return $logs;
    }

    public function updateWritebackStatusFromLogs(): void
    {
        if ($this->id === null) {
            return;
        }

        $logs = OrderWritebackLog::findByOrderId($this->id, $this->config);
        if (empty($logs)) {
            return;
        }

        $hasFailed = false;
        $hasPending = false;
        $hasSuccess = false;

        foreach ($logs as $log) {
            $status = $log->getWritebackStatus();
            if ($status === WritebackStatus::FAILED) {
                $hasFailed = true;
            } elseif ($status === WritebackStatus::PENDING || $status === WritebackStatus::RETRYING) {
                $hasPending = true;
            } elseif ($status === WritebackStatus::SUCCESS) {
                $hasSuccess = true;
            }
        }

        if ($hasFailed && $hasSuccess) {
            $this->setWritebackStatus(WritebackStatus::PARTIAL);
        } elseif ($hasFailed) {
            $this->setWritebackStatus(WritebackStatus::FAILED);
        } elseif ($hasPending) {
            $this->setWritebackStatus(WritebackStatus::PENDING);
        } else {
            $this->setWritebackStatus(WritebackStatus::SUCCESS);
        }

        $this->updateLastWritebackAt();
        $this->save();
    }

    public function setRollbackProtection(
        string $protectionType,
        string $protectedBy,
        string $protectionReason,
        ?float $thresholdAmount = null,
        ?string $protectUntil = null,
        array $context = []
    ): OrderRollbackProtection {
        $protection = OrderRollbackProtection::createProtection(
            $this->id,
            $protectionType,
            $protectedBy,
            $protectionReason,
            $this->config,
            $thresholdAmount,
            $protectUntil,
            $context
        );

        $this->setRollbackProtected(true);
        $this->save();

        return $protection;
    }

    public function removeRollbackProtection(string $operatorId = ''): int
    {
        $count = OrderRollbackProtection::deactivateAllForOrder($this->id, $this->config);
        if ($count > 0) {
            $this->setRollbackProtected(false);
            $this->save();
        }
        return $count;
    }

    public function getRollbackProtections(): array
    {
        return OrderRollbackProtection::findActiveProtections($this->id, $this->config);
    }

    public function getWritebackLogs(): array
    {
        return OrderWritebackLog::findByOrderId($this->id, $this->config);
    }

    public function submitRollbackAudit(
        string $applicantId,
        string $reason,
        array $context = []
    ): OrderAuditRecord {
        $auditRecord = new OrderAuditRecord(
            $this->id,
            AuditType::ROLLBACK,
            'submit',
            $this->config
        );

        $auditRecord->submit(
            $applicantId,
            $reason,
            $this->getStatus(),
            null,
            $context
        );

        $auditRecord->save();
        $this->setAuditStatus(AuditStatus::PENDING);
        $this->save();

        return $auditRecord;
    }

    public function approveRollback(
        string $auditorId,
        string $auditRemark = '',
        string $remark = ''
    ): TransitionResult {
        if (!$this->requiresRollbackAudit()) {
            throw StateMachineException::validationFailed('该订单无需审核即可回滚');
        }

        $currentStatus = $this->getStatus();

        return $this->db->transactional(function () use ($auditorId, $auditRemark, $remark, $currentStatus) {
            $pendingAudit = $this->findPendingAuditRecord(AuditType::ROLLBACK);
            if ($pendingAudit === null) {
                throw StateMachineException::validationFailed('没有待审核的回滚申请');
            }

            $pendingAudit->approve($auditorId, $auditRemark);
            $pendingAudit->setAfterStatus($this->stateMachine->getRollbackStack()[0]['from_status'] ?? null);
            $pendingAudit->save();

            $result = $this->stateMachine->rollback($this, $auditorId, $remark);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $auditorId, $remark);
            $this->incrementRollbackCount();
            $this->setAuditStatus(AuditStatus::APPROVED);
            $this->logTransition($result);
            $this->save();

            $this->triggerWriteback(WritebackType::STATUS_UPDATE, $auditorId);

            return $result;
        });
    }

    public function rejectRollback(
        string $auditorId,
        string $auditRemark = ''
    ): OrderAuditRecord {
        $pendingAudit = $this->findPendingAuditRecord(AuditType::ROLLBACK);
        if ($pendingAudit === null) {
            throw StateMachineException::validationFailed('没有待审核的回滚申请');
        }

        $pendingAudit->reject($auditorId, $auditRemark);
        $pendingAudit->save();

        $this->setAuditStatus(AuditStatus::REJECTED);
        $this->save();

        return $pendingAudit;
    }

    public function findPendingAuditRecord(string $auditType): ?OrderAuditRecord
    {
        $records = OrderAuditRecord::findByOrderId($this->id, $this->config);
        foreach ($records as $record) {
            if ($record->getAuditType() === $auditType && $record->getAuditStatus() === AuditStatus::PENDING) {
                return $record;
            }
        }
        return null;
    }

    public function getAuditRecords(): array
    {
        return OrderAuditRecord::findByOrderId($this->id, $this->config);
    }

    private function persistStateSnapshot(): void
    {
        $snapshot = $this->stateMachine->getSnapshot();
        $this->extraData['_state_snapshot'] = $snapshot;
        $this->isDirty = true;
    }

    private function restoreStateSnapshot(): void
    {
        if (isset($this->extraData['_state_snapshot']) && is_array($this->extraData['_state_snapshot'])) {
            $this->stateMachine->restoreFromSnapshot($this->extraData['_state_snapshot']);
            $this->isDirty = false;
        }
    }

    public function refresh(): void
    {
        if ($this->id === null) {
            return;
        }

        $sql = 'SELECT * FROM orders WHERE id = ?';
        $row = $this->db->fetchOne($sql, [$this->id]);

        if ($row === null) {
            return;
        }

        $this->orderNo = $row['order_no'];
        $this->userId = (int) $row['user_id'];
        $this->totalAmount = (float) $row['total_amount'];
        $this->createdAt = $row['created_at'];
        $this->updatedAt = $row['updated_at'];

        $extraData = [];
        if (!empty($row['extra_data'])) {
            $decoded = json_decode($row['extra_data'], true);
            if (is_array($decoded)) {
                $extraData = $decoded;
            }
        }
        $this->extraData = $extraData;

        $this->stateMachine->syncStatus($row['status']);

        if (isset($extraData['_state_snapshot']) && is_array($extraData['_state_snapshot'])) {
            $this->stateMachine->restoreFromSnapshot($extraData['_state_snapshot']);
        }

        $this->isDirty = false;
    }

    private function updateStatus(string $status, string $operatorId, string $remark): void
    {
        if ($this->id === null) {
            return;
        }

        $this->stateMachine->syncStatus($status);
        $this->isDirty = true;
    }

    private function logTransition(TransitionResult $result): void
    {
        if ($this->id === null) {
            return;
        }

        $sql = 'INSERT INTO order_status_logs (
            order_id, from_status, to_status, event, message, 
            operator_id, remark, context, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        $this->db->execute($sql, [
            $this->id,
            $result->getFromStatus(),
            $result->getToStatus(),
            $result->getEvent(),
            $result->getMessage(),
            $result->getOperatorId(),
            $result->getRemark(),
            json_encode($result->getContext(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function save(): self
    {
        if ($this->id === null) {
            $this->persistStateSnapshot();

            $sql = 'INSERT INTO orders (
                order_no, user_id, total_amount, status, audit_status,
                exception_type, exception_level, rollback_protected, rollback_count,
                writeback_status, last_writeback_at, extra_data, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

            $this->db->execute($sql, [
                $this->orderNo,
                $this->userId,
                $this->totalAmount,
                $this->getStatus(),
                $this->auditStatus,
                $this->exceptionType,
                $this->exceptionLevel,
                $this->rollbackProtected ? 1 : 0,
                $this->rollbackCount,
                $this->writebackStatus,
                $this->lastWritebackAt,
                json_encode($this->extraData, JSON_UNESCAPED_UNICODE),
            ]);

            $this->id = (int) $this->db->lastInsertId();
            $this->createdAt = date('Y-m-d H:i:s');
            $this->updatedAt = date('Y-m-d H:i:s');
            $this->isDirty = false;
        } elseif ($this->isDirty) {
            $this->persistStateSnapshot();

            $sql = 'UPDATE orders SET 
                total_amount = ?, status = ?, audit_status = ?, exception_type = ?,
                exception_level = ?, rollback_protected = ?, rollback_count = ?,
                writeback_status = ?, last_writeback_at = ?, extra_data = ?, updated_at = NOW()
                WHERE id = ?';

            $this->db->execute($sql, [
                $this->totalAmount,
                $this->getStatus(),
                $this->auditStatus,
                $this->exceptionType,
                $this->exceptionLevel,
                $this->rollbackProtected ? 1 : 0,
                $this->rollbackCount,
                $this->writebackStatus,
                $this->lastWritebackAt,
                json_encode($this->extraData, JSON_UNESCAPED_UNICODE),
                $this->id,
            ]);

            $this->updatedAt = date('Y-m-d H:i:s');
            $this->isDirty = false;
        }

        return $this;
    }

    public static function findById(int $id, array $config): ?self
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM orders WHERE id = ?';
        $row = $db->fetchOne($sql, [$id]);

        if ($row === null) {
            return null;
        }

        return self::createFromArray($row, $config);
    }

    public static function findByOrderNo(string $orderNo, array $config): ?self
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM orders WHERE order_no = ?';
        $row = $db->fetchOne($sql, [$orderNo]);

        if ($row === null) {
            return null;
        }

        return self::createFromArray($row, $config);
    }

    private static function createFromArray(array $row, array $config): self
    {
        $order = new self(
            $row['order_no'],
            (int) $row['user_id'],
            (float) $row['total_amount'],
            $row['status'],
            $config,
            (int) $row['id']
        );

        $order->createdAt = $row['created_at'];
        $order->updatedAt = $row['updated_at'];

        $order->auditStatus = $row['audit_status'] ?? AuditStatus::NONE;
        $order->exceptionType = $row['exception_type'] ?? null;
        $order->exceptionLevel = isset($row['exception_level']) ? (int) $row['exception_level'] : ExceptionLevel::NONE;
        $order->rollbackProtected = isset($row['rollback_protected']) ? (bool) $row['rollback_protected'] : false;
        $order->rollbackCount = isset($row['rollback_count']) ? (int) $row['rollback_count'] : 0;
        $order->writebackStatus = $row['writeback_status'] ?? WritebackStatus::PENDING;
        $order->lastWritebackAt = $row['last_writeback_at'] ?? null;

        $extraData = [];
        if (!empty($row['extra_data'])) {
            $decoded = json_decode($row['extra_data'], true);
            if (is_array($decoded)) {
                $extraData = $decoded;
            }
        }
        $order->extraData = $extraData;

        if (isset($extraData['_state_snapshot']) && is_array($extraData['_state_snapshot'])) {
            $order->stateMachine->restoreFromSnapshot($extraData['_state_snapshot']);
        }

        $order->isDirty = false;

        return $order;
    }

    public function toArray(): array
    {
        $availableEvents = $this->getAvailableEvents();
        $availableEventsWithLabels = array_map(function ($event) {
            return [
                'event' => $event,
                'label' => OrderEvent::getLabel($event),
            ];
        }, $availableEvents);

        return [
            'id' => $this->id,
            'order_no' => $this->orderNo,
            'user_id' => $this->userId,
            'total_amount' => $this->totalAmount,
            'status' => $this->getStatus(),
            'status_label' => OrderStatus::getLabel($this->getStatus()),
            'status_color' => OrderStatus::getColor($this->getStatus()),
            'previous_status' => $this->getPreviousStatus(),
            'previous_status_label' => OrderStatus::getLabel($this->getPreviousStatus()),
            'available_events' => $availableEventsWithLabels,
            'can_rollback' => !empty($this->getRollbackStack()),
            'rollback_depth' => count($this->getRollbackStack()),
            'rollback_count' => $this->rollbackCount,
            'exception_reason' => $this->stateMachine->getExceptionReason(),
            'audit_status' => $this->auditStatus,
            'audit_status_label' => $this->getAuditStatusLabel(),
            'audit_status_color' => $this->getAuditStatusColor(),
            'exception_type' => $this->exceptionType,
            'exception_type_label' => $this->getExceptionTypeLabel(),
            'exception_level' => $this->exceptionLevel,
            'exception_level_label' => $this->getExceptionLevelLabel(),
            'exception_level_color' => $this->getExceptionLevelColor(),
            'has_exception' => $this->hasException(),
            'rollback_protected' => $this->rollbackProtected,
            'requires_rollback_audit' => $this->requiresRollbackAudit(),
            'writeback_status' => $this->writebackStatus,
            'writeback_status_label' => $this->getWritebackStatusLabel(),
            'writeback_status_color' => $this->getWritebackStatusColor(),
            'last_writeback_at' => $this->lastWritebackAt,
            'extra_data' => $this->extraData,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function getStatusConsistencyCheck(): array
    {
        $dbStatus = null;
        if ($this->id !== null) {
            $sql = 'SELECT status FROM orders WHERE id = ?';
            $row = $this->db->fetchOne($sql, [$this->id]);
            if ($row !== null) {
                $dbStatus = $row['status'];
            }
        }

        $memoryStatus = $this->getStatus();
        $snapshotStatus = isset($this->extraData['_state_snapshot']['current_status'])
            ? $this->extraData['_state_snapshot']['current_status']
            : null;

        return [
            'db_status' => $dbStatus,
            'memory_status' => $memoryStatus,
            'snapshot_status' => $snapshotStatus,
            'is_consistent' => ($dbStatus === null || $dbStatus === $memoryStatus)
                && ($snapshotStatus === null || $snapshotStatus === $memoryStatus),
        ];
    }

    public static function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
