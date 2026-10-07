<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Patient;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function (string $route) {
    $patient = Patient::factory()->create();

    $this->get(route($route, $patient))->assertRedirect(route('login'));
})->with(['patients.index', 'patients.show', 'patients.edit', 'my-profile']);

test('staff roles can list and view patients', function (UserRole $role) {
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole($role))->get(route('patients.index'))->assertOk();
    $this->get(route('patients.show', $patient))->assertOk();
})->with([
    'super admin' => UserRole::SuperAdmin,
    'clinic admin' => UserRole::ClinicAdmin,
    'doctor' => UserRole::Doctor,
    'practitioner' => UserRole::Practitioner,
]);

test('lab staff and patients cannot reach staff patient routes', function (UserRole $role) {
    $patient = Patient::factory()->create();
    $this->actingAs(userWithRole($role));

    $this->get(route('patients.index'))->assertForbidden();
    $this->get(route('patients.show', $patient))->assertForbidden();
    $this->get(route('patients.edit', $patient))->assertForbidden();
    $this->post(route('patients.store'), patientPayload())->assertForbidden();
    $this->put(route('patients.update', $patient), patientPayload())->assertForbidden();
    $this->delete(route('patients.destroy', $patient))->assertForbidden();

    expect(Patient::count())->toBe(1);
})->with([
    'lab staff' => UserRole::LabStaff,
    'patient' => UserRole::Patient,
]);

test('a patient cannot view their own record through staff routes either', function () {
    $user = userWithRole(UserRole::Patient);
    $patient = Patient::factory()->withAccount($user)->create();

    $this->actingAs($user)->get(route('patients.show', $patient))->assertForbidden();
});

test('denied users cannot tell whether a patient id exists', function () {
    $patient = Patient::factory()->create();
    $this->actingAs(userWithRole(UserRole::Patient));

    $this->get(route('patients.show', $patient))->assertForbidden();
    $this->get(route('patients.show', 999999))->assertForbidden();
});

test('medical fields are sent only to clinicians', function (UserRole $role, bool $seesMedical) {
    $patient = Patient::factory()->create(['medical_history' => 'Type 2 diabetes', 'allergies' => 'Penicillin']);

    $this->actingAs(userWithRole($role))
        ->get(route('patients.show', $patient))
        ->assertInertia(fn (Assert $page) => $seesMedical
            ? $page->where('patient.medical_history', 'Type 2 diabetes')->where('patient.allergies', 'Penicillin')
            : $page->missing('patient.medical_history')->missing('patient.allergies'));
})->with([
    'doctor' => [UserRole::Doctor, true],
    'practitioner' => [UserRole::Practitioner, true],
    'clinic admin' => [UserRole::ClinicAdmin, false],
    'super admin' => [UserRole::SuperAdmin, false],
]);

test('medical text never appears in responses to non-clinicians', function () {
    $patient = Patient::factory()->create(['medical_history' => 'Confidential cardiac history']);

    $this->actingAs(userWithRole(UserRole::ClinicAdmin));

    expect($this->get(route('patients.show', $patient))->getContent())->not->toContain('Confidential cardiac history')
        ->and($this->get(route('patients.edit', $patient))->getContent())->not->toContain('Confidential cardiac history');
});

test('only clinic admins and super admins can archive and link accounts', function (UserRole $role, bool $allowed) {
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole($role))
        ->get(route('patients.show', $patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.archive', $allowed)
            ->where('can.linkAccount', $allowed));

    $response = $this->delete(route('patients.destroy', $patient));

    $allowed ? $response->assertRedirect(route('patients.index')) : $response->assertForbidden();
})->with([
    'clinic admin' => [UserRole::ClinicAdmin, true],
    'super admin' => [UserRole::SuperAdmin, true],
    'doctor' => [UserRole::Doctor, false],
    'practitioner' => [UserRole::Practitioner, false],
]);

test('policy denials are audited', function () {
    $doctor = userWithRole(UserRole::Doctor);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)->delete(route('patients.destroy', $patient))->assertForbidden();

    $entry = auditEntries(AuditEvent::AccessDenied)->sole();

    expect($entry->user_id)->toBe($doctor->id)
        ->and($entry->metadata)->toMatchArray(['reason' => 'policy', 'route' => 'patients.destroy', 'user_role' => 'doctor']);
});

test('role middleware denials are audited exactly once', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole(UserRole::LabStaff))->get(route('patients.show', $patient))->assertForbidden();

    expect(auditEntries(AuditEvent::AccessDenied))->toHaveCount(1);
});
