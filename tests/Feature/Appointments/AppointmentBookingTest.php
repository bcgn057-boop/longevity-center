<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 08:00:00', 'UTC'));
    $this->doctor = userWithRole(UserRole::Doctor);
    $this->patient = Patient::factory()->create();
    $this->staff = userWithRole(UserRole::ClinicAdmin);
});

function book(array $overrides = [])
{
    return test()->actingAs(test()->staff)->post(route('appointments.store'), appointmentPayload([
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        ...$overrides,
    ]));
}

test('staff can book an appointment', function () {
    $response = book();

    $appointment = Appointment::sole();

    $response->assertRedirect(route('appointments.show', $appointment))->assertSessionHasNoErrors();

    expect($appointment->patient_id)->toBe($this->patient->id)
        ->and($appointment->doctor_id)->toBe($this->doctor->id)
        ->and($appointment->start_time->toDateTimeString())->toBe('2026-11-02 10:00:00')
        ->and($appointment->end_time->toDateTimeString())->toBe('2026-11-02 10:30:00')
        ->and($appointment->status)->toBe(AppointmentStatus::Scheduled)
        ->and($appointment->reason)->toBe('Annual review')
        ->and($appointment->created_by)->toBe($this->staff->id);
});

test('times are entered in the clinic timezone and stored in UTC', function () {
    config(['clinic.timezone' => 'Asia/Kolkata']);

    book(['start_time' => '15:30', 'end_time' => '16:00'])->assertSessionHasNoErrors();

    $appointment = Appointment::sole();

    expect($appointment->start_time->toDateTimeString())->toBe('2026-11-02 10:00:00');

    $this->get(route('appointments.show', $appointment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appointment.start', '15:30')
            ->where('appointment.end', '16:00')
            ->where('appointment.date', '2026-11-02'));
});

test('invalid bookings are rejected', function (array $overrides, string $field) {
    book($overrides)->assertSessionHasErrors($field);

    expect(Appointment::count())->toBe(0);
})->with([
    'missing patient' => [['patient_id' => null], 'patient_id'],
    'unknown patient' => [['patient_id' => 999999], 'patient_id'],
    'missing clinician' => [['doctor_id' => null], 'doctor_id'],
    'bad date' => [['date' => '02/11/2026'], 'date'],
    'bad time' => [['start_time' => '10am'], 'start_time'],
    'end before start' => [['start_time' => '11:00', 'end_time' => '10:30'], 'end_time'],
    'zero length' => [['end_time' => '10:00'], 'end_time'],
    'over four hours' => [['end_time' => '14:30'], 'end_time'],
    'off the 5-minute grid' => [['start_time' => '10:03', 'end_time' => '10:33'], 'start_time'],
    'in the past' => [['date' => '2026-10-31'], 'start_time'],
    'reason too long' => [['reason' => str_repeat('a', 501)], 'reason'],
]);

test('only doctors and practitioners can run appointments', function (UserRole $role) {
    book(['doctor_id' => userWithRole($role)->id])
        ->assertSessionHasErrors(['doctor_id' => 'Choose a doctor or practitioner.']);
})->with([
    'clinic admin' => UserRole::ClinicAdmin,
    'lab staff' => UserRole::LabStaff,
    'patient' => UserRole::Patient,
]);

test('practitioners can run appointments', function () {
    book(['doctor_id' => userWithRole(UserRole::Practitioner)->id])->assertSessionHasNoErrors();
});

test('archived patients cannot be booked', function () {
    $this->patient->delete();

    book()->assertSessionHasErrors(['patient_id' => 'Choose an active patient.']);
});

test('the patient and status cannot be smuggled into a booking', function () {
    book(['status' => 'completed', 'created_by' => 999, 'cancelled_by' => 999])->assertSessionHasNoErrors();

    $appointment = Appointment::sole();

    expect($appointment->status)->toBe(AppointmentStatus::Scheduled)
        ->and($appointment->created_by)->toBe($this->staff->id)
        ->and($appointment->cancelled_by)->toBeNull();
});

describe('double-booking', function () {
    beforeEach(function () {
        $this->existing = Appointment::factory()
            ->for($this->doctor, 'doctor')
            ->at('2026-11-02 10:00', 60)
            ->create();
    });

    test('a clinician cannot be double-booked', function () {
        book(['start_time' => '10:30', 'end_time' => '11:30'])
            ->assertSessionHasErrors(['doctor_id' => 'The clinician already has an appointment from 10:00 to 11:00 on Mon 2 Nov 2026.']);

        expect(Appointment::count())->toBe(1);
    });

    test('a patient cannot be double-booked with another clinician', function () {
        Appointment::factory()->for($this->patient)->at('2026-11-02 14:00')->create();

        book([
            'doctor_id' => userWithRole(UserRole::Doctor)->id,
            'start_time' => '14:15',
            'end_time' => '14:45',
        ])->assertSessionHasErrors(['start_time' => 'The patient already has an appointment from 14:00 to 14:30 on Mon 2 Nov 2026.']);
    });

    test('back-to-back appointments are allowed', function () {
        book(['start_time' => '11:00', 'end_time' => '11:30'])->assertSessionHasNoErrors();
        book(['start_time' => '09:30', 'end_time' => '10:00'])->assertSessionHasNoErrors();

        expect(Appointment::count())->toBe(3);
    });

    test('a cancelled appointment frees its slot', function () {
        $this->existing->cancel(null);

        book()->assertSessionHasNoErrors();
    });

    test('the clash message never names the other patient', function () {
        $other = $this->existing->patient;

        $errors = book()->assertSessionHasErrors('doctor_id')->getSession()->get('errors');

        expect($errors->first('doctor_id'))
            ->not->toContain($other->first_name)
            ->not->toContain($other->last_name);
    });

    test('the scheduler re-checks for clashes when saving', function () {
        // As if a second request passed validation before the first one saved.
        expect(fn () => app(AppointmentScheduler::class)->book($this->patient->id, [
            'doctor_id' => $this->doctor->id,
            'start_time' => Carbon::parse('2026-11-02 10:15'),
            'end_time' => Carbon::parse('2026-11-02 10:45'),
        ], $this->staff))->toThrow(ValidationException::class);

        expect(Appointment::count())->toBe(1);
    });
});

describe('rescheduling', function () {
    beforeEach(function () {
        $this->appointment = Appointment::factory()
            ->for($this->patient)
            ->for($this->doctor, 'doctor')
            ->at('2026-11-02 10:00', 60)
            ->create(['reason' => 'Original reason']);
    });

    function reschedule(array $overrides = [])
    {
        return test()->actingAs(test()->staff)->put(route('appointments.update', test()->appointment), appointmentPayload([
            'doctor_id' => test()->doctor->id,
            ...$overrides,
        ]));
    }

    test('staff can move an appointment', function () {
        reschedule(['start_time' => '10:30', 'end_time' => '11:00'])
            ->assertRedirect(route('appointments.show', $this->appointment))
            ->assertSessionHasNoErrors();

        expect($this->appointment->fresh()->start_time->toDateTimeString())->toBe('2026-11-02 10:30:00');
    });

    test('an appointment does not clash with its own old slot', function () {
        reschedule(['start_time' => '10:30', 'end_time' => '11:30'])->assertSessionHasNoErrors();
    });

    test('moving into another booking is rejected', function () {
        Appointment::factory()->for($this->doctor, 'doctor')->at('2026-11-02 12:00')->create();

        reschedule(['start_time' => '12:15', 'end_time' => '12:45'])->assertSessionHasErrors('doctor_id');

        expect($this->appointment->fresh()->start_time->toDateTimeString())->toBe('2026-11-02 10:00:00');
    });

    test('the patient cannot be changed', function () {
        $other = Patient::factory()->create();

        reschedule(['patient_id' => $other->id])->assertSessionHasNoErrors();

        expect($this->appointment->fresh()->patient_id)->toBe($this->patient->id);
    });

    test('leaving out the reason keeps it', function () {
        $payload = appointmentPayload(['doctor_id' => $this->doctor->id]);
        unset($payload['reason']);

        $this->actingAs($this->staff)->put(route('appointments.update', $this->appointment), $payload);

        expect($this->appointment->fresh()->reason)->toBe('Original reason');
    });

    test('completed and cancelled appointments cannot be rescheduled', function (string $state) {
        $appointment = Appointment::factory()->{$state}()->at('2026-11-03 10:00')->create();

        $this->actingAs($this->staff)
            ->put(route('appointments.update', $appointment), appointmentPayload(['doctor_id' => $appointment->doctor_id]))
            ->assertSessionHasErrors('status');

        $this->get(route('appointments.edit', $appointment))->assertRedirect(route('appointments.show', $appointment));
    })->with(['completed', 'cancelled']);
});

test('the booking form offers only clinicians', function () {
    $practitioner = userWithRole(UserRole::Practitioner);
    userWithRole(UserRole::ClinicAdmin);

    $this->actingAs($this->staff)
        ->get(route('appointments.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('appointments/Create')
            ->has('doctors', 2)
            ->where('doctors', fn ($doctors) => collect($doctors)->pluck('id')->sort()->values()->all()
                === collect([$this->doctor->id, $practitioner->id])->sort()->values()->all()));
});

test('the booking form can start with a patient chosen', function () {
    $this->actingAs($this->staff)
        ->get(route('appointments.create', ['patient_id' => $this->patient->id]))
        ->assertInertia(fn (Assert $page) => $page->where('patient.id', $this->patient->id));
});

test('patients are searched for booking with a partial reload', function () {
    Patient::factory()->create(['last_name' => 'Lovelace']);

    $this->actingAs($this->staff)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'appointments/Create',
            'X-Inertia-Partial-Data' => 'patientOptions',
        ])
        ->get(route('appointments.create', ['patient_search' => 'Love']))
        ->assertOk()
        ->assertJsonCount(1, 'props.patientOptions')
        ->assertJsonMissingPath('props.doctors');

    expect(auditEntries(AuditEvent::PatientSearched)->sole()->metadata)
        ->toBe(['context' => 'appointment_booking', 'search' => 'Love', 'results' => 1]);
});

test('the schedule shows a date range and filters', function () {
    $mine = Appointment::factory()->for($this->doctor, 'doctor')->at('2026-11-02 10:00')->create();
    Appointment::factory()->at('2026-11-03 10:00')->create();
    Appointment::factory()->at('2026-11-20 10:00')->create();

    $this->actingAs($this->staff)
        ->get(route('appointments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-11-01')
            ->where('filters.to', '2026-11-07')
            ->has('appointments.data', 2));

    $this->get(route('appointments.index', ['doctor_id' => $this->doctor->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.id', $mine->id));
});

test('the schedule range is limited', function () {
    $this->actingAs($this->staff)
        ->get(route('appointments.index', ['from' => '2026-11-01', 'to' => '2026-12-31']))
        ->assertSessionHasErrors('to');
});

test('new clinicians appear in the booking form', function () {
    $new = User::factory()->withRole(UserRole::Doctor)->create(['name' => 'Dr New']);

    $this->actingAs($this->staff)
        ->get(route('appointments.create'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'doctors',
            fn ($doctors) => collect($doctors)->contains('id', $new->id),
        ));
});
