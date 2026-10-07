<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page from the admin area', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('unverified users are redirected to email verification from the admin area', function () {
    $user = User::factory()->unverified()->withRole(UserRole::SuperAdmin)->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('admin roles can access the admin area', function (UserRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard'));
})->with([
    'super admin' => UserRole::SuperAdmin,
    'clinic admin' => UserRole::ClinicAdmin,
]);

test('non-admin roles are forbidden from the admin area', function (UserRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
})->with([
    'doctor' => UserRole::Doctor,
    'practitioner' => UserRole::Practitioner,
    'lab staff' => UserRole::LabStaff,
    'patient' => UserRole::Patient,
]);

test('users without a role are forbidden from the admin area', function () {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('every role can access the dashboard', function (UserRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
})->with(UserRole::cases());

test('the authenticated user role is shared with the frontend', function () {
    $user = User::factory()->withRole(UserRole::Doctor)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.role', 'doctor'));
});

test('gates grant access according to role', function (UserRole $role, array $expected) {
    $user = User::factory()->withRole($role)->create();

    foreach ($expected as $ability => $allowed) {
        expect(Gate::forUser($user)->allows($ability))
            ->toBe($allowed, "{$role->value} / {$ability}");
    }
})->with([
    // The Super Admin bypass deliberately excludes clinical abilities.
    'super admin' => [UserRole::SuperAdmin, [
        'access-admin' => true, 'access-clinical' => false, 'access-lab' => true, 'access-patient-portal' => true,
    ]],
    'clinic admin' => [UserRole::ClinicAdmin, [
        'access-admin' => true, 'access-clinical' => false, 'access-lab' => false, 'access-patient-portal' => false,
    ]],
    'doctor' => [UserRole::Doctor, [
        'access-admin' => false, 'access-clinical' => true, 'access-lab' => false, 'access-patient-portal' => false,
    ]],
    'practitioner' => [UserRole::Practitioner, [
        'access-admin' => false, 'access-clinical' => true, 'access-lab' => false, 'access-patient-portal' => false,
    ]],
    'lab staff' => [UserRole::LabStaff, [
        'access-admin' => false, 'access-clinical' => false, 'access-lab' => true, 'access-patient-portal' => false,
    ]],
    'patient' => [UserRole::Patient, [
        'access-admin' => false, 'access-clinical' => false, 'access-lab' => false, 'access-patient-portal' => true,
    ]],
]);

test('users without a role pass no gates', function () {
    $user = User::factory()->withoutRole()->create();

    expect(Gate::forUser($user)->any([
        'access-admin', 'access-clinical', 'access-lab', 'access-patient-portal',
    ]))->toBeFalse();
});

test('role helpers on the user model', function () {
    $user = User::factory()->withRole(UserRole::LabStaff)->create();

    expect($user->hasRole(UserRole::LabStaff))->toBeTrue()
        ->and($user->hasRole(UserRole::Doctor))->toBeFalse()
        ->and($user->hasAnyRole(UserRole::Doctor, UserRole::LabStaff))->toBeTrue()
        ->and($user->hasAnyRole(UserRole::Doctor, UserRole::Patient))->toBeFalse();

    $user->assignRole(UserRole::Doctor);

    expect($user->fresh()->hasRole(UserRole::Doctor))->toBeTrue();
});
