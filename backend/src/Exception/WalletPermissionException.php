<?php

namespace Dealer\Wallet\Exception;

class WalletPermissionException extends WalletException
{
    public static function forDealerMismatch(int $requestDealerId, int $currentDealerId): self
    {
        return new self(
            "权限校验失败：当前经销商ID【{$currentDealerId}】无权访问经销商【{$requestDealerId}】的钱包数据。" .
            "如需跨经销商查询，请使用管理员账号或申请数据权限授权。"
        );
    }

    public static function forAdminRequired(string $operationName): self
    {
        return new self(
            "权限校验失败：【{$operationName}】操作需要管理员权限。" .
            "当前账号无管理员权限，请联系管理员或切换至管理员账号。"
        );
    }

    public static function forScopeDenied(string $operationName, string $permission): self
    {
        return new self(
            "权限校验失败：当前账号缺少【{$permission}】权限，无法执行【{$operationName}】。" .
            "请联系管理员为您分配相应的数据操作权限。"
        );
    }
}
