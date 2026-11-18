<?php

namespace App\Finance;

use App\Platform\Memberships\Membership;

/**
 * Papéis e permissões do Finanças (config/finance.php). Única regra de acesso do produto: não repetir em tela.
 */
class Access
{
    public static function allows(?Membership $membership, string $permission): bool
    {
        if (! $membership?->isActive()) {
            return false;
        }

        $permissions = config("finance.roles.{$membership->role}.permissions", []);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** @return array<string, string> papel => nome */
    public static function roles(): array
    {
        return array_map(fn (array $role) => $role['label'], config('finance.roles'));
    }

    public static function label(string $role): string
    {
        return config("finance.roles.{$role}.label", $role);
    }
}
