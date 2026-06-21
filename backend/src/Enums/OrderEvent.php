<?php

namespace Order\Enums;

class OrderEvent
{
    const PAY = 'pay';
    const SHIP = 'ship';
    const CONFIRM_RECEIPT = 'confirm_receipt';
    const COMPLETE = 'complete';
    const CANCEL = 'cancel';
    const APPLY_REFUND = 'apply_refund';
    const APPROVE_REFUND = 'approve_refund';
    const REJECT_REFUND = 'reject_refund';
    const MARK_EXCEPTION = 'mark_exception';
    const RESOLVE_EXCEPTION = 'resolve_exception';
    const ROLLBACK = 'rollback';

    const ALL = [
        self::PAY,
        self::SHIP,
        self::CONFIRM_RECEIPT,
        self::COMPLETE,
        self::CANCEL,
        self::APPLY_REFUND,
        self::APPROVE_REFUND,
        self::REJECT_REFUND,
        self::MARK_EXCEPTION,
        self::RESOLVE_EXCEPTION,
        self::ROLLBACK,
    ];

    const LABELS = [
        self::PAY => '支付',
        self::SHIP => '发货',
        self::CONFIRM_RECEIPT => '确认收货',
        self::COMPLETE => '完成订单',
        self::CANCEL => '取消订单',
        self::APPLY_REFUND => '申请退款',
        self::APPROVE_REFUND => '同意退款',
        self::REJECT_REFUND => '拒绝退款',
        self::MARK_EXCEPTION => '标记异常',
        self::RESOLVE_EXCEPTION => '解决异常',
        self::ROLLBACK => '回滚状态',
    ];

    public static function exists(string $event): bool
    {
        return in_array($event, self::ALL, true);
    }

    public static function getLabel(string $event): string
    {
        return self::LABELS[$event] ?? $event;
    }
}
