<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
});

/**
 * The dashboard's sections and actions for the user, keyed for easy checks.
 *
 * @return array{sections: array<string, array<string, array<string, mixed>>>, actions: array<int, string>, page: array<string, mixed>}
 */
function dashboardFor(User $user): array
{
    $page = test()->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page');
    $props = $page['props'];

    return [
        'sections' => collect($props['sections'])->mapWithKeys(fn (array $section) => [
            $section['title'] => collect($section['cards'])->keyBy('key')->all(),
        ])->all(),
        'actions' => array_column($props['actions'], 'key'),
        'page' => $props,
    ];
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('an account without a role sees a notice and nothing else', function () {
    $dashboard = dashboardFor(User::factory()->withoutRole()->create());

    expect($dashboard['sections'])->toBe([])
        ->and($dashboard['actions'])->toBe([])
        ->and($dashboard['page']['notice'])->toContain('role');
});

test('every role gets a dashboard that only links to pages it can open', function (UserRole $role) {
    $user = userWithRole($role);
    $props = $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

    $links = collect($props['actions'])->reject->download->pluck('href')
        ->merge(collect($props['sections'])->flatMap(fn (array $section) => array_column($section['cards'], 'href'))->filter());

    expect($props['notice'])->toBeNull();

    foreach ($links as $href) {
        $this->get($href)->assertOk();
    }
})->with(UserRole::cases());

describe('clinicians', function () {
    test('see their own day and the clinic, as counts', function () {
        $doctor = userWithRole(UserRole::Doctor);
        $patient = Patient::factory()->create(['first_name' => 'Zebedee']);
        Patient::factory()->create();

        Appointment::factory()->for($patient)->create(['doctor_id' => $doctor->id, 'start_time' => '2026-10-12 14:00:00', 'end_time' => '2026-10-12 14:30:00']);
        Appointment::factory()->for($patient)->create(['doctor_id' => $doctor->id, 'start_time' => '2026-10-12 08:00:00', 'end_time' => '2026-10-12 08:30:00', 'status' => AppointmentStatus::Completed]);
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-12 15:00:00', 'end_time' => '2026-10-12 15:30:00']);
        Appointment::factory()->for($patient)->create(['doctor_id' => $doctor->id, 'start_time' => '2026-10-13 09:00:00', 'end_time' => '2026-10-13 09:30:00']);
        LabReport::factory()->count(2)->create();
        Prescription::factory()->for($patient)->create(['starts_on' => '2026-10-01']);

        $dashboard = dashboardFor($doctor);
        $day = $dashboard['sections']['Your day'];
        $clinic = $dashboard['sections']['Clinic today'];

        expect($day['appointments']['value'])->toBe(1)
            ->and($day['appointments']['detail'])->toBe('1 completed so far')
            ->and($day['labs']['value'])->toBe(2)
            ->and($clinic['patients']['value'])->toBe(4) // includes the two from the lab reports
            ->and($clinic['appointments']['value'])->toBe(2)
            ->and($clinic['protocols']['value'])->toBe(1)
            ->and($clinic['protocols']['href'])->toBeNull()
            ->and($dashboard['actions'])->toContain('patients', 'book', 'labs')
            ->not->toContain('audit', 'analytics')
            ->and(json_encode($dashboard['page']))->not->toContain('Zebedee');
    });

    test("today follows the clinic's timezone", function () {
        config(['clinic.timezone' => 'Asia/Kolkata']);
        $doctor = userWithRole(UserRole::Doctor);
        // 2026-10-13 01:00 in Kolkata: tomorrow there, still today in UTC.
        Appointment::factory()->create(['doctor_id' => $doctor->id, 'start_time' => '2026-10-12 19:30:00', 'end_time' => '2026-10-12 20:00:00']);

        expect(dashboardFor($doctor)['sections']['Your day']['appointments']['value'])->toBe(0);
    });
});

test('lab staff see their own lab\'s queue, never other labs\'', function () {
    $lab = userWithRole(UserRole::LabStaff);
    $otherLab = LabPartner::factory()->create();
    LabReport::factory()->create(['entered_by' => $lab->id, 'lab_partner_id' => $lab->lab_partner_id]);
    LabReport::factory()->create(['lab_partner_id' => $lab->lab_partner_id]);
    LabReport::factory()->count(3)->create(['lab_partner_id' => $otherLab->id]);
    LabReport::factory()->create();
    requisitionFor(Patient::factory()->create(), $lab->labPartner);
    requisitionFor(Patient::factory()->create(), $otherLab);

    $dashboard = dashboardFor($lab);

    expect($dashboard['sections']['Lab work']['labs']['value'])->toBe(1)
        ->and($dashboard['sections']['Lab work']['review']['value'])->toBe(2)
        ->and($dashboard['sections']['Lab work']['requisitions']['value'])->toBe(1)
        ->and($dashboard['sections'])->not->toHaveKey('Clinic today')
        ->and($dashboard['actions'])->toBe(['lab_entry', 'labs']);
});

test('compliance officers see recent audit counts linked to the matching entries', function () {
    $officer = userWithRole(UserRole::ComplianceOfficer);
    $audit = app(AuditLogger::class);
    $audit->record(AuditEvent::PatientViewed, actor: $officer);
    $audit->record(AuditEvent::LoginFailed, actorEmail: 'someone@example.com');
    $audit->record(AuditEvent::AccessDenied, actor: $officer);
    $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'UTC'));
    $audit->record(AuditEvent::AccessDenied, actor: $officer);
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    $logged = AuditLog::count();

    $dashboard = dashboardFor($officer);
    $compliance = $dashboard['sections']['Compliance: last 7 days'];

    expect($compliance['phi']['value'])->toBe(1)
        ->and($compliance['denied']['value'])->toBe(1)
        ->and($compliance['sign_in']['value'])->toBe(1)
        ->and($compliance['denied']['href'])->toBe(route('compliance.audit-logs.index', ['category' => 'security', 'from' => '2026-10-06', 'to' => '2026-10-12']))
        ->and($dashboard['sections'])->not->toHaveKey('Clinic today')
        ->and($dashboard['actions'])->toBe(['analytics', 'staff', 'audit', 'export'])
        // Counts are not a view of the log's entries, so nothing is recorded.
        ->and(AuditLog::count())->toBe($logged);
});

