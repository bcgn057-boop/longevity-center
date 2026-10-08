<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Patient;

test('creating a patient is audited with its record number', function () {
    $doctor = userWithRole(UserRole::Doctor);

    $this->actingAs($doctor)->post(route('patients.store'), patientPayload());

    $patient = Patient::sole();
    $entry = auditEntries(AuditEvent::PatientCreated)->sole();

    expect($entry->user_id)->toBe($doctor->id)
        ->and($entry->auditable_id)->toBe($patient->id)
        ->and($entry->metadata)->toBe(['medical_record_number' => $patient->medical_record_number]);
});

test('viewing a patient is audited with the sections shown', function (UserRole $role, array $sections) {
    $patient = Patient::factory()->create();
    $user = userWithRole($role);

    $this->actingAs($user)->get(route('patients.show', $patient));

    $entry = auditEntries(AuditEvent::PatientViewed)->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->auditable_id)->toBe($patient->id)
        ->and($entry->metadata)->toBe(['context' => 'show', 'sections' => $sections]);
})->with([
    'doctor' => [UserRole::Doctor, ['demographics', 'contact', 'emergency_contacts', 'medical', 'care_reminders', 'lab_reports']],
    'clinic admin' => [UserRole::ClinicAdmin, ['demographics', 'contact', 'emergency_contacts', 'care_reminders']],
    'super admin' => [UserRole::SuperAdmin, ['demographics', 'contact', 'emergency_contacts']],
]);

test('opening the edit form is audited as a view', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole(UserRole::Doctor))->get(route('patients.edit', $patient));

    expect(auditEntries(AuditEvent::PatientViewed)->sole()->metadata['context'])->toBe('edit');
});

test('searching patients is audited with the term and result count', function () {
    Patient::factory()->create(['last_name' => 'Lovelace']);

    $this->actingAs(userWithRole(UserRole::Doctor))->get(route('patients.index', ['search' => 'Love']));

    expect(auditEntries(AuditEvent::PatientSearched)->sole()->metadata)
        ->toBe(['search' => 'Love', 'page' => 1, 'results' => 1]);
});

test('updates record which fields changed but never their values', function () {
    $patient = Patient::factory()->withEmergencyContacts()->create([
        'first_name' => 'Ada',
        'phone' => '+14155550100',
        'medical_history' => 'Old history',
    ]);

    $this->actingAs(userWithRole(UserRole::Doctor))->put(route('patients.update', $patient), patientPayload([
        'first_name' => 'Ada',
        'phone' => '+14155550999',
        'medical_history' => 'Brand new diagnosis',
        'emergency_contacts' => [],
    ]));

    $entries = auditEntries(AuditEvent::PatientUpdated);
    $fields = $entries->flatMap(fn (AuditLog $entry) => $entry->metadata['fields'])->all();

    expect($fields)->toContain('phone', 'medical_history', 'emergency_contacts')
        ->not->toContain('first_name')
        ->and($entries->every(fn (AuditLog $entry) => $entry->old_values === null && $entry->new_values === null))->toBeTrue();
});

test('the audit log never contains patient identifying or medical values', function () {
    $user = userWithRole(UserRole::Doctor);
    $this->actingAs($user);

    $this->post(route('patients.store'), patientPayload([
        'date_of_birth' => '1985-12-10',
        'phone' => '+14155550123',
        'medical_history' => 'Rare genetic condition',
        'allergies' => 'Shellfish',
    ]));
    $patient = Patient::sole();

    $this->get(route('patients.show', $patient));
    $this->put(route('patients.update', $patient), patientPayload(['medical_history' => 'Updated condition notes']));

    $raw = json_encode(AuditLog::query()->toBase()->get());

    expect($raw)
        ->not->toContain('1985-12-10')
        ->not->toContain('4155550123')
        ->not->toContain('Rare genetic condition')
        ->not->toContain('Updated condition notes')
        ->not->toContain('Shellfish')
        ->not->toContain('12 Analytical Way')
        ->not->toContain('Charles Babbage');
});

test('saving without changes does not create an update entry', function () {
    $patient = Patient::factory()->create();

    $patient->save();

    expect(auditEntries(AuditEvent::PatientUpdated))->toHaveCount(0);
});

test('archiving is audited', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))->delete(route('patients.destroy', $patient));

    expect(auditEntries(AuditEvent::PatientArchived)->sole()->auditable_id)->toBe($patient->id);
});

test('the audit chain stays valid through patient activity', function () {
    $this->actingAs(userWithRole(UserRole::Doctor))->post(route('patients.store'), patientPayload());
    $this->get(route('patients.show', Patient::sole()));

    $this->artisan('audit:verify')->assertSuccessful();
});
