<?php

use App\Models\EmergencyContact;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

test('identifying and medical fields are encrypted at rest', function () {
    $patient = Patient::factory()->create([
        'date_of_birth' => '1985-12-10',
        'email' => 'ada@example.com',
        'phone' => '+14155550123',
        'address' => '12 Analytical Way',
        'medical_history' => 'Hypertension',
        'allergies' => 'Latex',
    ]);

    $raw = (array) DB::table('patients')->where('id', $patient->id)->first();

    foreach (['date_of_birth', 'email', 'phone', 'address', 'medical_history', 'allergies'] as $field) {
        expect($raw[$field])->not->toBe($patient->{$field})
            ->and(decrypt($raw[$field], false))->toBe($patient->{$field});
    }

    expect(json_encode($raw))
        ->not->toContain('1985-12-10')
        ->not->toContain('ada@example.com')
        ->not->toContain('Hypertension');
});

test('names stay searchable in plain text', function () {
    $patient = Patient::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    $raw = DB::table('patients')->where('id', $patient->id)->first();

    expect($raw->first_name)->toBe('Ada')
        ->and($raw->last_name)->toBe('Lovelace');
});

test('emergency contact phone and email are encrypted at rest', function () {
    $contact = EmergencyContact::factory()->create(['phone' => '+14155550199', 'email' => 'charles@example.com']);

    $raw = DB::table('emergency_contacts')->where('id', $contact->id)->first();

    expect($raw->phone)->not->toContain('4155550199')
        ->and($raw->email)->not->toContain('charles')
        ->and($raw->name)->toBe($contact->name);
});

test('encrypted fields are hidden from model serialization', function () {
    $patient = Patient::factory()->create();

    expect($patient->toArray())
        ->not->toHaveKeys(['date_of_birth', 'email', 'phone', 'address', 'medical_history', 'allergies']);
});
