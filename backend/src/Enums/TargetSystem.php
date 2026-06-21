<?php

namespace Order\Enums;

class TargetSystem
{
    const ERP = 'erp';
    const WMS = 'wms';
    const CRM = 'crm';
    const FINANCE = 'finance';
    const OTHER = 'other';

    const ALL = [
        self::ERP,
        self::WMS,
        self::CRM,
        self::FINANCE,
        self::OTHER,
    ];

    const LABELS = [
        self::ERP => 'ERP系统',
        self::WMS => 'WMS仓储系统',
        self::CRM => 'CRM客户系统',
        self::FINANCE => '财务系统',
        self::OTHER => '其他系统',
    ];

    public static function exists(string $system): bool
    {
        return in_array($system, self::ALL, true);
    }

    public static function getLabel(string $system): string
    {
        return self::LABELS[$system] ?? $system;
    }
}
