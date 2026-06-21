<?php

namespace Order\Core;

use Order\Enums\OrderStatus;
use Order\Enums\OrderEvent;
use Order\Exceptions\StateMachineException;

class StateMachine
{
    private string $currentStatus;
    private array $transitions = [];
    private array $history = [];
    private array $rollbackStack = [];
    private string $previousStatus;
    private ?string $exceptionReason = null;
    private array $config;

    public function __construct(string $initialStatus = OrderStatus::PENDING, array $config = [])
    {
        if (!OrderStatus::exists($initialStatus)) {
            throw StateMachineException::invalidStatus($initialStatus);
        }

        $this->currentStatus = $initialStatus;
        $this->previousStatus = $initialStatus;
        $this->config = array_merge([
            'strict_validation' => true,
            'allow_force_transition' => false,
            'transition_log_enabled' => true,
            'rollback_enabled' => true,
            'max_rollback_depth' => 3,
        ], $config);

        $this->setupDefaultTransitions();
    }

    private function setupDefaultTransitions(): void
    {
        $transitions = [
            [OrderStatus::PENDING, OrderEvent::PAY, OrderStatus::PAID],
            [OrderStatus::PENDING, OrderEvent::CANCEL, OrderStatus::CANCELLED],
            [OrderStatus::PAID, OrderEvent::SHIP, OrderStatus::SHIPPED],
            [OrderStatus::PAID, OrderEvent::CANCEL, OrderStatus::CANCELLED],
            [OrderStatus::PAID, OrderEvent::APPLY_REFUND, OrderStatus::REFUNDING],
            [OrderStatus::SHIPPED, OrderEvent::CONFIRM_RECEIPT, OrderStatus::DELIVERED],
            [OrderStatus::SHIPPED, OrderEvent::APPLY_REFUND, OrderStatus::REFUNDING],
            [OrderStatus::DELIVERED, OrderEvent::COMPLETE, OrderStatus::COMPLETED],
            [OrderStatus::DELIVERED, OrderEvent::APPLY_REFUND, OrderStatus::REFUNDING],
            [OrderStatus::REFUNDING, OrderEvent::APPROVE_REFUND, OrderStatus::REFUNDED],
            [OrderStatus::REFUNDING, OrderEvent::REJECT_REFUND, OrderStatus::PAID],
            [OrderStatus::EXCEPTION, OrderEvent::RESOLVE_EXCEPTION, OrderStatus::PENDING],
        ];

        foreach ($transitions as [$from, $event, $to]) {
            $this->addTransition(new Transition($from, $event, $to));
        }
    }

    public function addTransition(Transition $transition): void
    {
        $key = $transition->getFromStatus() . '.' . $transition->getEvent();
        $this->transitions[$key] = $transition;
    }

    public function getCurrentStatus(): string
    {
        return $this->currentStatus;
    }

    public function getPreviousStatus(): string
    {
        return $this->previousStatus;
    }

    public function getHistory(): array
    {
        return $this->history;
    }

    public function getRollbackStack(): array
    {
        return $this->rollbackStack;
    }

    public function getExceptionReason(): ?string
    {
        return $this->exceptionReason;
    }

    public function can(string $event, object $context = null): bool
    {
        if (!OrderEvent::exists($event)) {
            return false;
        }

        if ($this->currentStatus === OrderStatus::EXCEPTION && $event !== OrderEvent::RESOLVE_EXCEPTION) {
            return false;
        }

        if ($event === OrderEvent::MARK_EXCEPTION) {
            return !OrderStatus::isTerminal($this->currentStatus);
        }

        $key = $this->currentStatus . '.' . $event;
        if (!isset($this->transitions[$key])) {
            return false;
        }

        return $this->transitions[$key]->canTransition($context);
    }

