<?php

namespace Order\Enums;

class WritebackType
{
    const STATUS_CREATE = 'status_create';
    const STATUS_UPDATE = 'status_update';
    const PAYMENT = 'payment';
    const REFUND = 'refund';
    const SHIPMENT = 'shipment';
    const INVENTORY_SYNC = 'inventory_sync';
    const FINANCE_SETTLE = 'finance_settle';

    const ALL = [
        self::STATUS_CREATE,
        self::STATUS_UPDATE,
        self::PAYMENT,
        self::REFUND,
        self::SHIPMENT,
        self::INVENTORY_SYNC,
        self::FINANCE_SETTLE,
    ];

    const LABELS = [
        self::STATUS_CREATE => '订单创建',
        self::STATUS_UPDATE => '状态更新',
        self::PAYMENT => '支付信息',
        self::REFUND => '退款信息',
        self::SHIPMENT => '发货信息',
        self::INVENTORY_SYNC => '库存同步',
        self::FINANCE_SETTLE => '财务结算',
    ];

    const TARGET_SYSTEMS = [
        self::STATUS_CREATE => ['erp'],
        self::STATUS_UPDATE => ['erp'],
        self::PAYMENT => ['finance', 'erp'],
        self::REFUND => ['finance', 'erp'],
        self::SHIPMENT => ['wms', 'erp'],
        self::INVENTORY_SYNC => ['wms', 'erp'],
        self::FINANCE_SETTLE => ['finance'],
    ];

    public static function exists(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function getLabel(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    public static function getTargetSystems(string $type): array
    {
        return self::TARGET_SYSTEMS[$type] ?? [];
    }
}
