<?php

namespace Order\Enums;

class OrderStatus
{
    const PENDING = 'pending';
    const PAID = 'paid';
    const SHIPPED = 'shipped';
    const DELIVERED = 'delivered';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
    const REFUNDING = 'refunding';
    const REFUNDED = 'refunded';
    const EXCEPTION = 'exception';

    const ALL = [
        self::PENDING,
        self::PAID,
        self::SHIPPED,
        self::DELIVERED,
        self::COMPLETED,
        self::CANCELLED,
        self::REFUNDING,
        self::REFUNDED,
        self::EXCEPTION,
    ];

    const LABELS = [
        self::PENDING => '待支付',
        self::PAID => '已支付',
        self::SHIPPED => '已发货',
        self::DELIVERED => '已送达',
        self::COMPLETED => '已完成',
        self::CANCELLED => '已取消',
        self::REFUNDING => '退款中',
        self::REFUNDED => '已退款',
        self::EXCEPTION => '异常',
    ];

    const COLORS = [
        self::PENDING => '#faad14',
        self::PAID => '#1890ff',
        self::SHIPPED => '#722ed1',
        self::DELIVERED => '#13c2c2',
        self::COMPLETED => '#52c41a',
        self::CANCELLED => '#8c8c8c',
        self::REFUNDING => '#eb2f96',
        self::REFUNDED => '#f5222d',
        self::EXCEPTION => '#ff4d4f',
    ];

    public static function exists(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function getColor(string $status): string
    {
        return self::COLORS[$status] ?? '#000000';
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::COMPLETED, self::CANCELLED, self::REFUNDED], true);
    }

    public static function isRefundable(string $status): bool
    {
        return in_array($status, [self::PAID, self::SHIPPED, self::DELIVERED], true);
    }
}
