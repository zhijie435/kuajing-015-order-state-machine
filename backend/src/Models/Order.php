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
    }

    public function getExtraData(string $key = null)
    {
        if ($key === null) {
            return $this->extraData;
        }
        return $this->extraData[$key] ?? null;
    }

    public function can(string $event): bool
    {
        return $this->stateMachine->can($event, $this);
    }

    public function apply(string $event, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($event, $operatorId, $remark) {
            $result = $this->stateMachine->apply($event, $this, $operatorId, $remark);

            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->logTransition($result);

            return $result;
        });
    }

    public function markException(string $reason, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($reason, $operatorId, $remark) {
            $result = $this->stateMachine->apply(OrderEvent::MARK_EXCEPTION, $this, $operatorId, $reason);

            $this->updateStatus($result->getToStatus(), $operatorId, $reason);
            $this->logTransition($result);

            return $result;
        });
    }

    public function resolveException(string $targetStatus, string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($targetStatus, $operatorId, $remark) {
            $result = $this->stateMachine->resolveException($targetStatus, $this, $operatorId, $remark);

            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->logTransition($result);

            return $result;
        });
    }

    public function rollback(string $operatorId = '', string $remark = ''): TransitionResult
    {
        return $this->db->transactional(function () use ($operatorId, $remark) {
            $result = $this->stateMachine->rollback($this, $operatorId, $remark);

            $this->updateStatus($result->getToStatus(), $operatorId, $remark);
            $this->logTransition($result);

            return $result;
        });
    }

    public function getAvailableEvents(): array
    {
        return $this->stateMachine->getAvailableEvents();
    }

    public function getTransitionHistory(): array
    {
        if ($this->id === null) {
            return [];
        }

        $sql = 'SELECT * FROM order_status_logs WHERE order_id = ? ORDER BY id DESC';
        return $this->db->fetchAll($sql, [$this->id]);
    }

    public function getRollbackStack(): array
    {
        return $this->stateMachine->getRollbackStack();
    }

    private function updateStatus(string $status, string $operatorId, string $remark): void
    {
        if ($this->id === null) {
            return;
        }

        $sql = 'UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?';
        $this->db->execute($sql, [$status, $this->id]);

        $this->updatedAt = date('Y-m-d H:i:s');
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
        } else {
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

        if (!empty($row['extra_data'])) {
            $extraData = json_decode($row['extra_data'], true);
            if (is_array($extraData)) {
                $order->extraData = $extraData;
            }
        }

        return $order;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->orderNo,
            'user_id' => $this->userId,
            'total_amount' => $this->totalAmount,
            'status' => $this->getStatus(),
            'status_label' => OrderStatus::getLabel($this->getStatus()),
            'status_color' => OrderStatus::getColor($this->getStatus()),
            'previous_status' => $this->getPreviousStatus(),
            'available_events' => $this->getAvailableEvents(),
            'extra_data' => $this->extraData,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public static function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
