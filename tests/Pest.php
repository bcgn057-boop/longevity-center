<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Get the audit entries recorded for the given event, oldest first.
 *
 * @return Collection<int, AuditLog>
 */
function auditEntries(AuditEvent $event)
{
    return AuditLog::where('event', $event)->orderBy('id')->get();
}

/**
 * Create a verified user with the given role.
 */
function userWithRole(UserRole $role): User
{
    return User::factory()->withRole($role)->create();
}

/**
 * A valid patient create/update payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function patientPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'sex' => 'female',
        'date_of_birth' => '1985-12-10',
        'email' => 'ada@example.com',
        'phone' => '+14155550123',
        'address' => '12 Analytical Way',
        'emergency_contacts' => [
            ['name' => 'Charles Babbage', 'relationship' => 'Friend', 'phone' => '+14155550199', 'email' => 'charles@example.com'],
        ],
        ...$overrides,
    ];
}
