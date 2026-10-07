<?php

namespace App\Audit;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Records authentication and account-security events in the audit log.
 *
 * Lives outside app/Listeners so Laravel's event discovery does not register
 * these handlers a second time.
 */
class AuthenticationEventSubscriber
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function onLogin(Login $event): void
    {
        $this->audit->record(
            AuditEvent::Login,
            actor: $this->user($event->user),
            metadata: ['guard' => $event->guard, 'remember' => (bool) $event->remember],
        );
    }

    public function onLogout(Logout $event): void
    {
        $this->audit->record(
            AuditEvent::Logout,
            actor: $this->user($event->user),
            metadata: ['guard' => $event->guard],
        );
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        // No one is authenticated: record the claimed email, and the targeted
        // account (if it exists) as the subject. Credentials are never stored.
        $this->audit->record(
            AuditEvent::LoginFailed,
            actorEmail: is_string($email) ? $email : null,
            auditable: $this->user($event->user),
            metadata: ['guard' => $event->guard, 'account_exists' => $event->user !== null],
        );
    }

    public function onLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->audit->record(
            AuditEvent::Lockout,
            actorEmail: is_string($email) ? $email : null,
            metadata: ['path' => '/'.ltrim($event->request->path(), '/')],
        );
    }

    public function onRegistered(Registered $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::Registered, actor: $user, auditable: $user);
    }

    public function onVerified(Verified $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::EmailVerified, actor: $user, auditable: $user);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $user = $this->user($event->user);

        $this->audit->record(AuditEvent::PasswordReset, actor: $user, auditable: $user);
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->recordForUser(AuditEvent::TwoFactorEnabled, $event->user);
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recordForUser(AuditEvent::TwoFactorConfirmed, $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recordForUser(AuditEvent::TwoFactorDisabled, $event->user);
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        // The user has passed the password step but is not logged in yet.
        $this->recordForUser(AuditEvent::TwoFactorFailed, $event->user);
    }

    public function onRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recordForUser(AuditEvent::RecoveryCodesRegenerated, $event->user);
    }

    public function onPasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->audit->record(
            AuditEvent::PasskeyRegistered,
            actor: $this->user($event->user),
            auditable: $this->user($event->user),
            metadata: ['passkey_id' => $event->passkey->getKey(), 'passkey_name' => $event->passkey->name],
        );
    }

    public function onPasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->audit->record(
            AuditEvent::PasskeyDeleted,
            actor: $this->user($event->user),
            auditable: $this->user($event->user),
            metadata: ['passkey_id' => $event->passkey->getKey(), 'passkey_name' => $event->passkey->name],
        );
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Registered::class => 'onRegistered',
            Verified::class => 'onVerified',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
            RecoveryCodesGenerated::class => 'onRecoveryCodesGenerated',
            PasskeyRegistered::class => 'onPasskeyRegistered',
            PasskeyDeleted::class => 'onPasskeyDeleted',
        ];
    }

    private function recordForUser(AuditEvent $event, mixed $user): void
    {
        $user = $this->user($user);

        $this->audit->record($event, actor: $user, auditable: $user);
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
