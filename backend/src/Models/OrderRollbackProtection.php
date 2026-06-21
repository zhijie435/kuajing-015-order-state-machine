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
