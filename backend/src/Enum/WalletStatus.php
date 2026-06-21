<?php

namespace Dealer\Wallet\Enum;

class WalletStatus
{
    public const NORMAL = 1;
    public const PARTIALLY_FROZEN = 2;
    public const FULLY_FROZEN = 3;

    public static function getName(int $status): string
    {
        return match ($status) {
            self::NORMAL => '正常',
            self::PARTIALLY_FROZEN => '部分冻结',
            self::FULLY_FROZEN => '全额冻结',
            default => '未知状态',
        };
    }

    public static function getColor(int $status): string
    {
        return match ($status) {
            self::NORMAL => 'green',
            self::PARTIALLY_FROZEN => 'orange',
            self::FULLY_FROZEN => 'red',
            default => 'gray',
        };
    }

    public static function isValid(int $status): bool
    {
        return in_array($status, [self::NORMAL, self::PARTIALLY_FROZEN, self::FULLY_FROZEN], true);
    }
}
