<?php

namespace Order\Enums;

class ExceptionType
{
    const PAYMENT_ABNORMAL = 'payment_abnormal';
    const SHIPPING_ABNORMAL = 'shipping_abnormal';
    const SYSTEM_ABNORMAL = 'system_abnormal';
    const MANUAL_HANDLING = 'manual_handling';
    const INVENTORY_ABNORMAL = 'inventory_abnormal';
    const REFUND_ABNORMAL = 'refund_abnormal';
    const OTHER = 'other';

    const ALL = [
        self::PAYMENT_ABNORMAL,
        self::SHIPPING_ABNORMAL,
        self::SYSTEM_ABNORMAL,
        self::MANUAL_HANDLING,
        self::INVENTORY_ABNORMAL,
        self::REFUND_ABNORMAL,
        self::OTHER,
    ];

    const LABELS = [
        self::PAYMENT_ABNORMAL => '支付异常',
        self::SHIPPING_ABNORMAL => '物流异常',
        self::SYSTEM_ABNORMAL => '系统异常',
        self::MANUAL_HANDLING => '需人工处理',
        self::INVENTORY_ABNORMAL => '库存异常',
        self::REFUND_ABNORMAL => '退款异常',
        self::OTHER => '其他异常',
    ];

    const DESCRIPTIONS = [
        self::PAYMENT_ABNORMAL => '支付过程出现异常，如重复支付、金额不符、支付超时等',
        self::SHIPPING_ABNORMAL => '物流配送异常，如地址错误、包裹丢失、配送延迟等',
        self::SYSTEM_ABNORMAL => '系统处理异常，如接口调用失败、数据不一致等',
        self::MANUAL_HANDLING => '需要人工介入处理的特殊情况',
        self::INVENTORY_ABNORMAL => '库存异常，如超卖、库存不足等',
        self::REFUND_ABNORMAL => '退款流程异常，如退款失败、金额错误等',
        self::OTHER => '其他未分类的异常情况',
    ];

    public static function exists(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function getLabel(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    public static function getDescription(string $type): string
    {
        return self::DESCRIPTIONS[$type] ?? '';
    }
}
