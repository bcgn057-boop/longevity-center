<?php

use App\Enums\AppointmentType;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 08:00:00', 'UTC'));
    $this->doctor = userWithRole(UserRole::Doctor);
    $this->patient = Patient::factory()->create();
    $this->staff = userWithRole(UserRole::ClinicAdmin);
});

test('the booking form offers a general visit and every therapy, in order', function () {
    $this->actingAs($this->staff)
        ->get(route('appointments.create'))
        ->assertInertia(fn (Assert $page) => $page->where('types', fn ($types) => collect($types)->pluck('label')->all() === [
            'General visit',
            'Exosome Therapy',
            'Peptide Therapy',
            'Stem Cell Therapy',
            'Senolytic Therapies',
            'Hyperbaric Oxygen Therapy (HBOT)',
            'Ozone Therapy',
            'Intermittent Hypoxia-Hyperoxia Therapy (IHHT)',
            'Red Light Therapy (Photobiomodulation)',
            'Pulsed Electromagnetic Field (PEMF) Therapy',
            'Focused Ultrasound',
            'Therapeutic Plasma Exchange (TPE)',
            'Young Plasma/Blood Component Infusions',
        ]));
});

test('booking stores the chosen type', function () {
    $this->actingAs($this->staff)
        ->post(route('appointments.store'), appointmentPayload([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'type' => 'hbot',
        ]))
        ->assertSessionHasNoErrors();

    expect(Appointment::sole()->type)->toBe(AppointmentType::Hbot);
});

test('a type is required and must be one of the list', function (?string $type) {
    $this->actingAs($this->staff)
        ->post(route('appointments.store'), appointmentPayload([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'type' => $type,
        ]))
        ->assertSessionHasErrors(['type' => 'Choose the type of appointment.']);

    expect(Appointment::count())->toBe(0);
})->with(['missing' => null, 'unknown' => 'cryotherapy']);

test('the type is shown in lists, on the appointment and to the patient', function () {
    $account = userWithRole(UserRole::Patient);
    $this->patient->forceFill(['user_id' => $account->id])->save();
    $appointment = Appointment::factory()->for($this->patient)->create([
        'doctor_id' => $this->doctor->id,
        'type' => AppointmentType::PeptideTherapy,
        'start_time' => '2026-11-02 10:00:00',
        'end_time' => '2026-11-02 10:30:00',
    ]);

    $this->actingAs($this->staff)
        ->get(route('appointments.index', ['from' => '2026-11-01', 'to' => '2026-11-07']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appointments.data.0.type', 'peptide_therapy')
            ->where('appointments.data.0.type_label', 'Peptide Therapy'));

    $this->actingAs($this->staff)
        ->get(route('appointments.show', $appointment))
        ->assertInertia(fn (Assert $page) => $page->where('appointment.type_label', 'Peptide Therapy'));

    $this->actingAs($account)
        ->get(route('my-appointments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('upcoming.0.type_label', 'Peptide Therapy'));
});

test('changing the type is audited by field name', function () {
    $appointment = Appointment::factory()->for($this->patient)->create(['doctor_id' => $this->doctor->id]);

    $this->actingAs($this->staff)
        ->put(route('appointments.update', $appointment), appointmentPayload([
            'doctor_id' => $this->doctor->id,
            'type' => 'ozone_therapy',
            'reason' => $appointment->reason ?? '',
            'date' => $appointment->start_time->format('Y-m-d'),
            'start_time' => $appointment->start_time->format('H:i'),
            'end_time' => $appointment->end_time->format('H:i'),
        ]))
        ->assertSessionHasNoErrors();

    expect($appointment->fresh()->type)->toBe(AppointmentType::OzoneTherapy)
        ->and(auditEntries(AuditEvent::AppointmentUpdated)->last()->metadata['fields'])->toContain('type');
});

test('existing appointments default to a general visit', function () {
    expect(Appointment::factory()->create()->fresh()->type)->toBe(AppointmentType::GeneralVisit);
});
