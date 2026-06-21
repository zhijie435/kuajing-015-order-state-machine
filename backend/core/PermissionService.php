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

    private array $operatorContext = [
        'operator_id' => null,
        'dealer_id' => null,
        'roles' => [],
        'permissions' => [],
        'scoped_dealer_ids' => null,
    ];

    public function setOperatorContext(array $context): void
    {
        $this->operatorContext = array_merge($this->operatorContext, $context);
    }

    public function getOperatorContext(): array
    {
        return $this->operatorContext;
    }

    public function getCurrentDealerId(): ?int
    {
        return $this->operatorContext['dealer_id'] ?? null;
    }

    public function isAdmin(): bool
    {
        $roles = $this->operatorContext['roles'] ?? [];
        return in_array(self::ROLE_SUPER_ADMIN, $roles, true)
            || in_array(self::ROLE_WALLET_ADMIN, $roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->operatorContext['permissions'] ?? [];
        if (in_array('*', $perms, true)) {
            return true;
        }
        return in_array($permission, $perms, true);
    }

    public function canViewWallet(int $dealerId): bool
    {
        if ($this->isAdmin()) {
            if ($this->hasPermission(self::PERM_WALLET_VIEW_ALL)) {
                return true;
            }
        }

        $currentDealerId = $this->getCurrentDealerId();
        if ($currentDealerId !== null && $currentDealerId === $dealerId) {
            return $this->hasPermission(self::PERM_WALLET_VIEW_OWN);
        }

        $scoped = $this->operatorContext['scoped_dealer_ids'] ?? null;
        if ($scoped === '*') {
            return true;
        }
        if (is_array($scoped) && in_array($dealerId, $scoped, true)) {
            return true;
        }

        return false;
    }

    public function canOperateWallet(int $dealerId): bool
    {
        return $this->canViewWallet($dealerId);
    }
}
