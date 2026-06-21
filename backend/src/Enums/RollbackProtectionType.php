<?php

namespace Order\Enums;

class RollbackProtectionType
{
    const AMOUNT_THRESHOLD = 'amount_threshold';
    const TIME_WINDOW = 'time_window';
    const TERMINAL_STATUS = 'terminal_status';
    const MANUAL_PROTECT = 'manual_protect';
    const AUDIT_REQUIRED = 'audit_required';

    const ALL = [
        self::AMOUNT_THRESHOLD,
        self::TIME_WINDOW,
        self::TERMINAL_STATUS,
        self::MANUAL_PROTECT,
        self::AUDIT_REQUIRED,
    ];

    const LABELS = [
        self::AMOUNT_THRESHOLD => '金额阈值保护',
        self::TIME_WINDOW => '时间窗口保护',
        self::TERMINAL_STATUS => '终态保护',
        self::MANUAL_PROTECT => '人工保护',
        self::AUDIT_REQUIRED => '需审核保护',
    ];

    const DESCRIPTIONS = [
        self::AMOUNT_THRESHOLD => '订单金额超过设定阈值时启用回滚保护',
        self::TIME_WINDOW => '在特定时间窗口内启用回滚保护',
        self::TERMINAL_STATUS => '订单到达终态时启用回滚保护',
        self::MANUAL_PROTECT => '人工手动设置的回滚保护',
        self::AUDIT_REQUIRED => '需要审核通过才能回滚',
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
