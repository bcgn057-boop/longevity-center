<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 08:00:00', 'UTC'));
});

test('viewing the schedule is audited with the filters and result count', function () {
    Appointment::factory()->at('2026-11-02 10:00')->create();
    $doctor = userWithRole(UserRole::Doctor);

    $this->actingAs($doctor)->get(route('appointments.index', ['status' => 'scheduled']));

    $entry = auditEntries(AuditEvent::AppointmentListed)->sole();

    expect($entry->user_id)->toBe($doctor->id)
        ->and($entry->metadata)->toBe([
            'context' => 'schedule',
            'from' => '2026-11-01',
            'to' => '2026-11-07',
            'doctor_id' => null,
            'status' => 'scheduled',
            'page' => 1,
            'results' => 1,
        ]);
});

test('viewing an appointment is audited with whether the reason was shown', function (UserRole $role, bool $shown) {
    $appointment = Appointment::factory()->create();

    $this->actingAs(userWithRole($role))->get(route('appointments.show', $appointment));

    $entry = auditEntries(AuditEvent::AppointmentViewed)->sole();

    expect($entry->auditable_id)->toBe($appointment->id)
        ->and($entry->metadata)->toBe(['context' => 'show', 'reason_shown' => $shown]);
})->with([
    'doctor' => [UserRole::Doctor, true],
    'super admin' => [UserRole::SuperAdmin, false],
]);

test('opening the reschedule form is audited as a view', function () {
    $appointment = Appointment::factory()->create();

    $this->actingAs(userWithRole(UserRole::Doctor))->get(route('appointments.edit', $appointment));

    expect(auditEntries(AuditEvent::AppointmentViewed)->sole()->metadata['context'])->toBe('edit');
});

test('booking and rescheduling through the pages are audited by the acting user', function () {
    $staff = userWithRole(UserRole::ClinicAdmin);
    $doctor = userWithRole(UserRole::Doctor);
    $patient = Patient::factory()->create();
    $this->actingAs($staff);

    $this->post(route('appointments.store'), appointmentPayload(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]));
    $appointment = Appointment::sole();
    $this->put(route('appointments.update', $appointment), appointmentPayload(['doctor_id' => $doctor->id, 'start_time' => '11:00', 'end_time' => '11:30']));

    expect(auditEntries(AuditEvent::AppointmentCreated)->sole()->user_id)->toBe($staff->id)
        ->and(auditEntries(AuditEvent::AppointmentRescheduled)->sole()->user_id)->toBe($staff->id);
});

test('the audit log never contains reasons from the pages', function () {
    $doctor = userWithRole(UserRole::Doctor);
    $patient = Patient::factory()->create();
    $this->actingAs($doctor);

    $this->post(route('appointments.store'), appointmentPayload([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'reason' => 'Suspected melanoma',
    ]));
    $appointment = Appointment::sole();
    $this->get(route('appointments.show', $appointment));
    $this->post(route('appointments.cancel', $appointment), ['cancellation_reason' => 'Admitted to hospital']);

    expect(json_encode(AuditLog::query()->toBase()->get()))
        ->not->toContain('melanoma')
        ->not->toContain('hospital');

    $this->artisan('audit:verify')->assertSuccessful();
});