    public function apply(string $event, object $context = null, string $operatorId = '', string $remark = ''): TransitionResult
    {
        if (!OrderEvent::exists($event)) {
            throw StateMachineException::invalidEvent($event);
        }

        if ($this->config['strict_validation'] && OrderStatus::isTerminal($this->currentStatus) && $event !== OrderEvent::MARK_EXCEPTION) {
            throw StateMachineException::terminalStatus($this->currentStatus);
        }

        if ($this->currentStatus === OrderStatus::EXCEPTION && $event !== OrderEvent::RESOLVE_EXCEPTION) {
            throw StateMachineException::exceptionState(OrderEvent::getLabel($event));
        }

        if ($event === OrderEvent::MARK_EXCEPTION) {
            return $this->markException($context, $operatorId, $remark);
        }

        if ($event === OrderEvent::ROLLBACK) {
            return $this->rollback($context, $operatorId, $remark);
        }

        $key = $this->currentStatus . '.' . $event;
        if (!isset($this->transitions[$key])) {
            throw StateMachineException::invalidTransition($this->currentStatus, $event);
        }

        $transition = $this->transitions[$key];

        if (!$transition->canTransition($context)) {
            throw StateMachineException::validationFailed('Guard condition not met');
        }

        $fromStatus = $this->currentStatus;
        $toStatus = $transition->getToStatus();

        try {
            $transition->before($context);

            $this->previousStatus = $this->currentStatus;
            $this->currentStatus = $toStatus;

            $this->pushToRollbackStack($fromStatus, $event, $context, $operatorId, $remark);

            $transition->after($context);

            $result = new TransitionResult(
                true,
                $fromStatus,
                $toStatus,
                $event,
                'State transition successful',
                $context ? (array) $context : [],
                $operatorId,
                $remark
            );

            $this->logTransition($result);

            return $result;
        } catch (\Exception $e) {
            $this->currentStatus = $fromStatus;

            $result = new TransitionResult(
                false,
                $fromStatus,
                $fromStatus,
                $event,
                $e->getMessage(),
                $context ? (array) $context : [],
                $operatorId,
                $remark
            );

            $this->logTransition($result);

            throw StateMachineException::transactionFailed($e->getMessage());
        }
    }

    private function markException(?object $context, string $operatorId, string $remark): TransitionResult
    {
        $fromStatus = $this->currentStatus;
        $this->previousStatus = $fromStatus;
        $this->currentStatus = OrderStatus::EXCEPTION;
        $this->exceptionReason = $remark ?: 'Unknown exception';

        $this->pushToRollbackStack($fromStatus, OrderEvent::MARK_EXCEPTION, $context, $operatorId, $remark);

        $result = new TransitionResult(
            true,
            $fromStatus,
            OrderStatus::EXCEPTION,
            OrderEvent::MARK_EXCEPTION,
            'Order marked as exception: ' . $this->exceptionReason,
            $context ? (array) $context : [],
            $operatorId,
            $remark
        );

        $this->logTransition($result);

        return $result;
    }

    public function resolveException(string $targetStatus, ?object $context = null, string $operatorId = '', string $remark = ''): TransitionResult
    {
        if ($this->currentStatus !== OrderStatus::EXCEPTION) {
            throw StateMachineException::invalidTransition($this->currentStatus, OrderEvent::RESOLVE_EXCEPTION);
        }

        if (!OrderStatus::exists($targetStatus)) {
            throw StateMachineException::invalidStatus($targetStatus);
        }

        $fromStatus = $this->currentStatus;
        $this->currentStatus = $targetStatus;
        $this->previousStatus = OrderStatus::EXCEPTION;
        $this->exceptionReason = null;

        $result = new TransitionResult(
            true,
            $fromStatus,
            $targetStatus,
            OrderEvent::RESOLVE_EXCEPTION,
            'Exception resolved, status restored to: ' . OrderStatus::getLabel($targetStatus),
            $context ? (array) $context : [],
            $operatorId,
            $remark
        );

        $this->logTransition($result);

        return $result;
    }

