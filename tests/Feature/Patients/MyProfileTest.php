<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Patient;
use Inertia\Testing\AssertableInertia as Assert;

test('a patient sees their own record including medical information', function () {
    $user = userWithRole(UserRole::Patient);
    $patient = Patient::factory()->withAccount($user)->withEmergencyContacts()->create([
        'medical_history' => 'My own history',
    ]);

    $this->actingAs($user)
        ->get(route('my-profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/MyProfile')
            ->where('patient.id', $patient->id)
            ->where('patient.medical_history', 'My own history')
            ->has('patient.emergency_contacts', 1));

    expect(auditEntries(AuditEvent::PatientViewed)->sole()->metadata['context'])->toBe('self');
});

test('a patient never sees another patient record', function () {
    $user = userWithRole(UserRole::Patient);
    Patient::factory()->withAccount($user)->create(['first_name' => 'Mine']);
    Patient::factory()->create(['first_name' => 'Someone', 'medical_history' => 'Not yours']);

    $content = $this->actingAs($user)->get(route('my-profile'))->getContent();

    expect($content)->toContain('Mine')->not->toContain('Not yours');
});

test('a patient without a linked record sees a notice', function () {
    $this->actingAs(userWithRole(UserRole::Patient))
        ->get(route('my-profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('patient', null));

    expect(auditEntries(AuditEvent::PatientViewed))->toHaveCount(0);
});

test('an archived record is not shown to the patient', function () {
    $user = userWithRole(UserRole::Patient);
    Patient::factory()->withAccount($user)->create()->delete();

    $this->actingAs($user)
        ->get(route('my-profile'))
        ->assertInertia(fn (Assert $page) => $page->where('patient', null));
});

test('staff cannot use the patient portal profile page', function (UserRole $role) {
    $this->actingAs(userWithRole($role))->get(route('my-profile'))->assertForbidden();
})->with([
    'doctor' => UserRole::Doctor,
    'super admin' => UserRole::SuperAdmin,
]);
