<?php

use App\Enums\UserRole;
use App\Models\Patient;

test('invalid patient input is rejected', function (array $overrides, string $field) {
    $this->actingAs(userWithRole(UserRole::Doctor))
        ->post(route('patients.store'), patientPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(Patient::count())->toBe(0);
})->with([
    'missing first name' => [['first_name' => ''], 'first_name'],
    'first name too long' => [['first_name' => str_repeat('a', 101)], 'first_name'],
    'missing last name' => [['last_name' => ''], 'last_name'],
    'unknown sex' => [['sex' => 'robot'], 'sex'],
    'missing date of birth' => [['date_of_birth' => ''], 'date_of_birth'],
    'malformed date of birth' => [['date_of_birth' => '10/12/1985'], 'date_of_birth'],
    'future date of birth' => [['date_of_birth' => now()->addDay()->format('Y-m-d')], 'date_of_birth'],
    'implausible date of birth' => [['date_of_birth' => '1850-01-01'], 'date_of_birth'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'local phone number' => [['phone' => '0123 456 789'], 'phone'],
    'phone with letters' => [['phone' => '+1415CALLNOW'], 'phone'],
    'address too long' => [['address' => str_repeat('a', 501)], 'address'],
    'medical history too long' => [['medical_history' => str_repeat('a', 10001)], 'medical_history'],
    'too many contacts' => [['emergency_contacts' => array_fill(0, 4, ['name' => 'A', 'relationship' => 'B', 'phone' => '+14155550100'])], 'emergency_contacts'],
    'contact without name' => [['emergency_contacts' => [['name' => '', 'relationship' => 'B', 'phone' => '+14155550100']]], 'emergency_contacts.0.name'],
    'contact with bad phone' => [['emergency_contacts' => [['name' => 'A', 'relationship' => 'B', 'phone' => '12']]], 'emergency_contacts.0.phone'],
    'contact with unexpected field' => [['emergency_contacts' => [['name' => 'A', 'relationship' => 'B', 'phone' => '+14155550100', 'patient_id' => 5]]], 'emergency_contacts.0'],
]);

test('phone numbers are normalized to international format', function (string $input, string $expected) {
    $this->actingAs(userWithRole(UserRole::Doctor))
        ->post(route('patients.store'), patientPayload(['phone' => $input]))
        ->assertSessionHasNoErrors();

    expect(Patient::sole()->phone)->toBe($expected);
})->with([
    'spaces and dashes' => ['+1 415-555-0123', '+14155550123'],
    'parentheses and dots' => ['+1 (415) 555.0123', '+14155550123'],
    'leading 00' => ['0044 7700 900123', '+447700900123'],
]);

test('non-clinicians are rejected when sending medical fields', function (UserRole $role) {
    $patient = Patient::factory()->create(['medical_history' => 'Original']);
    $this->actingAs(userWithRole($role));

    $this->post(route('patients.store'), patientPayload(['medical_history' => 'Smuggled']))
        ->assertSessionHasErrors('medical_history');

    $this->put(route('patients.update', $patient), patientPayload(['allergies' => 'Smuggled']))
        ->assertSessionHasErrors('allergies');

    expect(Patient::count())->toBe(1)
        ->and($patient->fresh()->allergies)->not->toBe('Smuggled');
})->with([
    'clinic admin' => UserRole::ClinicAdmin,
    'super admin' => UserRole::SuperAdmin,
]);

test('fields that are not part of the form cannot be mass assigned', function () {
    $other = userWithRole(UserRole::Patient);

    $this->actingAs(userWithRole(UserRole::Doctor))
        ->post(route('patients.store'), patientPayload([
            'user_id' => $other->id,
            'medical_record_number' => 'HACKED',
            'created_by' => $other->id,
        ]));

    $patient = Patient::sole();

    expect($patient->user_id)->toBeNull()
        ->and($patient->medical_record_number)->not->toBe('HACKED')
        ->and($patient->created_by)->not->toBe($other->id);
});
