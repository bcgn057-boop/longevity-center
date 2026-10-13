<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    $this->officer = userWithRole(UserRole::ComplianceOfficer);
    $this->doctor = userWithRole(UserRole::Doctor);
});

describe('access', function () {
    test('practice analytics are open to compliance officers and admins', function (UserRole $role, bool $staffActivity) {
        $this->actingAs(userWithRole($role))
            ->get(route('compliance.analytics'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/Analytics')
                ->where('can.viewStaffActivity', $staffActivity));
    })->with([
        'compliance officer' => [UserRole::ComplianceOfficer, true],
        'super admin' => [UserRole::SuperAdmin, true],
        'clinic admin' => [UserRole::ClinicAdmin, false],
    ]);

    test('staff activity is for compliance officers and super admins only', function () {
        $this->actingAs(userWithRole(UserRole::SuperAdmin))->get(route('compliance.staff-activity'))->assertOk();
        $this->actingAs(userWithRole(UserRole::ClinicAdmin))->get(route('compliance.staff-activity'))->assertForbidden();
    });

    test('clinical staff and patients are refused both reports', function (UserRole $role) {
        $this->actingAs(userWithRole($role));

        $this->get(route('compliance.analytics'))->assertForbidden();
        $this->get(route('compliance.staff-activity'))->assertForbidden();
        expect(auditEntries(AuditEvent::AnalyticsViewed))->toHaveCount(0);
    })->with(['doctor' => UserRole::Doctor, 'lab staff' => UserRole::LabStaff, 'patient' => UserRole::Patient]);

    test('viewing a report is audited with its period', function () {
        $this->actingAs($this->officer)->get(route('compliance.staff-activity', ['from' => '2026-09-01', 'to' => '2026-09-30']));

        expect(auditEntries(AuditEvent::AnalyticsViewed)->sole())
            ->user_id->toBe($this->officer->id)
            ->metadata->toBe(['report' => 'staff_activity', 'from' => '2026-09-01', 'to' => '2026-09-30']);
    });

    test('periods default to the last 30 days and are limited to a year', function () {
        $this->actingAs($this->officer)
            ->get(route('compliance.analytics'))
            ->assertInertia(fn (Assert $page) => $page->where('period', ['from' => '2026-09-13', 'to' => '2026-10-12', 'days' => 30]));

        $this->get(route('compliance.analytics', ['from' => '2025-01-01', 'to' => '2026-10-12']))->assertSessionHasErrors('to');
        $this->get(route('compliance.analytics', ['from' => '2026-10-12', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
    });
});

describe('practice metrics', function () {
    test('counts patients, appointments, prescriptions and lab work for the period', function () {
        $patients = Patient::factory()->count(3)->create();
        Patient::factory()->withAccount(userWithRole(UserRole::Patient))->create();
        Patient::factory()->create()->delete();

        Appointment::factory()->for($patients[0])->create(['start_time' => '2026-10-05 09:00:00', 'end_time' => '2026-10-05 09:30:00', 'status' => AppointmentStatus::Completed]);
        Appointment::factory()->for($patients[1])->create(['start_time' => '2026-10-06 09:00:00', 'end_time' => '2026-10-06 09:30:00', 'status' => AppointmentStatus::Cancelled]);
        Appointment::factory()->for($patients[1])->create(['start_time' => '2026-08-01 09:00:00', 'end_time' => '2026-08-01 09:30:00']);

        Prescription::factory()->for($patients[0])->create(['starts_on' => '2026-10-01']);
        Prescription::factory()->for($patients[0])->create(['starts_on' => '2026-10-01']);
        Prescription::factory()->for($patients[1])->discontinued()->create(['starts_on' => '2026-10-01']);

        $this->travelTo(Carbon::parse('2026-10-10 08:00:00', 'UTC'));
        $released = LabReport::factory()->for($patients[2])->create();
        $this->travelTo(Carbon::parse('2026-10-10 14:00:00', 'UTC'));
        $released->release($this->doctor);
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
        LabReport::factory()->for($patients[2])->create();

        $this->actingAs($this->officer)
            ->get(route('compliance.analytics', ['from' => '2026-10-01', 'to' => '2026-10-12']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.patients', ['active' => 4, 'with_portal' => 1, 'new' => 5, 'archived' => 1])
                ->where('metrics.appointments.total', 2)
                ->where('metrics.appointments.by_status.1', ['status' => 'completed', 'label' => 'Completed', 'count' => 1])
                ->where('metrics.prescriptions', ['in_effect' => 2, 'patients_on_protocol' => 1, 'issued' => 3])
                ->where('metrics.labs', ['pending_review' => 1, 'released' => 1, 'average_review_hours' => 6]));
    });

    test('adherence counts ticks against scheduled days for daily items, without naming patients', function () {
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $patient = Patient::factory()->create(['first_name' => 'Zebedee']);
        $prescription = Prescription::factory()->for($patient)->create(['starts_on' => '2026-10-01']);
        $daily = ProtocolItem::factory()->for($prescription)->create(['frequency' => 'daily']);
        ProtocolItem::factory()->for($prescription)->create(['frequency' => 'weekly']);
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));

        foreach (['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'] as $day) {
            $daily->recordAdherence($day, true, 'Private note');
        }
        $daily->recordAdherence('2026-10-11', false, null);

        $response = $this->actingAs($this->officer)
            ->get(route('compliance.analytics', ['from' => '2026-10-03', 'to' => '2026-10-12']));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('metrics.adherence.taken', 5)
            ->where('metrics.adherence.scheduled', 10)
            ->where('metrics.adherence.rate', 50)
            ->where('metrics.adherence.patients', 1)
            // One patient: too few to show which group they are in.
            ->where('metrics.adherence.groups.1', ['label' => '50–79%', 'patients' => null]));

        expect($response->getContent())->not->toContain('Zebedee')->not->toContain('Private note');
    });
});

describe('staff activity', function () {
    test('counts each staff member\'s actions in the period, without patient details', function () {
        $audit = app(AuditLogger::class);
        $patient = Patient::factory()->create(['first_name' => 'Zebedee']);
        $practitioner = userWithRole(UserRole::Practitioner);

        $audit->record(AuditEvent::PatientViewed, actor: $this->doctor, auditable: $patient);
        $audit->record(AuditEvent::PatientViewed, actor: $this->doctor, auditable: $patient);
        $audit->record(AuditEvent::PatientSearched, actor: $this->doctor, metadata: ['search' => 'Zebedee']);
        $audit->record(AuditEvent::PrescriptionIssued, actor: $this->doctor, metadata: ['patient_id' => $patient->id]);
        $audit->record(AuditEvent::Login, actor: $practitioner);
        $this->travelTo(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        $audit->record(AuditEvent::PatientViewed, actor: $practitioner, auditable: $patient);
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));

        $response = $this->actingAs($this->officer)->get(route('compliance.staff-activity'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('compliance/StaffActivity')
            ->where('staff.0.id', $this->doctor->id)
            ->where('staff.0.counts.phi_access', 3)
            ->where('staff.0.counts.patient_views', 2)
            ->where('staff.0.counts.patient_searches', 1)
            ->where('staff.0.counts.prescriptions_issued', 1)
            ->where('staff', fn ($staff) => collect($staff)->firstWhere('id', $practitioner->id)['counts']['phi_access'] === 0
                && collect($staff)->firstWhere('id', $practitioner->id)['counts']['sign_ins'] === 1));

        expect($response->getContent())->not->toContain('Zebedee');
    });

    test('patients are not listed', function () {
        $patientAccount = userWithRole(UserRole::Patient);

        $this->actingAs($this->officer)
            ->get(route('compliance.staff-activity'))
            ->assertInertia(fn (Assert $page) => $page->where('staff', fn ($staff) => ! collect($staff)->pluck('id')->contains($patientAccount->id)));
    });
});
