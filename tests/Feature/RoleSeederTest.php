<?php

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevAdminSeeder;
use Database\Seeders\RoleSeeder;

test('the role seeder creates every role', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::pluck('slug')->all())
        ->toEqualCanonicalizing(UserRole::cases());
});

test('the role seeder can run repeatedly without duplicating roles', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe(count(UserRole::cases()));
});

test('the dev admin seeder creates one verified super admin in the local environment', function () {
    $this->app['env'] = 'local';

    $this->seed(DevAdminSeeder::class);
    $this->seed(DevAdminSeeder::class);

    $admins = User::whereRelation('role', 'slug', UserRole::SuperAdmin)->get();

    expect($admins)->toHaveCount(1)
        ->and($admins->first()->email)->toBe(config('app.dev_admin.email'))
        ->and($admins->first()->hasVerifiedEmail())->toBeTrue();
});

test('the dev admin seeder does nothing outside the local environment', function (string $env) {
    $this->app['env'] = $env;

    // Called directly: db:seed would prompt for confirmation in production.
    $this->app->make(DevAdminSeeder::class)->run();

    expect(User::where('email', config('app.dev_admin.email'))->exists())->toBeFalse();
})->with(['testing', 'production', 'staging']);

test('the database seeder can run repeatedly', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::count())->toBe(count(UserRole::cases()))
        ->and(User::where('email', 'test@example.com')->first()->hasRole(UserRole::Patient))->toBeTrue();
});
