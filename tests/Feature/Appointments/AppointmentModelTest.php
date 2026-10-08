<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Exceptions\InvalidAppointmentTransitionException;
use App\Exceptions\MedicalRecordRetentionException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('new appointments are scheduled and linked to the patient and clinician', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Scheduled)
        ->and($appointment->patient->appointments->modelKeys())->toBe([$appointment->id])
        ->and($appointment->doctor->clinicianAppointments->modelKeys())->toBe([$appointment->id]);
});

test('times are stored in UTC whatever timezone they are given in', function () {
    $appointment = Appointment::factory()->at(Carbon::parse('2026-11-02 09:00', 'Asia/Kolkata'))->create();

    $raw = DB::table('appointments')->where('id', $appointment->id)->first();

    expect($raw->start_time)->toBe('2026-11-02 03:30:00')
        ->and($raw->end_time)->toBe('2026-11-02 04:00:00')
        ->and($appointment->fresh()->start_time->toIso8601String())->toBe('2026-11-02T03:30:00+00:00');
});

test('reasons are encrypted at rest and hidden from serialization', function () {
    $appointment = Appointment::factory()->create(['reason' => 'Discuss thyroid results']);
    $appointment->cancel(null, 'Patient travelling abroad');

    $raw = DB::table('appointments')->where('id', $appointment->id)->first();

    expect($raw->reason)->not->toContain('thyroid')
        ->and(decrypt($raw->reason, false))->toBe('Discuss thyroid results')
        ->and($raw->cancellation_reason)->not->toContain('travelling')
        ->and($appointment->fresh()->toArray())->not->toHaveKeys(['reason', 'cancellation_reason']);
});

test('an appointment must end after it starts', function (int $minutes) {
    Appointment::factory()->at('2026-11-02 09:00', $minutes)->create();
})->with([
    'zero length' => 0,
    'negative length' => -30,
])->throws(InvalidArgumentException::class);

