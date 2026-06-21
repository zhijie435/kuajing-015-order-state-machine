<?php

namespace Order\Enums;

class ExceptionLevel
{
    const NONE = 0;
    const LOW = 1;
    const MEDIUM = 2;
    const HIGH = 3;
    const CRITICAL = 4;

    const ALL = [
        self::NONE,
        self::LOW,
        self::MEDIUM,
        self::HIGH,
        self::CRITICAL,
    ];

    const LABELS = [
        self::NONE => '无异常',
        self::LOW => '低',
        self::MEDIUM => '中',
        self::HIGH => '高',
        self::CRITICAL => '严重',
    ];

    const COLORS = [
        self::NONE => '#52c41a',
        self::LOW => '#faad14',
        self::MEDIUM => '#fa8c16',
        self::HIGH => '#f5222d',
        self::CRITICAL => '#722ed1',
    ];

    const REQUIRE_AUDIT = [
        self::MEDIUM,
        self::HIGH,
        self::CRITICAL,
    ];

    public static function exists(int $level): bool
    {
        return in_array($level, self::ALL, true);
    }

    public static function getLabel(int $level): string
    {
        return self::LABELS[$level] ?? (string) $level;
    }

    public static function getColor(int $level): string
    {
        return self::COLORS[$level] ?? '#000000';
    }

    public static function requiresAudit(int $level): bool
    {
        return in_array($level, self::REQUIRE_AUDIT, true);
    }
}
