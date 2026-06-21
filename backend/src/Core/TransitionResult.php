<?php

namespace Order\Core;

use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;

class TransitionResult
{
    private bool $success;
    private string $fromStatus;
    private string $toStatus;
    private string $event;
    private ?string $message;
    private array $context;
    private \DateTimeImmutable $occurredAt;
    private string $operatorId;
    private string $remark;

    public function __construct(
        bool $success,
        string $fromStatus,
        string $toStatus,
        string $event,
        ?string $message = null,
        array $context = [],
        string $operatorId = '',
        string $remark = ''
    ) {
        $this->success = $success;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->event = $event;
        $this->message = $message;
        $this->context = $context;
        $this->occurredAt = new \DateTimeImmutable();
        $this->operatorId = $operatorId;
        $this->remark = $remark;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getOperatorId(): string
    {
        return $this->operatorId;
    }

    public function getRemark(): string
    {
        return $this->remark;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'event' => $this->event,
            'message' => $this->message,
            'context' => $this->context,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'operator_id' => $this->operatorId,
            'remark' => $this->remark,
        ];
    }
}
