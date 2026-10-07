<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;

test('a successful login is audited with the user and request details', function () {
    $user = User::factory()->create();

    $this->withHeader('User-Agent', 'PestBrowser/1.0')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $entries = auditEntries(AuditEvent::Login);

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->user_id)->toBe($user->id)
        ->and($entries->first()->actor_email)->toBe($user->email)
        ->and($entries->first()->ip_address)->toBe('127.0.0.1')
        ->and($entries->first()->user_agent)->toBe('PestBrowser/1.0');
});

test('a failed login records the attempted email but never the password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $entry = auditEntries(AuditEvent::LoginFailed)->sole();

    expect($entry->user_id)->toBeNull()
        ->and($entry->actor_email)->toBe($user->email)
        ->and($entry->auditable_id)->toBe($user->id)
        ->and($entry->metadata['account_exists'])->toBeTrue();

    $raw = json_encode(AuditLog::query()->toBase()->get());
    expect($raw)->not->toContain('wrong-password');
});

test('a failed login for an unknown email is audited', function () {
    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'secret']);

    $entry = auditEntries(AuditEvent::LoginFailed)->sole();

    expect($entry->actor_email)->toBe('nobody@example.com')
        ->and($entry->auditable_id)->toBeNull()
        ->and($entry->metadata['account_exists'])->toBeFalse();
});

test('a login lockout is audited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();

    $entry = auditEntries(AuditEvent::Lockout)->sole();

    expect($entry->actor_email)->toBe($user->email)
        ->and($entry->metadata)->toBe(['path' => '/login']);
});

test('a two factor brute force lockout is audited', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);
    $wrongCode = $google2fa->getCurrentOtp($secret) === '000000' ? '111111' : '000000';

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('two-factor.login.store'), ['code' => $wrongCode]);
    }

    $this->post(route('two-factor.login.store'), ['code' => $wrongCode])->assertTooManyRequests();

    expect(auditEntries(AuditEvent::TwoFactorFailed))->toHaveCount(5)
        ->and(auditEntries(AuditEvent::Lockout)->sole()->metadata)->toBe(['path' => '/two-factor-challenge']);
});

test('logout is audited', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    expect(auditEntries(AuditEvent::Logout)->sole()->user_id)->toBe($user->id);
});

test('registration is audited', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'name' => 'New Patient',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'new@example.com')->sole();

    expect(auditEntries(AuditEvent::Registered)->sole()->user_id)->toBe($user->id);
});

test('email verification is audited', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($url);

    expect(auditEntries(AuditEvent::EmailVerified)->sole()->user_id)->toBe($user->id);
});

test('password reset is audited', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        return true;
    });

    expect(auditEntries(AuditEvent::PasswordReset)->sole()->user_id)->toBe($user->id)
        ->and(auditEntries(AuditEvent::PasswordChanged))->toHaveCount(1);
});

test('enabling, confirming and disabling two factor authentication is audited', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'));

    $secret = decrypt($user->fresh()->two_factor_secret);
    $this->post(route('two-factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($secret)]);

    $this->post(route('two-factor.regenerate-recovery-codes'));
    $this->delete(route('two-factor.disable'));

    expect(auditEntries(AuditEvent::TwoFactorEnabled))->toHaveCount(1)
        ->and(auditEntries(AuditEvent::TwoFactorConfirmed))->toHaveCount(1)
        ->and(auditEntries(AuditEvent::RecoveryCodesRegenerated)->count())->toBeGreaterThanOrEqual(1)
        ->and(auditEntries(AuditEvent::TwoFactorDisabled))->toHaveCount(1);

    // The 2FA secret and recovery codes never reach the audit log.
    $raw = json_encode(AuditLog::query()->toBase()->get());
    expect($raw)->not->toContain($secret);
});

test('a failed two factor challenge is audited', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);

    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    // The factory's withTwoFactor() secret is not valid base32, so set a real one.
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);

    $wrongCode = $google2fa->getCurrentOtp($secret) === '000000' ? '111111' : '000000';

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => $wrongCode]);

    $this->assertGuest();
    expect(auditEntries(AuditEvent::TwoFactorFailed)->sole()->user_id)->toBe($user->id);
});

test('passkey registration and deletion are audited', function () {
    $user = User::factory()->create();

    $passkey = new Passkey;
    $passkey->forceFill(['id' => 42, 'name' => 'Work laptop']);

    event(new PasskeyRegistered($user, $passkey));
    event(new PasskeyDeleted($user, $passkey));

    expect(auditEntries(AuditEvent::PasskeyRegistered)->sole()->metadata)
        ->toBe(['passkey_id' => 42, 'passkey_name' => 'Work laptop'])
        ->and(auditEntries(AuditEvent::PasskeyDeleted)->sole()->user_id)->toBe($user->id);
});
