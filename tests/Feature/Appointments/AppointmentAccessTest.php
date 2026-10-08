<?php

use App\Enums\UserRole;
use App\Models\Appointment;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function (string $route) {
    $appointment = Appointment::factory()->create();

    $this->get(route($route, $appointment))->assertRedirect(route('login'));
})->with(['appointments.index', 'appointments.create', 'appointments.show', 'my-appointments.index']);

test('staff roles can use the schedule', function (UserRole $role) {
    $appointment = Appointment::factory()->create();
    $this->actingAs(userWithRole($role));

    $this->get(route('appointments.index'))->assertOk();
    $this->get(route('appointments.create'))->assertOk();
    $this->get(route('appointments.show', $appointment))->assertOk();
    $this->get(route('appointments.edit', $appointment))->assertOk();
})->with([
    'super admin' => UserRole::SuperAdmin,
    'clinic admin' => UserRole::ClinicAdmin,
    'doctor' => UserRole::Doctor,
    'practitioner' => UserRole::Practitioner,
]);

test('lab staff and patients cannot reach staff appointment routes', function (UserRole $role) {
    $appointment = Appointment::factory()->create();
    $this->actingAs(userWithRole($role));

    $this->get(route('appointments.index'))->assertForbidden();
    $this->get(route('appointments.create'))->assertForbidden();
    $this->get(route('appointments.show', $appointment))->assertForbidden();
    $this->get(route('appointments.edit', $appointment))->assertForbidden();
    $this->post(route('appointments.store'), appointmentPayload())->assertForbidden();
    $this->put(route('appointments.update', $appointment), appointmentPayload())->assertForbidden();
    $this->post(route('appointments.complete', $appointment))->assertForbidden();
    $this->post(route('appointments.cancel', $appointment))->assertForbidden();

    expect($appointment->fresh()->status->value)->toBe('scheduled');
})->with([
    'lab staff' => UserRole::LabStaff,
    'patient' => UserRole::Patient,
]);

test('denied users cannot tell whether an appointment id exists', function () {
    $appointment = Appointment::factory()->create();
    $this->actingAs(userWithRole(UserRole::LabStaff));

    $this->get(route('appointments.show', $appointment))->assertForbidden();
    $this->get(route('appointments.show', 999999))->assertForbidden();
});

test('staff cannot use the patient portal appointment pages', function (UserRole $role) {
    $this->actingAs(userWithRole($role))->get(route('my-appointments.index'))->assertForbidden();
})->with([
    'doctor' => UserRole::Doctor,
    'super admin' => UserRole::SuperAdmin,
]);

test('the reason for a visit is sent to staff but not to the super admin', function (UserRole $role, bool $seesReason) {
    $appointment = Appointment::factory()->create(['reason' => 'Discuss thyroid results']);

    $response = $this->actingAs(userWithRole($role))->get(route('appointments.show', $appointment));

    $response->assertInertia(fn (Assert $page) => $seesReason
        ? $page->where('appointment.reason', 'Discuss thyroid results')
        : $page->missing('appointment.reason')->missing('appointment.cancellation_reason'));

    expect(str_contains($response->getContent(), 'thyroid'))->toBe($seesReason);
})->with([
    'doctor' => [UserRole::Doctor, true],
    'clinic admin' => [UserRole::ClinicAdmin, true],
    'super admin' => [UserRole::SuperAdmin, false],
]);

test('the schedule list never includes reasons', function () {
    Appointment::factory()->at(now()->addDay())->create(['reason' => 'Discuss thyroid results']);

    $content = $this->actingAs(userWithRole(UserRole::Doctor))->get(route('appointments.index'))->getContent();

    expect($content)->not->toContain('thyroid');
});

test('the super admin cannot record a reason when booking', function () {
    $this->travelTo('2026-11-01 08:00:00');
    $appointment = Appointment::factory()->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->post(route('appointments.store'), appointmentPayload([
            'patient_id' => $appointment->patient_id,
            'doctor_id' => $appointment->doctor_id,
            'date' => '2026-11-03',
        ]))
        ->assertSessionHasErrors(['reason' => 'You are not allowed to record the reason for a visit.']);
});