    private function pushToRollbackStack(string $fromStatus, string $event, ?object $context, string $operatorId, string $remark): void
    {
        if (!$this->config['rollback_enabled']) {
            return;
        }

        $stackItem = [
            'from_status' => $fromStatus,
            'to_status' => $this->currentStatus,
            'event' => $event,
            'context' => $context ? (array) $context : [],
            'operator_id' => $operatorId,
            'remark' => $remark,
            'timestamp' => time(),
        ];

        array_unshift($this->rollbackStack, $stackItem);

        if (count($this->rollbackStack) > $this->config['max_rollback_depth']) {
            array_pop($this->rollbackStack);
        }
    }

    public function rollback(?object $context = null, string $operatorId = '', string $remark = ''): TransitionResult
    {
        if (!$this->config['rollback_enabled']) {
            throw StateMachineException::rollbackDisabled();
        }

        if (empty($this->rollbackStack)) {
            throw StateMachineException::noRollbackHistory();
        }

        if (count($this->rollbackStack) >= $this->config['max_rollback_depth']) {
            throw StateMachineException::rollbackDepthExceeded($this->config['max_rollback_depth']);
        }

        $rollbackItem = array_shift($this->rollbackStack);
        $fromStatus = $this->currentStatus;
        $toStatus = $rollbackItem['from_status'];

        $this->previousStatus = $fromStatus;
        $this->currentStatus = $toStatus;

        if ($fromStatus === OrderStatus::EXCEPTION) {
            $this->exceptionReason = null;
        }

        $result = new TransitionResult(
            true,
            $fromStatus,
            $toStatus,
            OrderEvent::ROLLBACK,
            sprintf('Rolled back from %s to %s', OrderStatus::getLabel($fromStatus), OrderStatus::getLabel($toStatus)),
            array_merge($rollbackItem['context'], $context ? (array) $context : []),
            $operatorId,
            $remark ?: 'Rollback: ' . $rollbackItem['remark']
        );

        $this->logTransition($result);

        return $result;
    }

    private function logTransition(TransitionResult $result): void
    {
        if (!$this->config['transition_log_enabled']) {
            return;
        }

        $this->history[] = $result;
    }

    public function getAvailableEvents(): array
    {
        $availableEvents = [];

        if ($this->currentStatus === OrderStatus::EXCEPTION) {
            $availableEvents[] = OrderEvent::RESOLVE_EXCEPTION;
            return $availableEvents;
        }

        if (!OrderStatus::isTerminal($this->currentStatus)) {
            $availableEvents[] = OrderEvent::MARK_EXCEPTION;
        }

        if (!empty($this->rollbackStack) && $this->config['rollback_enabled']) {
            $availableEvents[] = OrderEvent::ROLLBACK;
        }

        foreach ($this->transitions as $key => $transition) {
            [$fromStatus, $event] = explode('.', $key, 2);
            if ($fromStatus === $this->currentStatus && !in_array($event, $availableEvents, true)) {
                $availableEvents[] = $event;
            }
        }

        return $availableEvents;
    }

    public function forceTransition(string $toStatus, string $operatorId = '', string $remark = ''): TransitionResult
    {
        if (!$this->config['allow_force_transition']) {
            throw StateMachineException::validationFailed('Force transition is not allowed');
        }

        if (!OrderStatus::exists($toStatus)) {
            throw StateMachineException::invalidStatus($toStatus);
        }

        $fromStatus = $this->currentStatus;
        $this->previousStatus = $fromStatus;
        $this->currentStatus = $toStatus;

        $result = new TransitionResult(
            true,
            $fromStatus,
            $toStatus,
            'force_transition',
            'Force transition executed',
            [],
            $operatorId,
            $remark
        );

        $this->logTransition($result);

        return $result;
    }

    public function getTransitionMap(): array
    {
        $map = [];
        foreach ($this->transitions as $key => $transition) {
            [$from, $event] = explode('.', $key, 2);
            if (!isset($map[$from])) {
                $map[$from] = [];
            }
            $map[$from][] = [
                'event' => $event,
                'event_label' => OrderEvent::getLabel($event),
                'to' => $transition->getToStatus(),
                'to_label' => OrderStatus::getLabel($transition->getToStatus()),
            ];
        }
        return $map;
    }
}
