<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('new users are registered as patients', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->hasRole(UserRole::Patient))->toBeTrue();
});

test('users cannot choose their own role when registering', function () {
    $superAdminRole = Role::fromEnum(UserRole::SuperAdmin);

    $this->post(route('register.store'), [
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => $superAdminRole->id,
        'role' => 'super_admin',
    ]);

    $user = User::where('email', 'sneaky@example.com')->firstOrFail();

    expect($user->hasRole(UserRole::Patient))->toBeTrue()
        ->and($user->role_id)->not->toBe($superAdminRole->id);
});

test('registration validates its input', function (array $input, string $errorField) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$input,
    ])->assertSessionHasErrors($errorField);

    $this->assertGuest();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'missing email' => [['email' => ''], 'email'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'duplicate email' => [['email' => 'taken@example.com'], 'email'],
    'missing password' => [['password' => '', 'password_confirmation' => ''], 'password'],
    'password mismatch' => [['password_confirmation' => 'different'], 'password'],
]);
