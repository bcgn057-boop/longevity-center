<?php

namespace App\Observers;

use App\Enums\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;

class UserObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Audit security-relevant changes to a user account.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('password')) {
            $this->audit->record(AuditEvent::PasswordChanged, auditable: $user);
        }

        if ($user->wasChanged('email')) {
            $this->audit->record(
                AuditEvent::EmailChanged,
                auditable: $user,
                oldValues: ['email' => $user->getOriginal('email')],
                newValues: ['email' => $user->email],
            );
        }

        if ($user->wasChanged('role_id')) {
            $this->audit->record(
                AuditEvent::RoleChanged,
                auditable: $user,
                oldValues: ['role' => $this->roleSlug($user->getOriginal('role_id'))],
                newValues: ['role' => $this->roleSlug($user->role_id)],
            );
        }
    }

    /**
     * Audit account deletion. The entry keeps the email so it stays meaningful
     * after the user row is gone.
     */
    public function deleted(User $user): void
    {
        $this->audit->record(
            AuditEvent::UserDeleted,
            auditable: $user,
            oldValues: ['email' => $user->email],
        );
    }

    private function roleSlug(mixed $roleId): ?string
    {
        return is_int($roleId) ? Role::find($roleId)?->slug->value : null;
    }
}
