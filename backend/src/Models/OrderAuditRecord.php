<?php

namespace Order\Models;

use Order\Core\Database;
use Order\Enums\AuditStatus;
use Order\Enums\AuditType;
use Order\Enums\OrderStatus;

class OrderAuditRecord
{
    private ?int $id;
    private int $orderId;
    private string $auditType;
    private string $action;
    private ?string $beforeStatus;
    private ?string $afterStatus;
    private ?string $applicantId;
    private ?string $auditorId;
    private ?string $auditRemark;
    private ?string $reason;
    private array $context = [];
    private string $auditStatus;
    private ?string $submittedAt;
    private ?string $auditedAt;
    private ?string $createdAt;
    private ?string $updatedAt;
    private Database $db;

    public function __construct(
        int $orderId,
        string $auditType,
        string $action,
        array $config,
        ?int $id = null
    ) {
        $this->orderId = $orderId;
        $this->auditType = $auditType;
        $this->action = $action;
        $this->auditStatus = AuditStatus::PENDING;
        $this->id = $id;
        $this->db = Database::getInstance($config['db'] ?? []);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getAuditType(): string
    {
        return $this->auditType;
    }

    public function getAuditTypeLabel(): string
    {
        return AuditType::getLabel($this->auditType);
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getBeforeStatus(): ?string
    {
        return $this->beforeStatus;
    }

    public function setBeforeStatus(?string $beforeStatus): void
    {
        $this->beforeStatus = $beforeStatus;
    }

    public function getAfterStatus(): ?string
    {
        return $this->afterStatus;
    }

    public function setAfterStatus(?string $afterStatus): void
    {
        $this->afterStatus = $afterStatus;
    }

    public function getApplicantId(): ?string
    {
        return $this->applicantId;
    }

    public function setApplicantId(?string $applicantId): void
    {
        $this->applicantId = $applicantId;
    }

    public function getAuditorId(): ?string
    {
        return $this->auditorId;
    }

    public function setAuditorId(?string $auditorId): void
    {
        $this->auditorId = $auditorId;
    }

    public function getAuditRemark(): ?string
    {
        return $this->auditRemark;
    }

    public function setAuditRemark(?string $auditRemark): void
    {
        $this->auditRemark = $auditRemark;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
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

    public function getSubmittedAt(): ?string
    {
        return $this->submittedAt;
    }

    public function getAuditedAt(): ?string
    {
        return $this->auditedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function submit(string $applicantId, string $reason, ?string $beforeStatus = null, ?string $afterStatus = null, array $context = []): self
    {
        $this->applicantId = $applicantId;
        $this->reason = $reason;
        $this->beforeStatus = $beforeStatus;
        $this->afterStatus = $afterStatus;
        $this->context = $context;
        $this->auditStatus = AuditStatus::PENDING;
        $this->submittedAt = date('Y-m-d H:i:s');
        return $this;
    }

    public function approve(string $auditorId, string $auditRemark = ''): self
    {
        $this->auditorId = $auditorId;
        $this->auditRemark = $auditRemark;
        $this->auditStatus = AuditStatus::APPROVED;
        $this->auditedAt = date('Y-m-d H:i:s');
        return $this;
    }

    public function reject(string $auditorId, string $auditRemark = ''): self
    {
        $this->auditorId = $auditorId;
        $this->auditRemark = $auditRemark;
        $this->auditStatus = AuditStatus::REJECTED;
        $this->auditedAt = date('Y-m-d H:i:s');
        return $this;
    }

    public function cancel(string $applicantId, string $auditRemark = ''): self
    {
        $this->applicantId = $applicantId;
        $this->auditRemark = $auditRemark;
        $this->auditStatus = AuditStatus::CANCELLED;
        return $this;
    }

    public function save(): self
    {
        if ($this->id === null) {
            $sql = 'INSERT INTO order_audit_records (
                order_id, audit_type, action, before_status, after_status,
                applicant_id, auditor_id, audit_remark, reason, context,
                audit_status, submitted_at, audited_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

            $this->db->execute($sql, [
                $this->orderId,
                $this->auditType,
                $this->action,
                $this->beforeStatus,
                $this->afterStatus,
                $this->applicantId,
                $this->auditorId,
                $this->auditRemark,
                $this->reason,
                json_encode($this->context, JSON_UNESCAPED_UNICODE),
                $this->auditStatus,
                $this->submittedAt,
                $this->auditedAt,
            ]);

            $this->id = (int) $this->db->lastInsertId();
            $this->createdAt = date('Y-m-d H:i:s');
            $this->updatedAt = date('Y-m-d H:i:s');
        } else {
            $sql = 'UPDATE order_audit_records SET
                before_status = ?, after_status = ?, applicant_id = ?, auditor_id = ?,
                audit_remark = ?, reason = ?, context = ?, audit_status = ?,
                submitted_at = ?, audited_at = ?, updated_at = NOW()
                WHERE id = ?';

            $this->db->execute($sql, [
                $this->beforeStatus,
                $this->afterStatus,
                $this->applicantId,
                $this->auditorId,
                $this->auditRemark,
                $this->reason,
                json_encode($this->context, JSON_UNESCAPED_UNICODE),
                $this->auditStatus,
                $this->submittedAt,
                $this->auditedAt,
                $this->id,
            ]);

            $this->updatedAt = date('Y-m-d H:i:s');
        }

        return $this;
    }

    public static function findById(int $id, array $config): ?self
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM order_audit_records WHERE id = ?';
        $row = $db->fetchOne($sql, [$id]);

        if ($row === null) {
            return null;
        }

        return self::createFromRow($row, $config);
    }

    public static function findByOrderId(int $orderId, array $config, int $limit = 50): array
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM order_audit_records WHERE order_id = ? ORDER BY id DESC LIMIT ' . (int) $limit;
        $rows = $db->fetchAll($sql, [$orderId]);

        $records = [];
        foreach ($rows as $row) {
            $records[] = self::createFromRow($row, $config);
        }

        return $records;
    }

    public static function findPendingByType(string $auditType, array $config, int $page = 1, int $pageSize = 20): array
    {
        $db = Database::getInstance($config['db'] ?? []);
        $offset = ($page - 1) * $pageSize;

        $sql = 'SELECT * FROM order_audit_records 
                WHERE audit_status = ? AND audit_type = ? 
                ORDER BY id DESC LIMIT ' . (int) $offset . ', ' . (int) $pageSize;
        $rows = $db->fetchAll($sql, [AuditStatus::PENDING, $auditType]);

        $records = [];
        foreach ($rows as $row) {
            $records[] = self::createFromRow($row, $config);
        }

        return $records;
    }

    public static function countPendingByType(string $auditType, array $config): int
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT COUNT(*) as total FROM order_audit_records WHERE audit_status = ? AND audit_type = ?';
        $row = $db->fetchOne($sql, [AuditStatus::PENDING, $auditType]);
        return (int) ($row['total'] ?? 0);
    }

    private static function createFromArray(array $row, array $config): self
    {
        $record = new self(
            (int) $row['order_id'],
            $row['audit_type'],
            $row['action'],
            $config,
            (int) $row['id']
        );

        $record->beforeStatus = $row['before_status'];
        $record->afterStatus = $row['after_status'];
        $record->applicantId = $row['applicant_id'];
        $record->auditorId = $row['auditor_id'];
        $record->auditRemark = $row['audit_remark'];
        $record->reason = $row['reason'];
        $record->auditStatus = $row['audit_status'];
        $record->submittedAt = $row['submitted_at'];
        $record->auditedAt = $row['audited_at'];
        $record->createdAt = $row['created_at'];
        $record->updatedAt = $row['updated_at'];

        if (!empty($row['context'])) {
            $decoded = json_decode($row['context'], true);
            if (is_array($decoded)) {
                $record->context = $decoded;
            }
        }

        return $record;
    }

    private static function createFromRow(array $row, array $config): self
    {
        return self::createFromArray($row, $config);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'audit_type' => $this->auditType,
            'audit_type_label' => $this->getAuditTypeLabel(),
            'action' => $this->action,
            'before_status' => $this->beforeStatus,
            'before_status_label' => $this->beforeStatus ? OrderStatus::getLabel($this->beforeStatus) : null,
            'after_status' => $this->afterStatus,
            'after_status_label' => $this->afterStatus ? OrderStatus::getLabel($this->afterStatus) : null,
            'applicant_id' => $this->applicantId,
            'auditor_id' => $this->auditorId,
            'audit_remark' => $this->auditRemark,
            'reason' => $this->reason,
            'context' => $this->context,
            'audit_status' => $this->auditStatus,
            'audit_status_label' => $this->getAuditStatusLabel(),
            'audit_status_color' => $this->getAuditStatusColor(),
            'submitted_at' => $this->submittedAt,
            'audited_at' => $this->auditedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
