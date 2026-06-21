<?php

namespace Dealer\Wallet\Exception;

class WalletPermissionException extends WalletException
{
    public static function forDealerMismatch(int $requestDealerId, int $currentDealerId): self
    {
        $e = new self(
            "【权限不足】经销商数据隔离：当前经销商ID【{$currentDealerId}】无权访问经销商【{$requestDealerId}】的钱包。" .
            "如需跨经销商操作，请使用管理员账号或申请数据权限授权。"
        );
        $e->setRetryInfo([
            'retryable' => false,
            'retry_entry' => [
                'operation_name' => '权限校验',
                'can_retry' => false,
                'retry_button_text' => '无法重试',
                'retry_hint' => '权限不足，请切换账号',
            ],
            'suggestions' => [
                "请使用经销商ID【{$currentDealerId}】相关的钱包进行操作",
                '联系管理员为您分配跨经销商数据访问权限',
                '切换到拥有相应权限的管理员账号后重试',
            ],
        ]);
        return $e;
    }

    public static function forAdminRequired(string $operationName): self
    {
        $e = new self(
            "【权限不足】操作【{$operationName}】需要管理员权限。" .
            "当前账号角色不具备管理员权限，请切换至管理员账号或联系管理员授权。"
        );
        $e->setRetryInfo([
            'retryable' => false,
            'retry_entry' => [
                'operation_name' => $operationName,
                'can_retry' => false,
                'retry_button_text' => '无法重试',
                'retry_hint' => '需要管理员权限',
            ],
            'suggestions' => [
                '请使用具有管理员权限的账号执行此操作',
                '联系超级管理员为您分配 wallet_admin 角色',
            ],
        ]);
        return $e;
    }

    public static function forScopeDenied(string $operationName, string $permission): self
    {
        $permLabel = class_exists('\PermissionService')
            ? \PermissionService::describePermission($permission)
            : $permission;
        $e = new self(
            "【权限不足】缺少操作权限：执行【{$operationName}】需要【{$permLabel}】（{$permission}）权限。" .
            "请联系管理员为您分配相应的操作权限。"
        );
        $e->setRetryInfo([
            'retryable' => false,
            'retry_entry' => [
                'operation_name' => $operationName,
                'can_retry' => false,
                'retry_button_text' => '无法重试',
                'retry_hint' => "缺少 {$permLabel} 权限",
            ],
            'suggestions' => [
                "请联系管理员为您分配【{$permLabel}】权限",
                '使用具有相应权限的账号进行操作',
            ],
        ]);
        return $e;
    }

    public static function forOperationDenied(string $operationName, string $permission, ?int $dealerId = null): self
    {
        $permLabel = class_exists('\PermissionService')
            ? \PermissionService::describePermission($permission)
            : $permission;
        $ctx = $dealerId ? "（经销商ID：{$dealerId}）" : '';
        $e = new self(
            "【权限不足】操作【{$operationName}】{$ctx}需要【{$permLabel}】（{$permission}）权限。" .
            "当前账号不具备该操作权限，请联系管理员授权。"
        );
        $e->setRetryInfo([
            'retryable' => false,
            'retry_entry' => [
                'operation_name' => $operationName,
                'can_retry' => false,
                'retry_button_text' => '无法重试',
                'retry_hint' => "缺少 {$permLabel} 权限",
            ],
            'suggestions' => [
                "请联系管理员为您分配【{$permLabel}】权限",
                '使用具有相应操作权限的账号重试',
            ],
        ]);
        return $e;
    }
}
