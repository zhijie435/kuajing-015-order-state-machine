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
        }
        if ($dealerId !== null) {
            self::$operatorContext['dealer_id'] = $dealerId;
        }
    }

    private static function getPermissionsByRole(string $role): array
    {
        $map = [
            self::ROLE_SUPER_ADMIN => ['*'],
            self::ROLE_WALLET_ADMIN => [
                self::PERM_WALLET_VIEW_ALL,
                self::PERM_WALLET_TRANSACTIONS_ALL,
                self::PERM_WALLET_FREEZE_ALL,
                self::PERM_WALLET_RECONCILE,
                self::PERM_WALLET_FIX,
                self::PERM_WALLET_EXPORT,
            ],
            self::ROLE_DEALER => [
                self::PERM_WALLET_VIEW_OWN,
            ],
            self::ROLE_AUDITOR => [
                self::PERM_WALLET_VIEW_ALL,
                self::PERM_WALLET_TRANSACTIONS_ALL,
                self::PERM_WALLET_RECONCILE,
                self::PERM_WALLET_EXPORT,
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
        if (self::isAdmin()) {
            if (self::hasPermission(self::PERM_WALLET_VIEW_ALL)) {
                return true;
            }
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

    public static function canOperateWallet(int $dealerId): bool
    {
        return self::canViewWallet($dealerId);
    }
}
