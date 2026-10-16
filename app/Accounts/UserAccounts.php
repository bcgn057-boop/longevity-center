<?php

namespace App\Accounts;

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating and changing accounts on behalf of an administrator.
 *
 * Administrators never set or see passwords: a new account gets a random
 * one and an emailed link to choose their own, which also confirms the
 * address. These rules hold whoever is acting, Super Admins included.
 */
class UserAccounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, role: UserRole, lab_partner_id: int|null}  $details
     */
    public function create(array $details): User
    {
        $user = DB::transaction(function () use ($details) {
            $user = new User(['name' => $details['name'], 'email' => $details['email'], 'password' => Str::password(40)]);
            $user->role_id = Role::fromEnum($details['role'])->id;
            $user->lab_partner_id = $details['role'] === UserRole::LabStaff ? $details['lab_partner_id'] : null;
            $user->save();

            return $user;
        });

        $this->invite($user);

        return $user;
    }

    /**
     * @param  array{name: string, role: UserRole, lab_partner_id: int|null}  $details
     */
    public function update(User $actor, User $account, array $details): void
    {
        if ($actor->is($account) && $account->role?->slug !== $details['role']) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        $this->ensureNotLastSuperAdmin($account, $details['role'] !== UserRole::SuperAdmin, 'role');

        $account->name = $details['name'];
        $account->role_id = Role::fromEnum($details['role'])->id;
        $account->lab_partner_id = $details['role'] === UserRole::LabStaff ? $details['lab_partner_id'] : null;
        $account->save();
    }

    public function deactivate(User $actor, User $account): void
    {
        if ($actor->is($account)) {
            throw ValidationException::withMessages(['account' => 'You cannot deactivate your own account.']);
        }

        $this->ensureNotLastSuperAdmin($account, true, 'account');

        DB::transaction(function () use ($account) {
            $account->forceFill(['deactivated_at' => now(), 'remember_token' => null])->save();

            // End every open session at once, not only on the next request.
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
        });
    }

    public function reactivate(User $account): void
    {
        $account->forceFill(['deactivated_at' => null])->save();
    }

    /**
     * Email a link to set a password. Valid for the password reset expiry.
     */
    public function invite(User $user): void
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        $this->audit->record(AuditEvent::InvitationSent, auditable: $user, metadata: ['sent' => $status === Password::RESET_LINK_SENT]);
    }

    private function ensureNotLastSuperAdmin(User $account, bool $losingAccess, string $field): void
    {
        if (! $losingAccess || $account->role?->slug !== UserRole::SuperAdmin || $account->isDeactivated()) {
            return;
        }

        $others = User::query()
            ->whereKeyNot($account->id)
            ->whereNull('deactivated_at')
            ->whereRelation('role', 'slug', UserRole::SuperAdmin)
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([$field => 'This is the only active Super Admin. Make someone else a Super Admin first.']);
        }
    }
}
