<?php

namespace Dealer\Wallet\Enum;

class TransactionType
{
    public const RECHARGE = 1;
    public const WITHDRAW = 2;
    public const CONSUME = 3;
    public const REFUND = 4;
    public const FREEZE = 5;
    public const UNFREEZE = 6;
    public const DEDUCT_FROZEN = 7;

    public static function getName(int $type): string
    {
        return match ($type) {
            self::RECHARGE => '充值',
            self::WITHDRAW => '提现',
            self::CONSUME => '消费',
            self::REFUND => '退款',
            self::FREEZE => '冻结',
            self::UNFREEZE => '解冻',
            self::DEDUCT_FROZEN => '冻结扣除',
            default => '未知类型',
        };
    }

    public static function getDirection(int $type): string
    {
        return match ($type) {
            self::RECHARGE, self::REFUND, self::UNFREEZE => 'in',
            self::WITHDRAW, self::CONSUME, self::FREEZE, self::DEDUCT_FROZEN => 'out',
            default => 'unknown',
        };
    }
}
