<?php

namespace Order\Models;

use Order\Core\Database;
use Order\Enums\WritebackStatus;
use Order\Enums\WritebackType;
use Order\Enums\TargetSystem;

class OrderWritebackLog
{
    private ?int $id;
    private int $orderId;
    private string $targetSystem;
    private string $writebackType;
    private array $writebackData = [];
    private string $writebackStatus;
    private int $retryCount;
    private int $maxRetryCount;
    private ?string $errorMessage;
    private ?string $operatorId;
    private ?string $lastAttemptAt;
    private ?string $completedAt;
    private ?string $createdAt;
    private ?string $updatedAt;
    private Database $db;

    public function __construct(
        int $orderId,
        string $targetSystem,
        string $writebackType,
        array $config,
        ?int $id = null
    ) {
        $this->orderId = $orderId;
        $this->targetSystem = $targetSystem;
        $this->writebackType = $writebackType;
        $this->writebackStatus = WritebackStatus::PENDING;
        $this->retryCount = 0;
        $this->maxRetryCount = 3;
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

    public function getTargetSystem(): string
    {
        return $this->targetSystem;
    }

    public function getTargetSystemLabel(): string
    {
        return TargetSystem::getLabel($this->targetSystem);
    }

    public function getWritebackType(): string
    {
        return $this->writebackType;
    }

    public function getWritebackTypeLabel(): string
    {
        return WritebackType::getLabel($this->writebackType);
    }

    public function getWritebackData(): array
    {
        return $this->writebackData;
    }

    public function setWritebackData(array $writebackData): void
    {
        $this->writebackData = $writebackData;
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

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getMaxRetryCount(): int
    {
        return $this->maxRetryCount;
    }

    public function setMaxRetryCount(int $maxRetryCount): void
    {
        $this->maxRetryCount = $maxRetryCount;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getOperatorId(): ?string
    {
        return $this->operatorId;
    }

    public function setOperatorId(?string $operatorId): void
    {
        $this->operatorId = $operatorId;
    }

    public function getLastAttemptAt(): ?string
    {
        return $this->lastAttemptAt;
    }

    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function canRetry(): bool
    {
        if ($this->retryCount >= $this->maxRetryCount) {
            return false;
        }
        return WritebackStatus::canRetry($this->writebackStatus);
    }

    public function isCompleted(): bool
    {
        return WritebackStatus::isFinal($this->writebackStatus);
    }

    public function markSuccess(string $operatorId = null): self
    {
        $this->writebackStatus = WritebackStatus::SUCCESS;
        $this->errorMessage = null;
        $this->lastAttemptAt = date('Y-m-d H:i:s');
        $this->completedAt = date('Y-m-d H:i:s');
        if ($operatorId !== null) {
            $this->operatorId = $operatorId;
        }
        return $this;
    }

    public function markFailed(string $errorMessage, string $operatorId = null): self
    {
        $this->writebackStatus = WritebackStatus::FAILED;
        $this->errorMessage = $errorMessage;
        $this->lastAttemptAt = date('Y-m-d H:i:s');
        if ($operatorId !== null) {
            $this->operatorId = $operatorId;
        }
        return $this;
    }

    public function markRetrying(string $operatorId = null): self
    {
        $this->writebackStatus = WritebackStatus::RETRYING;
        $this->retryCount++;
        $this->lastAttemptAt = date('Y-m-d H:i:s');
        if ($operatorId !== null) {
            $this->operatorId = $operatorId;
        }
        return $this;
    }

    public function markSkipped(string $reason = '', string $operatorId = null): self
    {
        $this->writebackStatus = WritebackStatus::SKIPPED;
        $this->errorMessage = $reason;
        $this->completedAt = date('Y-m-d H:i:s');
        if ($operatorId !== null) {
            $this->operatorId = $operatorId;
        }
        return $this;
    }

    public function attemptRetry(): bool
    {
        if (!$this->canRetry()) {
            if ($this->retryCount >= $this->maxRetryCount) {
                $this->markFailed('已达到最大重试次数');
                $this->save();
            }
            return false;
        }

        $this->markRetrying();
        $this->save();
        return true;
    }

    public function save(): self
    {
        if ($this->id === null) {
            $sql = 'INSERT INTO order_writeback_logs (
                order_id, target_system, writeback_type, writeback_data,
                writeback_status, retry_count, max_retry_count, error_message,
                operator_id, last_attempt_at, completed_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

            $this->db->execute($sql, [
                $this->orderId,
                $this->targetSystem,
                $this->writebackType,
                json_encode($this->writebackData, JSON_UNESCAPED_UNICODE),
                $this->writebackStatus,
                $this->retryCount,
                $this->maxRetryCount,
                $this->errorMessage,
                $this->operatorId,
                $this->lastAttemptAt,
                $this->completedAt,
            ]);

            $this->id = (int) $this->db->lastInsertId();
            $this->createdAt = date('Y-m-d H:i:s');
            $this->updatedAt = date('Y-m-d H:i:s');
        } else {
            $sql = 'UPDATE order_writeback_logs SET
                writeback_data = ?, writeback_status = ?, retry_count = ?,
                max_retry_count = ?, error_message = ?, operator_id = ?,
                last_attempt_at = ?, completed_at = ?, updated_at = NOW()
                WHERE id = ?';

            $this->db->execute($sql, [
                json_encode($this->writebackData, JSON_UNESCAPED_UNICODE),
                $this->writebackStatus,
                $this->retryCount,
                $this->maxRetryCount,
                $this->errorMessage,
                $this->operatorId,
                $this->lastAttemptAt,
                $this->completedAt,
                $this->id,
            ]);

            $this->updatedAt = date('Y-m-d H:i:s');
        }

        return $this;
    }

    public static function findById(int $id, array $config): ?self
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM order_writeback_logs WHERE id = ?';
        $row = $db->fetchOne($sql, [$id]);

        if ($row === null) {
            return null;
        }

        return self::createFromRow($row, $config);
    }

    public static function findByOrderId(int $orderId, array $config, int $limit = 50): array
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM order_writeback_logs WHERE order_id = ? ORDER BY id DESC LIMIT ' . (int) $limit;
        $rows = $db->fetchAll($sql, [$orderId]);

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = self::createFromRow($row, $config);
        }

        return $logs;
    }

