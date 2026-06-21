<?php

namespace Order\Models;

use Order\Core\Database;
use Order\Core\StateMachine;
use Order\Core\TransitionResult;
use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
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

    public function markException(string $reason, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($reason, $operatorId, $remark) {
            $result = $this->stateMachine->apply(OrderEvent::MARK_EXCEPTION, $this, $operatorId, $reason);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $reason);
            $this->logTransition($result);
            $this->save();

            return $result;
        });
    }

    public function resolveException(string $targetStatus, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($targetStatus, $operatorId, $remark) {
            $result = $this->stateMachine->resolveException($targetStatus, $this, $operatorId, $remark);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->logTransition($result);
            $this->save();

            return $result;
        });
    }

    public function rollback(string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($operatorId, $remark) {
            $result = $this->stateMachine->rollback($this, $operatorId, $remark);

            $this->persistStateSnapshot();
            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->logTransition($result);
            $this->save();

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
                order_no, user_id, total_amount, status, 
                extra_data, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())';

            $this->db->execute($sql, [
                $this->orderNo,
                $this->userId,
                $this->totalAmount,
                $this->getStatus(),
                json_encode($this->extraData, JSON_UNESCAPED_UNICODE),
            ]);

            $this->id = (int) $this->db->lastInsertId();
            $this->createdAt = date('Y-m-d H:i:s');
            $this->updatedAt = date('Y-m-d H:i:s');
            $this->isDirty = false;
        } elseif ($this->isDirty) {
            $this->persistStateSnapshot();

            $sql = 'UPDATE orders SET 
                total_amount = ?, status = ?, extra_data = ?, updated_at = NOW()
                WHERE id = ?';

            $this->db->execute($sql, [
                $this->totalAmount,
                $this->getStatus(),
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
            'exception_reason' => $this->stateMachine->getExceptionReason(),
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
