<?php

namespace Order\Core;

use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;

class Transition
{
    private string $fromStatus;
    private string $event;
    private string $toStatus;
    private $guard;
    private $beforeCallback;
    private $afterCallback;

    public function __construct(
        string $fromStatus,
        string $event,
        string $toStatus,
        $guard = null,
        $beforeCallback = null,
        $afterCallback = null
    ) {
        $this->fromStatus = $fromStatus;
        $this->event = $event;
        $this->toStatus = $toStatus;
        $this->guard = $guard;
        $this->beforeCallback = $beforeCallback;
        $this->afterCallback = $afterCallback;
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function canTransition(object $context = null): bool
    {
        if ($this->guard === null) {
            return true;
        }
        return (bool) call_user_func($this->guard, $context);
    }

    public function before(object $context = null): void
    {
        if ($this->beforeCallback !== null) {
            call_user_func($this->beforeCallback, $context);
        }
    }

    public function after(object $context = null): void
    {
        if ($this->afterCallback !== null) {
            call_user_func($this->afterCallback, $context);
        }
    }
}
