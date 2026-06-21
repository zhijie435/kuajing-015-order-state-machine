<?php

namespace Order\Controllers;

use Order\Services\OrderService;
use Order\Exceptions\StateMachineException;

class ApiResponse
{
    public static function success($data = null, string $message = 'success', int $code = 0): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ];
    }

    public static function error(string $message, int $code = 1, $errors = null, $data = null): array
    {
        $response = [
            'code' => $code,
            'message' => $message,
            'timestamp' => time(),
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $response;
    }

    public static function fromStateMachineException(StateMachineException $e, array $context = []): array
    {
        $errorCodeMap = [
            StateMachineException::CODE_INVALID_TRANSITION => 'INVALID_TRANSITION',
            StateMachineException::CODE_INVALID_STATUS => 'INVALID_STATUS',
            StateMachineException::CODE_INVALID_EVENT => 'INVALID_EVENT',
            StateMachineException::CODE_TERMINAL_STATUS => 'TERMINAL_STATUS',
            StateMachineException::CODE_ROLLBACK_DISABLED => 'ROLLBACK_DISABLED',
            StateMachineException::CODE_ROLLBACK_DEPTH_EXCEEDED => 'ROLLBACK_DEPTH_EXCEEDED',
            StateMachineException::CODE_NO_ROLLBACK_HISTORY => 'NO_ROLLBACK_HISTORY',
            StateMachineException::CODE_EXCEPTION_STATE => 'EXCEPTION_STATE',
            StateMachineException::CODE_VALIDATION_FAILED => 'VALIDATION_FAILED',
            StateMachineException::CODE_TRANSACTION_FAILED => 'TRANSACTION_FAILED',
            StateMachineException::CODE_ROLLBACK_AUDIT_REQUIRED => 'ROLLBACK_AUDIT_REQUIRED',
            StateMachineException::CODE_PERMISSION_DENIED => 'PERMISSION_DENIED',
        ];

        $errorCode = $errorCodeMap[$e->getCode()] ?? 'UNKNOWN_ERROR';

        $retryable = in_array($e->getCode(), [
            StateMachineException::CODE_TRANSACTION_FAILED,
            StateMachineException::CODE_VALIDATION_FAILED,
        ], true);

        $isRollbackAuditRequired = ($e->getCode() === StateMachineException::CODE_ROLLBACK_AUDIT_REQUIRED);

        $errors = [
            'error_code' => $errorCode,
            'retryable' => $retryable,
            'rollback_available' => !$isRollbackAuditRequired && ($context['can_rollback'] ?? false),
            'rollback_audit_required' => $isRollbackAuditRequired,
            'suggestion' => $context['suggestion'] ?? self::getSuggestionForErrorCode($errorCode, $context),
        ];

        $response = [
            'code' => $e->getCode(),
            'error_code' => $errorCode,
            'message' => $e->getMessage(),
            'errors' => $errors,
            'timestamp' => time(),
        ];

        if (!empty($context)) {
            $response['data'] = [
                'order_id' => $context['order_id'] ?? null,
                'current_status' => $context['current_status'] ?? null,
                'can_rollback' => !$isRollbackAuditRequired && ($context['can_rollback'] ?? false),
                'rollback_depth' => $context['rollback_depth'] ?? 0,
                'failed_event' => $context['failed_event'] ?? null,
                'rollback_audit_required' => $isRollbackAuditRequired,
            ];
        }

        return $response;
    }

    private static function getSuggestionForErrorCode(string $errorCode, array $context): string
    {
        $suggestions = [
            'TRANSACTION_FAILED' => '状态流转事务失败，请重试操作或回滚到上一状态',
            'VALIDATION_FAILED' => '操作校验未通过，请检查订单状态后重试',
            'INVALID_TRANSITION' => '当前状态不允许此操作，请确认订单状态',
            'EXCEPTION_STATE' => '订单处于异常状态，请先解决异常',
            'TERMINAL_STATUS' => '订单已处于终态，无法执行状态变更',
            'ROLLBACK_DISABLED' => '系统未启用回滚功能，请联系管理员',
            'ROLLBACK_DEPTH_EXCEEDED' => '已达到最大回滚深度，请联系管理员处理',
            'NO_ROLLBACK_HISTORY' => '没有可回滚的历史记录',
            'ROLLBACK_AUDIT_REQUIRED' => '该订单受回滚保护，请提交回滚审核申请或联系管理员审批',
            'PERMISSION_DENIED' => '您没有执行该操作的权限，请联系管理员开通相应权限',
        ];

        if (!empty($context['can_rollback'])) {
            return ($suggestions[$errorCode] ?? '操作失败') . '，可尝试回滚到上一状态';
        }

        return $suggestions[$errorCode] ?? '操作失败，请稍后重试';
    }
}
