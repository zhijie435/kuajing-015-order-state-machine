<?php

namespace Dealer\Wallet\Enum;

class FreezeStatus
{
    public const FROZEN = 1;
    public const PARTIALLY_UNFROZEN = 2;
    public const FULLY_UNFROZEN = 3;
    public const DEDUCTED = 4;
    public const EXPIRED = 5;

    public static function getName(int $status): string
    {
        return match ($status) {
            self::FROZEN => '冻结中',
            self::PARTIALLY_UNFROZEN => '部分解冻',
            self::FULLY_UNFROZEN => '已全额解冻',
            self::DEDUCTED => '已扣除',
            self::EXPIRED => '已过期',
            default => '未知状态',
        };
    }
}
