<?php

namespace Order\Exceptions;

use Exception;

class StateMachineException extends Exception
{
    const CODE_INVALID_TRANSITION = 1001;
    const CODE_INVALID_STATUS = 1002;
    const CODE_INVALID_EVENT = 1003;
    const CODE_TERMINAL_STATUS = 1004;
    const CODE_ROLLBACK_DISABLED = 1005;
    const CODE_ROLLBACK_DEPTH_EXCEEDED = 1006;
    const CODE_NO_ROLLBACK_HISTORY = 1007;
    const CODE_EXCEPTION_STATE = 1008;
    const CODE_VALIDATION_FAILED = 1009;
    const CODE_TRANSACTION_FAILED = 1010;
    const CODE_ROLLBACK_AUDIT_REQUIRED = 1011;
    const CODE_PERMISSION_DENIED = 1012;

    public static function invalidTransition(string $from, string $event, ?string $suggestion = null): self
    {
        $message = sprintf('当前状态 "%s" 不支持 "%s" 操作', $from, $event);
        if ($suggestion !== null) {
            $message .= '，' . $suggestion;
        }
        return new self($message, self::CODE_INVALID_TRANSITION);
    }

    public static function invalidStatus(string $status): self
    {
        return new self(
            sprintf('无效的订单状态: "%s"', $status),
            self::CODE_INVALID_STATUS
        );
    }

    public static function invalidEvent(string $event): self
    {
        return new self(
            sprintf('无效的操作: "%s"', $event),
            self::CODE_INVALID_EVENT
        );
    }

    public static function terminalStatus(string $status): self
    {
        return new self(
            sprintf('终态订单 "%s" 无法执行状态变更操作', $status),
            self::CODE_TERMINAL_STATUS
        );
    }

    public static function rollbackDisabled(): self
    {
        return new self(
            '系统未启用回滚功能',
            self::CODE_ROLLBACK_DISABLED
        );
    }

    public static function rollbackDepthExceeded(int $maxDepth): self
    {
        return new self(
            sprintf('已达到最大回滚深度 (%d) 限制', $maxDepth),
            self::CODE_ROLLBACK_DEPTH_EXCEEDED
        );
    }

    public static function noRollbackHistory(): self
    {
        return new self(
            '没有可回滚的历史记录',
            self::CODE_NO_ROLLBACK_HISTORY
        );
    }

    public static function exceptionState(string $action): self
    {
        return new self(
            sprintf('订单处于异常状态，无法执行 "%s" 操作', $action),
            self::CODE_EXCEPTION_STATE
        );
    }

    public static function validationFailed(string $message): self
    {
        return new self($message, self::CODE_VALIDATION_FAILED);
    }

    public static function transactionFailed(string $message): self
    {
        return new self(
            sprintf('事务执行失败: %s', $message),
            self::CODE_TRANSACTION_FAILED
        );
    }

    public static function rollbackAuditRequired(string $reason): self
    {
        return new self(
            sprintf('该订单受回滚保护，需要审核通过后才能执行回滚操作，%s，请提交回滚审核申请或联系管理员', $reason),
            self::CODE_ROLLBACK_AUDIT_REQUIRED
        );
    }

    public static function permissionDenied(string $permission, string $description = ''): self
    {
        $message = sprintf('权限不足，缺少 "%s" 权限', $permission);
        if ($description !== '' && $description !== $permission) {
            $message .= sprintf('（%s）', $description);
        }
        return new self($message . '，请联系管理员开通权限', self::CODE_PERMISSION_DENIED);
    }
}
