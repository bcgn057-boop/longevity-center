<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\AuditLogger;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('compliance.audit-logs.index'))->assertRedirect(route('login'));
});

test('super admins can view the audit log', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();
    app(AuditLogger::class)->record(AuditEvent::Login, actor: $admin);

    $this->actingAs($admin)
        ->get(route('compliance.audit-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('compliance/AuditLogs')
            ->has('logs.data', 2) // the login, plus this view itself
            ->where('logs.data.0.event', AuditEvent::AuditLogViewed->value)
            ->where('logs.data.1.event', AuditEvent::Login->value)
            ->missing('logs.data.0.hash')
            ->has('events', count(AuditEvent::cases())),
        );
});

test('other roles cannot view the audit log and the attempt is audited', function (UserRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('compliance.audit-logs.index'))->assertForbidden();

    $entry = auditEntries(AuditEvent::AccessDenied)->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->metadata['path'])->toBe('/compliance/audit-logs');
    expect(auditEntries(AuditEvent::AuditLogViewed))->toHaveCount(0);
})->with([
    'clinic admin' => UserRole::ClinicAdmin,
    'doctor' => UserRole::Doctor,
    'practitioner' => UserRole::Practitioner,
    'lab staff' => UserRole::LabStaff,
    'patient' => UserRole::Patient,
]);

test('viewing the audit log is itself audited, including the filters used', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    $this->actingAs($admin)->get(route('compliance.audit-logs.index', ['event' => 'auth.login']));

    $entry = auditEntries(AuditEvent::AuditLogViewed)->sole();

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->metadata)->toBe(['filters' => ['event' => 'auth.login']]);
});

test('the audit log can be filtered by event and user', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $audit = app(AuditLogger::class);
    $audit->record(AuditEvent::Login, actor: $alice);
    $audit->record(AuditEvent::Logout, actor: $alice);
    $audit->record(AuditEvent::Login, actor: $bob);

    $this->actingAs($admin)
        ->get(route('compliance.audit-logs.index', ['event' => 'auth.login', 'user_id' => $alice->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.user_id', $alice->id)
            ->where('logs.data.0.event', 'auth.login')
            ->where('filters', ['event' => 'auth.login', 'user_id' => $alice->id]),
        );
});

test('invalid filters are rejected', function (array $query, string $field) {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    $this->actingAs($admin)
        ->get(route('compliance.audit-logs.index', $query))
        ->assertSessionHasErrors($field);

    expect(auditEntries(AuditEvent::AuditLogViewed))->toHaveCount(0);
})->with([
    'unknown event' => [['event' => 'auth.hacked'], 'event'],
    'non-numeric user id' => [['user_id' => 'abc'], 'user_id'],
    'negative user id' => [['user_id' => -1], 'user_id'],
]);

test('the audit log is paginated', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    $audit = app(AuditLogger::class);
    foreach (range(1, 60) as $i) {
        $audit->record(AuditEvent::Login, actor: $admin);
    }

    $this->actingAs($admin)
        ->get(route('compliance.audit-logs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 50)
            ->where('logs.total', 61)
            ->where('logs.last_page', 2),
        );
});

/**
 * Headers the Inertia client sends for a visit, optionally as a partial reload.
 *
 * @param  array<int, string>  $only
 * @return array<string, string>
 */
function inertiaHeaders(array $only = []): array
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
    ];

    if ($only !== []) {
        $headers['X-Inertia-Partial-Component'] = 'compliance/AuditLogs';
        $headers['X-Inertia-Partial-Data'] = implode(',', $only);
    }

    return $headers;
}

test('filters are applied on an inertia partial reload', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();
    $alice = User::factory()->create();

    $audit = app(AuditLogger::class);
    $audit->record(AuditEvent::Login, actor: $alice);
    $audit->record(AuditEvent::Logout, actor: $alice);

    $this->actingAs($admin)->get(route('compliance.audit-logs.index'));

    $response = $this->withHeaders(inertiaHeaders(['logs', 'filters']))
        ->get(route('compliance.audit-logs.index', ['event' => 'auth.logout', 'user_id' => $alice->id]))
        ->assertOk()
        ->json('props');

    expect($response['logs']['data'])->toHaveCount(1)
        ->and($response['logs']['data'][0]['event'])->toBe('auth.logout')
        ->and($response['filters'])->toBe(['event' => 'auth.logout', 'user_id' => $alice->id])
        ->and($response)->not->toHaveKey('events');
});

test('partial reloads after the initial page load are not audited as new views', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    $this->actingAs($admin)->get(route('compliance.audit-logs.index'));

    // Filter, reset, and page: all partial reloads.
    $this->withHeaders(inertiaHeaders(['logs', 'filters']))->get(route('compliance.audit-logs.index', ['event' => 'auth.login']))->assertOk();
    $this->withHeaders(inertiaHeaders(['logs', 'filters']))->get(route('compliance.audit-logs.index'))->assertOk();
    $this->withHeaders(inertiaHeaders(['logs', 'filters']))->get(route('compliance.audit-logs.index', ['page' => 2]))->assertOk();

    expect(auditEntries(AuditEvent::AuditLogViewed))->toHaveCount(1);
});

test('full inertia visits to the audit log are audited', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    $this->actingAs($admin)->get(route('compliance.audit-logs.index'));
    $this->withHeaders(inertiaHeaders())->get(route('compliance.audit-logs.index'))->assertOk();

    expect(auditEntries(AuditEvent::AuditLogViewed))->toHaveCount(2);
});

test('a forged partial reload header cannot be used to read the log without being audited', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    // No prior full page load in this session.
    $this->actingAs($admin)
        ->withHeaders(inertiaHeaders(['logs', 'filters']))
        ->get(route('compliance.audit-logs.index'))
        ->assertOk();

    expect(auditEntries(AuditEvent::AuditLogViewed))->toHaveCount(1);
});

test('a partial reload header for another page does not skip auditing', function () {
    $admin = User::factory()->withRole(UserRole::SuperAdmin)->create();

    $this->actingAs($admin)->get(route('compliance.audit-logs.index'));

    $this->withHeaders([...inertiaHeaders(['logs']), 'X-Inertia-Partial-Component' => 'Dashboard'])
        ->get(route('compliance.audit-logs.index'));

    expect(auditEntries(AuditEvent::AuditLogViewed))->toHaveCount(2);
});