    public static function findPendingOrFailed(array $config, int $page = 1, int $pageSize = 20): array
    {
        $db = Database::getInstance($config['db'] ?? []);
        $offset = ($page - 1) * $pageSize;

        $sql = 'SELECT * FROM order_writeback_logs 
                WHERE writeback_status IN (?, ?, ?)
                ORDER BY id ASC LIMIT ' . (int) $offset . ', ' . (int) $pageSize;
        $rows = $db->fetchAll($sql, [
            WritebackStatus::PENDING,
            WritebackStatus::FAILED,
            WritebackStatus::RETRYING,
        ]);

        $logs = [];
        foreach ($rows as $row) {
            $logs[] = self::createFromRow($row, $config);
        }

        return $logs;
    }

    public static function countPendingOrFailed(array $config): int
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT COUNT(*) as total FROM order_writeback_logs 
                WHERE writeback_status IN (?, ?, ?)';
        $row = $db->fetchOne($sql, [
            WritebackStatus::PENDING,
            WritebackStatus::FAILED,
            WritebackStatus::RETRYING,
        ]);
        return (int) ($row['total'] ?? 0);
    }

    public static function createLog(
        int $orderId,
        string $targetSystem,
        string $writebackType,
        array $writebackData,
        array $config,
        string $operatorId = null,
        int $maxRetryCount = 3
    ): self {
        $log = new self($orderId, $targetSystem, $writebackType, $config);
        $log->setWritebackData($writebackData);
        $log->setOperatorId($operatorId);
        $log->setMaxRetryCount($maxRetryCount);
        $log->save();

        return $log;
    }

    public static function batchCreateForOrder(
        int $orderId,
        string $writebackType,
        array $writebackData,
        array $config,
        string $operatorId = null
    ): array {
        $targetSystems = WritebackType::getTargetSystems($writebackType);
        $logs = [];

        foreach ($targetSystems as $system) {
            $logs[] = self::createLog(
                $orderId,
                $system,
                $writebackType,
                $writebackData,
                $config,
                $operatorId
            );
        }

        return $logs;
    }

    private static function createFromArray(array $row, array $config): self
    {
        $log = new self(
            (int) $row['order_id'],
            $row['target_system'],
            $row['writeback_type'],
            $config,
            (int) $row['id']
        );

        $log->writebackStatus = $row['writeback_status'];
        $log->retryCount = (int) $row['retry_count'];
        $log->maxRetryCount = (int) $row['max_retry_count'];
        $log->errorMessage = $row['error_message'];
        $log->operatorId = $row['operator_id'];
        $log->lastAttemptAt = $row['last_attempt_at'];
        $log->completedAt = $row['completed_at'];
        $log->createdAt = $row['created_at'];
        $log->updatedAt = $row['updated_at'];

        if (!empty($row['writeback_data'])) {
            $decoded = json_decode($row['writeback_data'], true);
            if (is_array($decoded)) {
                $log->writebackData = $decoded;
            }
        }

        return $log;
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
            'target_system' => $this->targetSystem,
            'target_system_label' => $this->getTargetSystemLabel(),
            'writeback_type' => $this->writebackType,
            'writeback_type_label' => $this->getWritebackTypeLabel(),
            'writeback_data' => $this->writebackData,
            'writeback_status' => $this->writebackStatus,
            'writeback_status_label' => $this->getWritebackStatusLabel(),
            'writeback_status_color' => $this->getWritebackStatusColor(),
            'retry_count' => $this->retryCount,
            'max_retry_count' => $this->maxRetryCount,
            'can_retry' => $this->canRetry(),
            'is_completed' => $this->isCompleted(),
            'error_message' => $this->errorMessage,
            'operator_id' => $this->operatorId,
            'last_attempt_at' => $this->lastAttemptAt,
            'completed_at' => $this->completedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
