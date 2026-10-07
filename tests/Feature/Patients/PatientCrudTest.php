<?php

use App\Enums\Sex;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Models\Patient;
use Inertia\Testing\AssertableInertia as Assert;

test('a doctor can create a patient with medical information and contacts', function () {
    $doctor = userWithRole(UserRole::Doctor);

    $response = $this->actingAs($doctor)->post(route('patients.store'), patientPayload([
        'medical_history' => 'Hypertension',
        'allergies' => 'Latex',
    ]));

    $patient = Patient::sole();
    $response->assertRedirect(route('patients.show', $patient));

    expect($patient->full_name)->toBe('Ada Lovelace')
        ->and($patient->sex)->toBe(Sex::Female)
        ->and($patient->date_of_birth)->toBe('1985-12-10')
        ->and($patient->medical_history)->toBe('Hypertension')
        ->and($patient->allergies)->toBe('Latex')
        ->and($patient->created_by)->toBe($doctor->id)
        ->and($patient->emergencyContacts)->toHaveCount(1)
        ->and($patient->emergencyContacts->first()->phone)->toBe('+14155550199');
});

test('patients are assigned sequential medical record numbers', function () {
    $first = Patient::factory()->create();
    $second = Patient::factory()->create();

    expect($first->fresh()->medical_record_number)->toBe('LC-'.str_pad((string) $first->id, 6, '0', STR_PAD_LEFT))
        ->and($second->fresh()->medical_record_number)->toBe('LC-'.str_pad((string) $second->id, 6, '0', STR_PAD_LEFT));
});

test('a clinic admin can create a patient without medical information', function () {
    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->post(route('patients.store'), patientPayload())
        ->assertSessionHasNoErrors();

    expect(Patient::sole()->medical_history)->toBeNull();
});

test('the create form only offers medical fields to clinicians', function (UserRole $role, bool $canEditMedical) {
    $this->actingAs(userWithRole($role))
        ->get(route('patients.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('patients/Create')
            ->where('canEditMedical', $canEditMedical));
})->with([
    'doctor' => [UserRole::Doctor, true],
    'clinic admin' => [UserRole::ClinicAdmin, false],
    'super admin' => [UserRole::SuperAdmin, false],
]);

test('a clinic admin can update demographics without touching medical data', function () {
    $patient = Patient::factory()->create(['medical_history' => 'Keep me']);

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->put(route('patients.update', $patient), patientPayload(['first_name' => 'Augusta']))
        ->assertRedirect(route('patients.show', $patient));

    $patient->refresh();
    expect($patient->first_name)->toBe('Augusta')
        ->and($patient->medical_history)->toBe('Keep me');
});

test('a practitioner can update medical information', function () {
    $patient = Patient::factory()->create(['medical_history' => 'Old']);

    $this->actingAs(userWithRole(UserRole::Practitioner))
        ->put(route('patients.update', $patient), patientPayload(['medical_history' => 'New', 'allergies' => null]))
        ->assertSessionHasNoErrors();

    expect($patient->fresh()->medical_history)->toBe('New');
});

test('emergency contacts are replaced on update', function () {
    $patient = Patient::factory()->withEmergencyContacts(2)->create();

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->put(route('patients.update', $patient), patientPayload([
            'emergency_contacts' => [
                ['name' => 'Only One', 'relationship' => 'Sibling', 'phone' => '+447700900123', 'email' => null],
            ],
        ]));

    expect($patient->emergencyContacts()->pluck('name')->all())->toBe(['Only One']);
});

test('archiving hides the patient but keeps the record', function () {
    $patient = Patient::factory()->create(['last_name' => 'Archived']);

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->delete(route('patients.destroy', $patient))
        ->assertRedirect(route('patients.index'));

    expect(Patient::find($patient->id))->toBeNull()
        ->and(Patient::withTrashed()->find($patient->id))->not->toBeNull();

    $this->get(route('patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('patients.total', 0));

    $this->get(route('patients.show', $patient->id))->assertNotFound();
});

test('patient records cannot be permanently deleted', function () {
    $patient = Patient::factory()->create();

    expect(fn () => $patient->forceDelete())->toThrow(MedicalRecordRetentionException::class);
    expect(Patient::withTrashed()->count())->toBe(1);
});

test('patients can be searched by name or record number', function () {
    $ada = Patient::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    Patient::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

    $this->actingAs(userWithRole(UserRole::Doctor));

    foreach (['love', 'Ada Love', $ada->fresh()->medical_record_number] as $term) {
        $this->get(route('patients.index', ['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('patients.data', 1)
                ->where('patients.data.0.full_name', 'Ada Lovelace'));
    }
});

test('the patient list exposes no contact or medical details', function () {
    Patient::factory()->create(['phone' => '+14155550123', 'medical_history' => 'Secret history']);

    $content = $this->actingAs(userWithRole(UserRole::Doctor))->get(route('patients.index'))->getContent();

    expect($content)->not->toContain('+14155550123')->not->toContain('Secret history');
});
