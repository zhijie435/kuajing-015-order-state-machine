<?php

namespace Order\Enums;

class WritebackStatus
{
    const PENDING = 'pending';
    const SUCCESS = 'success';
    const FAILED = 'failed';
    const RETRYING = 'retrying';
    const PARTIAL = 'partial';
    const SKIPPED = 'skipped';

    const ALL = [
        self::PENDING,
        self::SUCCESS,
        self::FAILED,
        self::RETRYING,
        self::PARTIAL,
        self::SKIPPED,
    ];

    const LABELS = [
        self::PENDING => '待回写',
        self::SUCCESS => '回写成功',
        self::FAILED => '回写失败',
        self::RETRYING => '重试中',
        self::PARTIAL => '部分回写',
        self::SKIPPED => '已跳过',
    ];

    const COLORS = [
        self::PENDING => '#faad14',
        self::SUCCESS => '#52c41a',
        self::FAILED => '#f5222d',
        self::RETRYING => '#1890ff',
        self::PARTIAL => '#722ed1',
        self::SKIPPED => '#8c8c8c',
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

    public static function isFinal(string $status): bool
    {
        return in_array($status, [self::SUCCESS, self::FAILED, self::SKIPPED], true);
    }

    public static function canRetry(string $status): bool
    {
        return in_array($status, [self::PENDING, self::FAILED, self::RETRYING], true);
    }
}
