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

    public static function invalidTransition(string $from, string $event): self
    {
        return new self(
            sprintf('Invalid state transition: cannot apply event "%s" from status "%s"', $event, $from),
            self::CODE_INVALID_TRANSITION
        );
    }

    public static function invalidStatus(string $status): self
    {
        return new self(
            sprintf('Invalid order status: "%s"', $status),
            self::CODE_INVALID_STATUS
        );
    }

    public static function invalidEvent(string $event): self
    {
        return new self(
            sprintf('Invalid event: "%s"', $event),
            self::CODE_INVALID_EVENT
        );
    }

    public static function terminalStatus(string $status): self
    {
        return new self(
            sprintf('Cannot transition from terminal status: "%s"', $status),
            self::CODE_TERMINAL_STATUS
        );
    }

    public static function rollbackDisabled(): self
    {
        return new self(
            'Rollback is disabled in configuration',
            self::CODE_ROLLBACK_DISABLED
        );
    }

    public static function rollbackDepthExceeded(int $maxDepth): self
    {
        return new self(
            sprintf('Maximum rollback depth (%d) exceeded', $maxDepth),
            self::CODE_ROLLBACK_DEPTH_EXCEEDED
        );
    }

    public static function noRollbackHistory(): self
    {
        return new self(
            'No rollback history available',
            self::CODE_NO_ROLLBACK_HISTORY
        );
    }

    public static function exceptionState(string $action): self
    {
        return new self(
            sprintf('Cannot perform "%s" while order is in exception state', $action),
            self::CODE_EXCEPTION_STATE
        );
    }

    public static function validationFailed(string $message): self
    {
        return new self(
            sprintf('Validation failed: %s', $message),
            self::CODE_VALIDATION_FAILED
        );
    }

    public static function transactionFailed(string $message): self
    {
        return new self(
            sprintf('Transaction failed: %s', $message),
            self::CODE_TRANSACTION_FAILED
        );
    }
}
