<?php

namespace Order\Models;

use Order\Core\Database;
use Order\Enums\RollbackProtectionType;

class OrderRollbackProtection
{
    private ?int $id;
    private int $orderId;
    private string $protectionType;
    private ?string $protectedBy;
    private ?string $protectionReason;
    private ?float $thresholdAmount;
    private ?string $protectUntil;
    private bool $isActive;
    private array $context = [];
    private ?string $createdAt;
    private ?string $updatedAt;
    private Database $db;

    public function __construct(
        int $orderId,
        string $protectionType,
        array $config,
        ?int $id = null
    ) {
        $this->orderId = $orderId;
        $this->protectionType = $protectionType;
        $this->isActive = true;
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

    public function getProtectionType(): string
    {
        return $this->protectionType;
    }

    public function getProtectionTypeLabel(): string
    {
        return RollbackProtectionType::getLabel($this->protectionType);
    }

    public function getProtectionTypeDescription(): string
    {
        return RollbackProtectionType::getDescription($this->protectionType);
    }

    public function getProtectedBy(): ?string
    {
        return $this->protectedBy;
    }

    public function setProtectedBy(?string $protectedBy): void
    {
        $this->protectedBy = $protectedBy;
    }

    public function getProtectionReason(): ?string
    {
        return $this->protectionReason;
    }

    public function setProtectionReason(?string $protectionReason): void
    {
        $this->protectionReason = $protectionReason;
    }

    public function getThresholdAmount(): ?float
    {
        return $this->thresholdAmount;
    }

    public function setThresholdAmount(?float $thresholdAmount): void
    {
        $this->thresholdAmount = $thresholdAmount;
    }

    public function getProtectUntil(): ?string
    {
        return $this->protectUntil;
    }

    public function setProtectUntil(?string $protectUntil): void
    {
        $this->protectUntil = $protectUntil;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function activate(): self
    {
        $this->isActive = true;
        return $this;
    }

    public function deactivate(): self
    {
        $this->isActive = false;
        return $this;
    }

    public function isExpired(): bool
    {
        if ($this->protectUntil === null) {
            return false;
        }
        return strtotime($this->protectUntil) < time();
    }

    public function isAmountExceeded(float $amount): bool
    {
        if ($this->protectionType !== RollbackProtectionType::AMOUNT_THRESHOLD) {
            return false;
        }
        if ($this->thresholdAmount === null) {
            return false;
        }
        return $amount >= $this->thresholdAmount;
    }

    public function isValid(): bool
    {
        if (!$this->isActive) {
            return false;
        }
        if ($this->isExpired()) {
            return false;
        }
        return true;
    }

    public function save(): self
    {
        if ($this->id === null) {
            $sql = 'INSERT INTO order_rollback_protections (
                order_id, protection_type, protected_by, protection_reason,
                threshold_amount, protect_until, is_active, context,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

            $this->db->execute($sql, [
                $this->orderId,
                $this->protectionType,
                $this->protectedBy,
                $this->protectionReason,
                $this->thresholdAmount,
                $this->protectUntil,
                $this->isActive ? 1 : 0,
                json_encode($this->context, JSON_UNESCAPED_UNICODE),
            ]);

            $this->id = (int) $this->db->lastInsertId();
            $this->createdAt = date('Y-m-d H:i:s');
            $this->updatedAt = date('Y-m-d H:i:s');
        } else {
            $sql = 'UPDATE order_rollback_protections SET
                protected_by = ?, protection_reason = ?, threshold_amount = ?,
                protect_until = ?, is_active = ?, context = ?, updated_at = NOW()
                WHERE id = ?';

            $this->db->execute($sql, [
                $this->protectedBy,
                $this->protectionReason,
                $this->thresholdAmount,
                $this->protectUntil,
                $this->isActive ? 1 : 0,
                json_encode($this->context, JSON_UNESCAPED_UNICODE),
                $this->id,
            ]);

            $this->updatedAt = date('Y-m-d H:i:s');
        }

        return $this;
    }

    public static function findById(int $id, array $config): ?self
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM order_rollback_protections WHERE id = ?';
        $row = $db->fetchOne($sql, [$id]);

        if ($row === null) {
            return null;
        }

        return self::createFromRow($row, $config);
    }

    public static function findByOrderId(int $orderId, array $config, bool $activeOnly = true): array
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'SELECT * FROM order_rollback_protections WHERE order_id = ?';
        $params = [$orderId];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY id DESC';
        $rows = $db->fetchAll($sql, $params);

        $protections = [];
        foreach ($rows as $row) {
            $protections[] = self::createFromRow($row, $config);
        }

        return $protections;
    }

    public static function findActiveProtections(int $orderId, array $config): array
    {
        $protections = self::findByOrderId($orderId, $config, true);
        return array_filter($protections, function ($protection) {
            return $protection->isValid();
        });
    }

    public static function hasActiveProtection(int $orderId, array $config): bool
    {
        return count(self::findActiveProtections($orderId, $config)) > 0;
    }

    public static function deactivateAllForOrder(int $orderId, array $config): int
    {
        $db = Database::getInstance($config['db'] ?? []);
        $sql = 'UPDATE order_rollback_protections SET is_active = 0, updated_at = NOW() WHERE order_id = ? AND is_active = 1';
        $stmt = $db->execute($sql, [$orderId]);
        return $stmt->rowCount();
    }

    public static function createProtection(
        int $orderId,
        string $protectionType,
        string $protectedBy,
        string $protectionReason,
        array $config,
        ?float $thresholdAmount = null,
        ?string $protectUntil = null,
        array $context = []
    ): self {
        $protection = new self($orderId, $protectionType, $config);
        $protection->setProtectedBy($protectedBy);
        $protection->setProtectionReason($protectionReason);
        $protection->setThresholdAmount($thresholdAmount);
        $protection->setProtectUntil($protectUntil);
        $protection->setContext($context);
        $protection->save();

        return $protection;
    }

    private static function createFromArray(array $row, array $config): self
    {
        $protection = new self(
            (int) $row['order_id'],
            $row['protection_type'],
            $config,
            (int) $row['id']
        );

        $protection->protectedBy = $row['protected_by'];
        $protection->protectionReason = $row['protection_reason'];
        $protection->thresholdAmount = $row['threshold_amount'] !== null ? (float) $row['threshold_amount'] : null;
        $protection->protectUntil = $row['protect_until'];
        $protection->isActive = (bool) $row['is_active'];
        $protection->createdAt = $row['created_at'];
        $protection->updatedAt = $row['updated_at'];

        if (!empty($row['context'])) {
            $decoded = json_decode($row['context'], true);
            if (is_array($decoded)) {
                $protection->context = $decoded;
            }
        }

        return $protection;
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
            'protection_type' => $this->protectionType,
            'protection_type_label' => $this->getProtectionTypeLabel(),
            'protection_type_description' => $this->getProtectionTypeDescription(),
            'protected_by' => $this->protectedBy,
            'protection_reason' => $this->protectionReason,
            'threshold_amount' => $this->thresholdAmount,
            'protect_until' => $this->protectUntil,
            'is_active' => $this->isActive,
            'is_expired' => $this->isExpired(),
            'is_valid' => $this->isValid(),
            'context' => $this->context,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
