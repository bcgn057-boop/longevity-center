<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Managing staff and patient accounts. Clinic Admins manage everyone except
 * Super Admins and Compliance Officers, who oversee the admins; Super Admins
 * (through the Gate bypass) manage everyone. Nobody changes their own role
 * or deactivates themselves, and the last active Super Admin is protected.
 */
class UserPolicy
{
    /**
     * Roles a Clinic Admin may not assign or manage.
     *
     * @var array<int, UserRole>
     */
    private const PROTECTED_ROLES = [UserRole::SuperAdmin, UserRole::ComplianceOfficer];

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin);
    }

    public function update(User $user, User $account): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin) && ! $this->isProtected($account);
    }

    /**
     * Whether the user may give someone this role.
     */
    public function assignRole(User $user, UserRole $role): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin) && ! in_array($role, self::PROTECTED_ROLES, true);
    }

    public function deactivate(User $user, User $account): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin) && ! $this->isProtected($account) && $user->isNot($account);
    }

    private function isProtected(User $account): bool
    {
        return $account->role !== null && in_array($account->role->slug, self::PROTECTED_ROLES, true);
    }
}
