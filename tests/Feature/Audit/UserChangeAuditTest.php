<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;

test('changing a password is audited without storing the password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

    $entry = auditEntries(AuditEvent::PasswordChanged)->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->auditable_id)->toBe($user->id)
        ->and($entry->old_values)->toBeNull()
        ->and($entry->new_values)->toBeNull();

    expect(json_encode(AuditLog::query()->toBase()->get()))
        ->not->toContain('brand-new-password')
        ->not->toContain($user->fresh()->password);
});

test('changing an email address is audited with old and new values', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'new@example.com',
    ]);

    $entry = auditEntries(AuditEvent::EmailChanged)->sole();

    expect($entry->old_values)->toBe(['email' => 'old@example.com'])
        ->and($entry->new_values)->toBe(['email' => 'new@example.com']);
});

test('changing a role is audited with old and new roles and the acting user', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();
    $user = User::factory()->withRole(UserRole::Patient)->create();

    $this->actingAs($admin);
    $user->assignRole(UserRole::Doctor);

    $entry = auditEntries(AuditEvent::RoleChanged)->sole();

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->auditable_id)->toBe($user->id)
        ->and($entry->old_values)->toBe(['role' => 'patient'])
        ->and($entry->new_values)->toBe(['role' => 'doctor']);
});

test('unrelated profile changes are not audited as security events', function () {
    $user = User::factory()->create();

    $user->update(['name' => 'Someone Else']);

    expect(AuditLog::count())->toBe(0);
});

test('deleting an account is audited and keeps the history of that user', function () {
    $user = User::factory()->create(['email' => 'leaving@example.com']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->delete(route('profile.destroy'), ['password' => 'password']);

    expect(User::find($user->id))->toBeNull();

    $entry = auditEntries(AuditEvent::UserDeleted)->sole();
    expect($entry->auditable_id)->toBe($user->id)
        ->and($entry->old_values)->toBe(['email' => 'leaving@example.com']);

    // Earlier entries for the deleted user are untouched.
    expect(auditEntries(AuditEvent::Login)->sole()->user_id)->toBe($user->id)
        ->and(auditEntries(AuditEvent::Logout)->sole()->user_id)->toBe($user->id);
});

test('denied access is audited with the route and roles involved', function () {
    $patient = User::factory()->withRole(UserRole::Patient)->create();

    $this->actingAs($patient)->get(route('admin.dashboard'))->assertForbidden();

    $entry = auditEntries(AuditEvent::AccessDenied)->sole();

    expect($entry->user_id)->toBe($patient->id)
        ->and($entry->metadata)->toMatchArray([
            'method' => 'GET',
            'path' => '/admin',
            'route' => 'admin.dashboard',
            'required_roles' => ['super_admin', 'clinic_admin'],
            'user_role' => 'patient',
        ]);
});