test('clinic admins see the clinic and analytics, but not compliance', function () {
    $dashboard = dashboardFor(userWithRole(UserRole::ClinicAdmin));

    expect(array_keys($dashboard['sections']))->toBe(['Clinic today'])
        ->and($dashboard['sections']['Clinic today']['protocols']['href'])->toBe(route('compliance.analytics'))
        ->and($dashboard['actions'])->toContain('patients', 'appointments', 'analytics', 'admin')
        ->not->toContain('audit', 'export', 'labs');
});

test('super admins see the clinic and compliance, but no clinical queues', function () {
    $dashboard = dashboardFor(userWithRole(UserRole::SuperAdmin));

    expect(array_keys($dashboard['sections']))->toBe(['Clinic today', 'Compliance: last 7 days'])
        ->and($dashboard['actions'])->toContain('audit', 'export', 'staff', 'analytics')
        ->not->toContain('labs', 'lab_entry', 'protocol');
});

describe('patients', function () {
    test("see today's protocol items, next appointment and results", function () {
        $account = userWithRole(UserRole::Patient);
        $patient = Patient::factory()->withAccount($account)->create();
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'UTC'));
        $prescription = Prescription::factory()->for($patient)->create(['starts_on' => '2026-10-01']);
        $taken = ProtocolItem::factory()->for($prescription)->create();
        ProtocolItem::factory()->for($prescription)->create();
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
        $taken->recordAdherence('2026-10-12', true, null);
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-14 09:30:00', 'end_time' => '2026-10-14 10:00:00']);
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-01 09:30:00', 'end_time' => '2026-10-01 10:00:00']);
        LabReport::factory()->for($patient)->released()->create();
        LabReport::factory()->for($patient)->create();

        $dashboard = dashboardFor($account);
        $care = $dashboard['sections']['Your care'];

        expect($care['protocol']['value'])->toBe('1 of 2')
            ->and($care['appointments']['value'])->toBe(1)
            ->and($care['appointments']['detail'])->toBe('Next: Wed 14 Oct, 09:30')
            ->and($care['results']['value'])->toBe(1)
            ->and($dashboard['actions'])->toBe(['protocol', 'results', 'appointments']);
    });

    test('an unlinked account is told to contact the clinic', function () {
        $props = dashboardFor(userWithRole(UserRole::Patient))['page'];

        expect($props['sections'][0]['empty'])->toContain('not linked');
    });
});

describe('trend charts', function () {
    test('clinic staff see daily appointments and new patients for the last 7 days', function () {
        $patient = Patient::factory()->create();
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-12 09:00:00', 'end_time' => '2026-10-12 09:30:00']);
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-10 09:00:00', 'end_time' => '2026-10-10 09:30:00', 'status' => AppointmentStatus::Completed]);
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-10 11:00:00', 'end_time' => '2026-10-10 11:30:00', 'status' => AppointmentStatus::Cancelled]);
        Appointment::factory()->for($patient)->create(['start_time' => '2026-10-02 09:00:00', 'end_time' => '2026-10-02 09:30:00']);

        $section = collect(dashboardFor(userWithRole(UserRole::Doctor))['page']['sections'])->firstWhere('title', 'Clinic today');
        $charts = collect($section['charts'])->keyBy('key');

        expect(array_column($charts['appointments']['points'], 'date'))->toBe([
            '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12',
        ])
            ->and(array_column($charts['appointments']['points'], 'value'))->toBe([0, 0, 0, 0, 1, 0, 1])
            ->and($charts['appointments']['total'])->toBe(2)
            ->and($charts['patients']['points'][6]['value'])->toBe(1);
    });

    test('compliance officers see PHI access, failed sign-ins and denials by day', function () {
        $audit = app(AuditLogger::class);
        $officer = userWithRole(UserRole::ComplianceOfficer);
        $audit->record(AuditEvent::LabReportViewed, actor: $officer);
        $audit->record(AuditEvent::Lockout, actorEmail: 'x@example.com');
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'UTC'));
        $audit->record(AuditEvent::AccessDenied, actor: $officer);
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));

        $section = collect(dashboardFor($officer)['page']['sections'])->firstWhere('title', 'Compliance: last 7 days');
        $charts = collect($section['charts'])->keyBy('key');

        expect($charts->keys()->all())->toBe(['phi', 'sign_in', 'denied'])
            ->and($charts['phi']['points'][6]['value'])->toBe(1)
            ->and($charts['sign_in']['total'])->toBe(1)
            ->and($charts['denied']['points'][3])->toBe(['date' => '2026-10-09', 'value' => 1]);
    });

    test('roles without the underlying access get no charts', function (UserRole $role) {
        $charts = collect(dashboardFor(userWithRole($role))['page']['sections'])->pluck('charts')->filter()->all();

        expect($charts)->toBe([]);
    })->with(['lab staff' => UserRole::LabStaff, 'patient' => UserRole::Patient]);
});
