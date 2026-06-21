<?php

namespace Order\Enums;

class AuditStatus
{
    const NONE = 'none';
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const CANCELLED = 'cancelled';

    const ALL = [
        self::NONE,
        self::PENDING,
        self::APPROVED,
        self::REJECTED,
        self::CANCELLED,
    ];

    const LABELS = [
        self::NONE => '无需审核',
        self::PENDING => '待审核',
        self::APPROVED => '审核通过',
        self::REJECTED => '审核拒绝',
        self::CANCELLED => '已取消',
    ];

    const COLORS = [
        self::NONE => '#8c8c8c',
        self::PENDING => '#faad14',
        self::APPROVED => '#52c41a',
        self::REJECTED => '#f5222d',
        self::CANCELLED => '#8c8c8c',
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
}
