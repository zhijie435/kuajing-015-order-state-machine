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

    public static function fromStateMachineException(StateMachineException $e): array
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
        ];

        $errorCode = $errorCodeMap[$e->getCode()] ?? 'UNKNOWN_ERROR';

        return [
            'code' => $e->getCode(),
            'error_code' => $errorCode,
            'message' => $e->getMessage(),
            'timestamp' => time(),
        ];
    }
}
