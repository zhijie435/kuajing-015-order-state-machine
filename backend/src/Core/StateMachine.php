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
    private ?string $preExceptionStatus = null;
    private ?string $exceptionType = null;
    private array $config;

    private const EXCEPTION_RESOLVE_MAP = [
        'payment_abnormal' => [OrderStatus::PENDING, OrderStatus::PAID, OrderStatus::CANCELLED],
        'shipping_abnormal' => [OrderStatus::PAID, OrderStatus::SHIPPED, OrderStatus::CANCELLED],
        'system_abnormal' => [],
        'manual_handling' => [],
        'inventory_abnormal' => [OrderStatus::PENDING, OrderStatus::PAID, OrderStatus::CANCELLED],
        'refund_abnormal' => [OrderStatus::PAID, OrderStatus::REFUNDING, OrderStatus::CANCELLED],
        'other' => [],
    ];

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

    public function getPreExceptionStatus(): ?string
    {
        return $this->preExceptionStatus;
    }

    public function getExceptionType(): ?string
    {
        return $this->exceptionType;
    }

    public function can(string $event, object $context = null): bool
    {
        $result = $this->checkCan($event, $context);
        return $result['allowed'];
    }

    public function checkCan(string $event, object $context = null): array
    {
        $isKnownEvent = OrderEvent::exists($event);
        $hasTransition = isset($this->transitions[$this->currentStatus . '.' . $event]);

        if (!$isKnownEvent && !$hasTransition) {
            return [
                'allowed' => false,
                'error_code' => 'invalid_event',
                'error_message' => sprintf('无效的操作: "%s"', $event),
                'suggestion' => '请检查操作是否正确',
            ];
        }

        if ($this->currentStatus === OrderStatus::EXCEPTION) {
            if ($event === OrderEvent::RESOLVE_EXCEPTION) {
                return [
                    'allowed' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'suggestion' => null,
                ];
            }
            return [
                'allowed' => false,
                'error_code' => 'exception_state',
                'error_message' => sprintf('订单处于异常状态，无法执行 "%s" 操作', OrderEvent::getLabel($event)),
                'suggestion' => '请先解决异常或联系管理员',
            ];
        }

        if ($event === OrderEvent::MARK_EXCEPTION) {
            if (OrderStatus::isTerminal($this->currentStatus)) {
                return [
                    'allowed' => false,
                    'error_code' => 'terminal_status',
                    'error_message' => sprintf('终态订单 "%s" 无法标记异常', OrderStatus::getLabel($this->currentStatus)),
                    'suggestion' => '终态订单不支持异常标记操作',
                ];
            }
            return [
                'allowed' => true,
                'error_code' => null,
                'error_message' => null,
                'suggestion' => null,
            ];
        }

        if ($event === OrderEvent::ROLLBACK) {
            if (!$this->config['rollback_enabled']) {
                return [
                    'allowed' => false,
                    'error_code' => 'rollback_disabled',
                    'error_message' => '系统未启用回滚功能',
                    'suggestion' => '请联系管理员开启回滚功能',
                ];
            }
            if (empty($this->rollbackStack)) {
                return [
                    'allowed' => false,
                    'error_code' => 'no_rollback_history',
                    'error_message' => '没有可回滚的历史记录',
                    'suggestion' => '当前订单状态无需回滚',
                ];
            }
            if (count($this->rollbackStack) >= $this->config['max_rollback_depth']) {
                return [
                    'allowed' => false,
                    'error_code' => 'rollback_depth_exceeded',
                    'error_message' => sprintf('已达到最大回滚深度 (%d) 限制', $this->config['max_rollback_depth']),
                    'suggestion' => '请联系管理员进行特殊处理',
                ];
            }
            return [
                'allowed' => true,
                'error_code' => null,
                'error_message' => null,
                'suggestion' => null,
            ];
        }

        if (OrderStatus::isTerminal($this->currentStatus)) {
            return [
                'allowed' => false,
                'error_code' => 'terminal_status',
                'error_message' => sprintf('终态订单 "%s" 无法执行 "%s" 操作', OrderStatus::getLabel($this->currentStatus), OrderEvent::getLabel($event)),
                'suggestion' => '终态订单不支持状态变更',
            ];
        }

        $key = $this->currentStatus . '.' . $event;
        if (!isset($this->transitions[$key])) {
            $allowedEvents = $this->getAllowedEventsFromCurrentStatus();
            $allowedEventLabels = array_map(function ($e) {
                return OrderEvent::getLabel($e);
            }, $allowedEvents);
            return [
                'allowed' => false,
                'error_code' => 'invalid_transition',
                'error_message' => sprintf('当前状态 "%s" 不支持 "%s" 操作', OrderStatus::getLabel($this->currentStatus), OrderEvent::getLabel($event)),
                'suggestion' => !empty($allowedEventLabels)
                    ? sprintf('当前可执行操作: %s', implode('、', $allowedEventLabels))
                    : '当前状态无可执行操作',
                'allowed_events' => $allowedEvents,
            ];
        }

        $transition = $this->transitions[$key];
        if (!$transition->canTransition($context)) {
            return [
                'allowed' => false,
                'error_code' => 'guard_failed',
                'error_message' => sprintf('操作 "%s" 的前置条件未满足', OrderEvent::getLabel($event)),
                'suggestion' => '请检查订单信息是否完整或联系管理员',
            ];
        }

        return [
            'allowed' => true,
            'error_code' => null,
            'error_message' => null,
            'suggestion' => null,
        ];
    }

    private function getAllowedEventsFromCurrentStatus(): array
    {
        $allowedEvents = [];

        if ($this->currentStatus === OrderStatus::EXCEPTION) {
            $allowedEvents[] = OrderEvent::RESOLVE_EXCEPTION;
            return $allowedEvents;
        }

        if (!OrderStatus::isTerminal($this->currentStatus)) {
            $allowedEvents[] = OrderEvent::MARK_EXCEPTION;
        }

        if (!empty($this->rollbackStack) && $this->config['rollback_enabled']) {
            $allowedEvents[] = OrderEvent::ROLLBACK;
        }

        foreach ($this->transitions as $key => $transition) {
            [$fromStatus, $event] = explode('.', $key, 2);
            if ($fromStatus === $this->currentStatus && !in_array($event, $allowedEvents, true)) {
                $allowedEvents[] = $event;
            }
        }

        return $allowedEvents;
    }

    public function getValidationErrors(string $event, object $context = null): array
    {
        $result = $this->checkCan($event, $context);
        if ($result['allowed']) {
            return [];
        }
        return [
            'code' => $result['error_code'],
            'message' => $result['error_message'],
            'suggestion' => $result['suggestion'] ?? '',
            'details' => $result['allowed_events'] ?? [],
        ];
    }

    public function apply(string $event, object $context = null, string $operatorId = '', string $remark = ''): TransitionResult
    {
        $isKnownEvent = OrderEvent::exists($event);
        $hasTransition = isset($this->transitions[$this->currentStatus . '.' . $event]);

        if (!$isKnownEvent && !$hasTransition) {
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
            throw StateMachineException::validationFailed(sprintf('操作 "%s" 的前置条件未满足，请检查订单信息是否完整', OrderEvent::getLabel($event)));
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
                sprintf('状态变更成功: %s → %s', OrderStatus::getLabel($fromStatus), OrderStatus::getLabel($toStatus)),
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
        $this->exceptionReason = $remark ?: '未说明的异常';

        $this->pushToRollbackStack($fromStatus, OrderEvent::MARK_EXCEPTION, $context, $operatorId, $remark);

        $result = new TransitionResult(
            true,
            $fromStatus,
            OrderStatus::EXCEPTION,
            OrderEvent::MARK_EXCEPTION,
            sprintf('订单已标记为异常: %s', $this->exceptionReason),
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
            sprintf('异常已解决，状态恢复为: %s', OrderStatus::getLabel($targetStatus)),
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
            sprintf('状态已回滚: %s → %s', OrderStatus::getLabel($fromStatus), OrderStatus::getLabel($toStatus)),
            array_merge($rollbackItem['context'], $context ? (array) $context : []),
            $operatorId,
            $remark ?: ('回滚: ' . $rollbackItem['remark'])
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
            throw StateMachineException::validationFailed('系统未启用强制状态变更功能');
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
            sprintf('强制状态变更: %s → %s', OrderStatus::getLabel($fromStatus), OrderStatus::getLabel($toStatus)),
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

    public function getSnapshot(): array
    {
        return [
            'current_status' => $this->currentStatus,
            'previous_status' => $this->previousStatus,
            'rollback_stack' => $this->rollbackStack,
            'exception_reason' => $this->exceptionReason,
        ];
    }

    public function restoreFromSnapshot(array $snapshot): void
    {
        if (isset($snapshot['current_status']) && OrderStatus::exists($snapshot['current_status'])) {
            $this->currentStatus = $snapshot['current_status'];
        }
        if (isset($snapshot['previous_status']) && OrderStatus::exists($snapshot['previous_status'])) {
            $this->previousStatus = $snapshot['previous_status'];
        }
        if (isset($snapshot['rollback_stack']) && is_array($snapshot['rollback_stack'])) {
            $this->rollbackStack = $snapshot['rollback_stack'];
        }
        if (isset($snapshot['exception_reason'])) {
            $this->exceptionReason = $snapshot['exception_reason'];
        }
    }

    public function syncStatus(string $status): void
    {
        if (OrderStatus::exists($status)) {
            $this->currentStatus = $status;
        }
    }
}
