<?php

class PermissionService
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_WALLET_ADMIN = 'wallet_admin';
    public const ROLE_DEALER = 'dealer';
    public const ROLE_AUDITOR = 'auditor';

    public const PERM_WALLET_VIEW_OWN = 'wallet:view:own';
    public const PERM_WALLET_VIEW_ALL = 'wallet:view:all';
    public const PERM_WALLET_TRANSACTIONS_ALL = 'wallet:transactions:all';
    public const PERM_WALLET_FREEZE_ALL = 'wallet:freeze:all';
    public const PERM_WALLET_RECONCILE = 'wallet:reconcile';
    public const PERM_WALLET_FIX = 'wallet:fix';
    public const PERM_WALLET_EXPORT = 'wallet:export';
    public const PERM_WALLET_RECHARGE = 'wallet:recharge';
    public const PERM_WALLET_WITHDRAW = 'wallet:withdraw';
    public const PERM_WALLET_CONSUME = 'wallet:consume';
    public const PERM_WALLET_REFUND = 'wallet:refund';
    public const PERM_WALLET_UNFREEZE = 'wallet:unfreeze';
    public const PERM_WALLET_DEDUCT_FROZEN = 'wallet:deduct_frozen';

    public const PERM_ORDER_VIEW_OWN = 'order:view:own';
    public const PERM_ORDER_VIEW_ALL = 'order:view:all';
    public const PERM_ORDER_CREATE = 'order:create';
    public const PERM_ORDER_PAY = 'order:pay';
    public const PERM_ORDER_SHIP = 'order:ship';
    public const PERM_ORDER_CONFIRM_RECEIPT = 'order:confirm_receipt';
    public const PERM_ORDER_COMPLETE = 'order:complete';
    public const PERM_ORDER_CANCEL = 'order:cancel';
    public const PERM_ORDER_APPLY_REFUND = 'order:apply_refund';
    public const PERM_ORDER_APPROVE_REFUND = 'order:approve_refund';
    public const PERM_ORDER_REJECT_REFUND = 'order:reject_refund';
    public const PERM_ORDER_MARK_EXCEPTION = 'order:mark_exception';
    public const PERM_ORDER_RESOLVE_EXCEPTION = 'order:resolve_exception';
    public const PERM_ORDER_ROLLBACK = 'order:rollback';
    public const PERM_ORDER_ROLLBACK_AUDIT = 'order:rollback_audit';
    public const PERM_ORDER_SET_PROTECTION = 'order:set_protection';
    public const PERM_ORDER_REMOVE_PROTECTION = 'order:remove_protection';
    public const PERM_ORDER_VIEW_LOGS = 'order:view_logs';
    public const PERM_ORDER_FORCE_TRANSITION = 'order:force_transition';
    public const PERM_ORDER_RETRY_WRITEBACK = 'order:retry_writeback';
    public const PERM_ORDER_VIEW_STATISTICS = 'order:view_statistics';

    public const EVENT_PERMISSION_MAP = [
        'pay' => self::PERM_ORDER_PAY,
        'ship' => self::PERM_ORDER_SHIP,
        'confirm_receipt' => self::PERM_ORDER_CONFIRM_RECEIPT,
        'complete' => self::PERM_ORDER_COMPLETE,
        'cancel' => self::PERM_ORDER_CANCEL,
        'apply_refund' => self::PERM_ORDER_APPLY_REFUND,
        'approve_refund' => self::PERM_ORDER_APPROVE_REFUND,
        'reject_refund' => self::PERM_ORDER_REJECT_REFUND,
        'mark_exception' => self::PERM_ORDER_MARK_EXCEPTION,
        'resolve_exception' => self::PERM_ORDER_RESOLVE_EXCEPTION,
        'rollback' => self::PERM_ORDER_ROLLBACK,
    ];

    private static array $operatorContext = [
        'operator_id' => null,
        'dealer_id' => null,
        'roles' => [],
        'permissions' => [],
        'scoped_dealer_ids' => null,
    ];

    public static function setOperatorContext(?string $operatorId = null, ?string $role = null, ?int $dealerId = null): void
    {
        if ($operatorId !== null) {
            self::$operatorContext['operator_id'] = $operatorId;
        }
        if ($role !== null) {
            $perms = self::getPermissionsByRole($role);
            self::$operatorContext['roles'] = [$role];
            self::$operatorContext['permissions'] = $perms;
            if ($dealerId === null) {
                self::$operatorContext['dealer_id'] = null;
            }
        }
        if ($dealerId !== null) {
            self::$operatorContext['dealer_id'] = $dealerId;
        }
    }

    public static function setRawContext(array $context): void
    {
        self::$operatorContext = array_merge(self::$operatorContext, $context);
    }

    public static function getPermissionsByRole(string $role): array
    {
        $map = [
            self::ROLE_SUPER_ADMIN => ['*'],
            self::ROLE_WALLET_ADMIN => [
                self::PERM_WALLET_VIEW_ALL,
                self::PERM_WALLET_TRANSACTIONS_ALL,
                self::PERM_WALLET_FREEZE_ALL,
                self::PERM_WALLET_RECHARGE,
                self::PERM_WALLET_WITHDRAW,
                self::PERM_WALLET_CONSUME,
                self::PERM_WALLET_REFUND,
                self::PERM_WALLET_UNFREEZE,
                self::PERM_WALLET_DEDUCT_FROZEN,
                self::PERM_WALLET_RECONCILE,
                self::PERM_WALLET_FIX,
                self::PERM_WALLET_EXPORT,
                self::PERM_ORDER_VIEW_ALL,
                self::PERM_ORDER_PAY,
                self::PERM_ORDER_SHIP,
                self::PERM_ORDER_CONFIRM_RECEIPT,
                self::PERM_ORDER_COMPLETE,
                self::PERM_ORDER_CANCEL,
                self::PERM_ORDER_APPLY_REFUND,
                self::PERM_ORDER_MARK_EXCEPTION,
                self::PERM_ORDER_RESOLVE_EXCEPTION,
                self::PERM_ORDER_ROLLBACK,
                self::PERM_ORDER_ROLLBACK_AUDIT,
                self::PERM_ORDER_SET_PROTECTION,
                self::PERM_ORDER_REMOVE_PROTECTION,
                self::PERM_ORDER_VIEW_LOGS,
                self::PERM_ORDER_RETRY_WRITEBACK,
                self::PERM_ORDER_VIEW_STATISTICS,
            ],
            self::ROLE_DEALER => [
                self::PERM_WALLET_VIEW_OWN,
                self::PERM_ORDER_VIEW_OWN,
                self::PERM_ORDER_CREATE,
                self::PERM_ORDER_PAY,
                self::PERM_ORDER_CONFIRM_RECEIPT,
                self::PERM_ORDER_CANCEL,
                self::PERM_ORDER_APPLY_REFUND,
                self::PERM_ORDER_VIEW_LOGS,
            ],
            self::ROLE_AUDITOR => [
                self::PERM_WALLET_VIEW_ALL,
                self::PERM_WALLET_TRANSACTIONS_ALL,
                self::PERM_WALLET_RECONCILE,
                self::PERM_WALLET_EXPORT,
                self::PERM_ORDER_VIEW_ALL,
                self::PERM_ORDER_APPROVE_REFUND,
                self::PERM_ORDER_REJECT_REFUND,
                self::PERM_ORDER_RESOLVE_EXCEPTION,
                self::PERM_ORDER_ROLLBACK_AUDIT,
                self::PERM_ORDER_VIEW_LOGS,
                self::PERM_ORDER_VIEW_STATISTICS,
            ],
        ];
        return $map[$role] ?? [];
    }

    public static function getOperatorContext(): array
    {
        return self::$operatorContext;
    }

    public static function getCurrentDealerId(): ?int
    {
        return self::$operatorContext['dealer_id'] ?? null;
    }

    public static function getCurrentRoles(): array
    {
        return self::$operatorContext['roles'] ?? [];
    }

    public static function isAdmin(): bool
    {
        $roles = self::$operatorContext['roles'] ?? [];
        return in_array(self::ROLE_SUPER_ADMIN, $roles, true)
            || in_array(self::ROLE_WALLET_ADMIN, $roles, true);
    }

    public static function hasPermission(string $permission): bool
    {
        $perms = self::$operatorContext['permissions'] ?? [];
        if (in_array('*', $perms, true)) {
            return true;
        }
        return in_array($permission, $perms, true);
    }

    public static function canViewWallet(int $dealerId): bool
    {
        if (self::hasPermission(self::PERM_WALLET_VIEW_ALL)) {
            return true;
        }

        $currentDealerId = self::getCurrentDealerId();
        if ($currentDealerId !== null && $currentDealerId === $dealerId) {
            return self::hasPermission(self::PERM_WALLET_VIEW_OWN);
        }

        $scoped = self::$operatorContext['scoped_dealer_ids'] ?? null;
        if ($scoped === '*') {
            return true;
        }
        if (is_array($scoped) && in_array($dealerId, $scoped, true)) {
            return true;
        }

        return false;
    }

    public static function canOperateWallet(int $dealerId, string $operationPermission = ''): bool
    {
        if ($dealerId > 0 && !self::canViewWallet($dealerId)) {
            return false;
        }
        if ($operationPermission !== '' && !self::hasPermission($operationPermission)) {
            if (!self::isAdmin()) {
                return false;
            }
        }
        return true;
    }

    public static function describePermission(string $permission): string
    {
        $map = [
            self::PERM_WALLET_VIEW_OWN => '查看本经销商钱包',
            self::PERM_WALLET_VIEW_ALL => '查看所有钱包',
            self::PERM_WALLET_TRANSACTIONS_ALL => '查看所有交易记录',
            self::PERM_WALLET_FREEZE_ALL => '冻结所有钱包资金',
            self::PERM_WALLET_RECHARGE => '钱包充值',
            self::PERM_WALLET_WITHDRAW => '余额提现',
            self::PERM_WALLET_CONSUME => '余额消费',
            self::PERM_WALLET_REFUND => '消费退款',
            self::PERM_WALLET_UNFREEZE => '资金解冻',
            self::PERM_WALLET_DEDUCT_FROZEN => '冻结资金扣除',
            self::PERM_WALLET_RECONCILE => '财务对账',
            self::PERM_WALLET_FIX => '修复钱包异常数据',
            self::PERM_WALLET_EXPORT => '导出数据',
            self::PERM_ORDER_VIEW_OWN => '查看本经销商订单',
            self::PERM_ORDER_VIEW_ALL => '查看所有订单',
            self::PERM_ORDER_CREATE => '创建订单',
            self::PERM_ORDER_PAY => '订单支付',
            self::PERM_ORDER_SHIP => '订单发货',
            self::PERM_ORDER_CONFIRM_RECEIPT => '确认收货',
            self::PERM_ORDER_COMPLETE => '完成订单',
            self::PERM_ORDER_CANCEL => '取消订单',
            self::PERM_ORDER_APPLY_REFUND => '申请退款',
            self::PERM_ORDER_APPROVE_REFUND => '同意退款',
            self::PERM_ORDER_REJECT_REFUND => '拒绝退款',
            self::PERM_ORDER_MARK_EXCEPTION => '标记异常',
            self::PERM_ORDER_RESOLVE_EXCEPTION => '解决异常',
            self::PERM_ORDER_ROLLBACK => '回滚状态',
            self::PERM_ORDER_ROLLBACK_AUDIT => '审核回滚申请',
            self::PERM_ORDER_SET_PROTECTION => '设置回滚保护',
            self::PERM_ORDER_REMOVE_PROTECTION => '解除回滚保护',
            self::PERM_ORDER_VIEW_LOGS => '查看操作日志',
            self::PERM_ORDER_FORCE_TRANSITION => '强制状态变更',
            self::PERM_ORDER_RETRY_WRITEBACK => '重试回写',
            self::PERM_ORDER_VIEW_STATISTICS => '查看统计数据',
        ];
        return $map[$permission] ?? $permission;
    }

    public static function canViewOrder(int $userId, int $orderOwnerUserId): bool
    {
        if (self::hasPermission(self::PERM_ORDER_VIEW_ALL)) {
            return true;
        }
        if ($userId > 0 && $userId === $orderOwnerUserId) {
            return self::hasPermission(self::PERM_ORDER_VIEW_OWN);
        }
        $scoped = self::$operatorContext['scoped_dealer_ids'] ?? null;
        if ($scoped === '*') {
            return true;
        }
        return false;
    }

    public static function canExecuteEvent(string $event, int $orderOwnerUserId = 0): bool
    {
        $permission = self::EVENT_PERMISSION_MAP[$event] ?? null;
        if ($permission === null) {
            return false;
        }
        if (!self::hasPermission($permission)) {
            return false;
        }
        if (!self::hasPermission(self::PERM_ORDER_VIEW_ALL) && $orderOwnerUserId > 0) {
            $currentUserId = self::$operatorContext['dealer_id'] ?? null;
            if ($currentUserId !== null && $currentUserId !== $orderOwnerUserId) {
                return false;
            }
        }
        return true;
    }

    public static function getMissingPermissionForEvent(string $event): ?string
    {
        $permission = self::EVENT_PERMISSION_MAP[$event] ?? null;
        if ($permission === null) {
            return null;
        }
        if (!self::hasPermission($permission)) {
            return $permission;
        }
        return null;
    }

    public static function getOperatorUserId(): ?int
    {
        return self::$operatorContext['dealer_id'] ?? null;
    }
}