test('status cannot be mass assigned', function () {
    $appointment = Appointment::factory()->create();

    $appointment->fill(['status' => AppointmentStatus::Completed])->save();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

describe('overlap detection', function () {
    beforeEach(function () {
        $this->doctor = userWithRole(UserRole::Doctor);
        $this->existing = Appointment::factory()->for($this->doctor, 'doctor')->at('2026-11-02 10:00', 60)->create();
    });

    test('clashing time blocks are found', function (string $start, string $end) {
        expect(Appointment::forDoctor($this->doctor)->overlapping(Carbon::parse($start), Carbon::parse($end))->exists())
            ->toBeTrue();
    })->with([
        'same slot' => ['2026-11-02 10:00', '2026-11-02 11:00'],
        'starts during' => ['2026-11-02 10:30', '2026-11-02 11:30'],
        'ends during' => ['2026-11-02 09:30', '2026-11-02 10:15'],
        'inside' => ['2026-11-02 10:15', '2026-11-02 10:45'],
        'surrounds' => ['2026-11-02 09:00', '2026-11-02 12:00'],
    ]);

    test('back-to-back and separate blocks do not clash', function (string $start, string $end) {
        expect(Appointment::forDoctor($this->doctor)->overlapping(Carbon::parse($start), Carbon::parse($end))->exists())
            ->toBeFalse();
    })->with([
        'ends as it starts' => ['2026-11-02 09:00', '2026-11-02 10:00'],
        'starts as it ends' => ['2026-11-02 11:00', '2026-11-02 11:30'],
        'another day' => ['2026-11-03 10:00', '2026-11-03 11:00'],
    ]);

    test('times in another timezone are compared in UTC', function () {
        // 15:30 in India is 10:00 UTC.
        $start = Carbon::parse('2026-11-02 15:30', 'Asia/Kolkata');

        expect(Appointment::forDoctor($this->doctor)->overlapping($start, $start->copy()->addMinutes(30))->exists())
            ->toBeTrue();
    });

    test('cancelled appointments free their slot', function () {
        $this->existing->cancel(null);

        expect(Appointment::forDoctor($this->doctor)
            ->overlapping(Carbon::parse('2026-11-02 10:00'), Carbon::parse('2026-11-02 11:00'))
            ->exists())->toBeFalse();
    });

    test('an appointment does not clash with itself when rescheduled', function () {
        expect(Appointment::forDoctor($this->doctor)
            ->overlapping(Carbon::parse('2026-11-02 10:30'), Carbon::parse('2026-11-02 11:30'), $this->existing)
            ->exists())->toBeFalse();
    });

    test('other clinicians and patients are not affected', function () {
        $window = [Carbon::parse('2026-11-02 10:00'), Carbon::parse('2026-11-02 11:00')];

        expect(Appointment::forDoctor(userWithRole(UserRole::Doctor))->overlapping(...$window)->exists())->toBeFalse()
            ->and(Appointment::forPatient(Patient::factory()->create())->overlapping(...$window)->exists())->toBeFalse()
            ->and(Appointment::forPatient($this->existing->patient)->overlapping(...$window)->exists())->toBeTrue();
    });
});

describe('status transitions', function () {
    test('a scheduled appointment can be completed', function () {
        $appointment = Appointment::factory()->create();

        $appointment->complete();

        expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
    });

    test('cancelling records who cancelled, when and why', function () {
        $this->freezeSecond();
        $admin = userWithRole(UserRole::ClinicAdmin);
        $appointment = Appointment::factory()->create();

        $appointment->cancel($admin, 'Clinician unavailable');

        $appointment = $appointment->fresh();
        expect($appointment->status)->toBe(AppointmentStatus::Cancelled)
            ->and($appointment->cancelled_by)->toBe($admin->id)
            ->and($appointment->cancelled_at->equalTo(now()))->toBeTrue()
            ->and($appointment->cancellation_reason)->toBe('Clinician unavailable');
    });

    test('completed and cancelled appointments are final', function (string $state, Closure $change) {
        $appointment = Appointment::factory()->{$state}()->create();

        $change($appointment);
    })->with([
        'complete a cancelled one' => ['cancelled', fn (Appointment $a) => $a->complete()],
        'cancel a completed one' => ['completed', fn (Appointment $a) => $a->cancel(null)],
        'reschedule a completed one' => ['completed', fn (Appointment $a) => $a->update(['end_time' => $a->end_time->addHour()])],
        'edit a cancelled one' => ['cancelled', fn (Appointment $a) => $a->update(['reason' => 'Changed'])],
    ])->throws(InvalidAppointmentTransitionException::class);

    test('setting the unchanged status is not treated as a transition', function () {
        $appointment = Appointment::factory()->create();
        $appointment->status = AppointmentStatus::Scheduled;
        $appointment->reason = 'Unchanged status is fine';
        $appointment->save();

        expect($appointment->fresh()->reason)->toBe('Unchanged status is fine');
    });
});

describe('retention', function () {
    test('appointments cannot be deleted', function () {
        $appointment = Appointment::factory()->create();

        expect(fn () => $appointment->delete())->toThrow(MedicalRecordRetentionException::class)
            ->and(Appointment::count())->toBe(1);
    });

    test('a patient with appointments cannot be permanently removed at the database level', function () {
        $appointment = Appointment::factory()->create();

        expect(fn () => DB::table('patients')->where('id', $appointment->patient_id)->delete())
            ->toThrow(QueryException::class);
    });
});

describe('auditing', function () {
    test('creating an appointment is audited', function () {
        $appointment = Appointment::factory()->create();

        $entry = auditEntries(AuditEvent::AppointmentCreated)->sole();

        expect($entry->auditable_id)->toBe($appointment->id)
            ->and($entry->metadata)->toBe([
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'status' => 'scheduled',
            ]);
    });

    test('moving an appointment is audited as a reschedule', function () {
        $appointment = Appointment::factory()->at('2026-11-02 10:00')->create();

        $appointment->update(['start_time' => '2026-11-02 11:00', 'end_time' => '2026-11-02 11:30']);

        expect(auditEntries(AuditEvent::AppointmentRescheduled)->sole()->metadata)
            ->toBe(['fields' => ['start_time', 'end_time']]);
    });

    test('other edits are audited by field name', function () {
        $appointment = Appointment::factory()->create();

        $appointment->update(['reason' => 'New reason']);

        expect(auditEntries(AuditEvent::AppointmentUpdated)->sole()->metadata)->toBe(['fields' => ['reason']])
            ->and(auditEntries(AuditEvent::AppointmentRescheduled))->toHaveCount(0);
    });

    test('status changes are audited with the old and new status', function () {
        $completed = Appointment::factory()->create();
        $cancelled = Appointment::factory()->create();

        $completed->complete();
        $cancelled->cancel(null, 'Unwell');

        $completion = auditEntries(AuditEvent::AppointmentCompleted)->sole();
        $cancellation = auditEntries(AuditEvent::AppointmentCancelled)->sole();

        expect($completion->auditable_id)->toBe($completed->id)
            ->and($completion->old_values)->toBe(['status' => 'scheduled'])
            ->and($completion->new_values)->toBe(['status' => 'completed'])
            ->and($cancellation->auditable_id)->toBe($cancelled->id)
            ->and($cancellation->new_values)->toBe(['status' => 'cancelled']);
    });

    test('saving without changes does not create an entry', function () {
        $appointment = Appointment::factory()->create();

        $appointment->save();

        expect(auditEntries(AuditEvent::AppointmentUpdated))->toHaveCount(0);
    });

    test('the audit log never contains appointment reasons', function () {
        $appointment = Appointment::factory()->create(['reason' => 'Erectile dysfunction review']);
        $appointment->update(['reason' => 'Fertility counselling']);
        $appointment->cancel(null, 'Bereavement in family');

        expect(json_encode(AuditLog::query()->toBase()->get()))
            ->not->toContain('Erectile')
            ->not->toContain('Fertility')
            ->not->toContain('Bereavement');
    });

    test('the audit chain stays valid through appointment activity', function () {
        $appointment = Appointment::factory()->create();
        $appointment->update(['start_time' => $appointment->start_time->addHour(), 'end_time' => $appointment->end_time->addHour()]);
        $appointment->complete();

        $this->artisan('audit:verify')->assertSuccessful();
    });
});
