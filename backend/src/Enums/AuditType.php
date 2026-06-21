<?php

namespace Order\Enums;

class AuditType
{
    const STATUS_CHANGE = 'status_change';
    const ROLLBACK = 'rollback';
    const EXCEPTION_RESOLVE = 'exception_resolve';
    const WRITEBACK = 'writeback';
    const EXCEPTION_MARK = 'exception_mark';

    const ALL = [
        self::STATUS_CHANGE,
        self::ROLLBACK,
        self::EXCEPTION_RESOLVE,
        self::WRITEBACK,
        self::EXCEPTION_MARK,
    ];

    const LABELS = [
        self::STATUS_CHANGE => '状态变更审核',
        self::ROLLBACK => '回滚审核',
        self::EXCEPTION_RESOLVE => '异常解决审核',
        self::WRITEBACK => '数据回写审核',
        self::EXCEPTION_MARK => '标记异常审核',
    ];

    public static function exists(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function getLabel(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
