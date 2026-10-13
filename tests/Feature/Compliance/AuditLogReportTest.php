<?php

use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    $this->officer = userWithRole(UserRole::ComplianceOfficer);
    $this->doctor = userWithRole(UserRole::Doctor);
    $this->audit = app(AuditLogger::class);
});

/**
 * Parse a streamed CSV export into rows keyed by column.
 *
 * @return array<int, array<string, string>>
 */
function csvRows(string $csv): array
{
    $lines = array_map(fn (string $line) => str_getcsv($line, escape: ''), array_filter(explode("\n", $csv)));
    $header = array_shift($lines);

    return array_map(fn (array $line) => array_combine($header, $line), $lines);
}

describe('access', function () {
    test('compliance officers and super admins can read the audit log', function (UserRole $role) {
        $this->actingAs(userWithRole($role))
            ->get(route('compliance.audit-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/AuditLogs')
                ->where('can.export', true)
                ->has('categories', count(AuditCategory::cases())));
    })->with(['compliance officer' => UserRole::ComplianceOfficer, 'super admin' => UserRole::SuperAdmin]);

    test('everyone else is refused the log and its exports, and the attempt is audited', function (UserRole $role) {
        $user = userWithRole($role);
        $this->actingAs($user);

        $this->get(route('compliance.audit-logs.index'))->assertForbidden();
        $this->get(route('compliance.audit-logs.export', ['format' => 'csv']))->assertForbidden();

        expect(auditEntries(AuditEvent::AccessDenied))->toHaveCount(2)
            ->and(auditEntries(AuditEvent::AccessDenied)->pluck('user_id')->unique()->all())->toBe([$user->id])
            ->and(auditEntries(AuditEvent::AuditLogExported))->toHaveCount(0);
    })->with([
        'clinic admin' => UserRole::ClinicAdmin,
        'doctor' => UserRole::Doctor,
        'practitioner' => UserRole::Practitioner,
        'lab staff' => UserRole::LabStaff,
        'patient' => UserRole::Patient,
    ]);

    test('guests are sent to sign in', function () {
        $this->get(route('compliance.audit-logs.export', ['format' => 'csv']))->assertRedirect(route('login'));
    });

    test('the staff filter lists staff accounts, not patients', function () {
        $patient = userWithRole(UserRole::Patient);

        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staff', fn ($staff) => collect($staff)->pluck('id')->contains($this->doctor->id)
                    && ! collect($staff)->pluck('id')->contains($patient->id)));
    });
});

describe('filters', function () {
    test('entries can be filtered by category', function () {
        $this->audit->record(AuditEvent::Login, actor: $this->doctor);
        $this->audit->record(AuditEvent::PatientViewed, actor: $this->doctor);
        $this->audit->record(AuditEvent::LabReportViewed, actor: $this->doctor);

        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.index', ['category' => 'phi_access']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 2)
                ->where('logs.data.0.event', 'lab_report.viewed')
                ->where('logs.data.0.category', 'phi_access')
                ->where('filters', ['category' => 'phi_access']));
    });

    test("date ranges are whole days in the clinic's timezone", function () {
        config(['clinic.timezone' => 'Asia/Kolkata']);

        // 2026-10-09 23:00 in Kolkata, still the 9th there.
        $this->travelTo(Carbon::parse('2026-10-09 17:30:00', 'UTC'));
        $this->audit->record(AuditEvent::Login, actor: $this->doctor);
        // 2026-10-10 00:30 in Kolkata.
        $this->travelTo(Carbon::parse('2026-10-09 19:00:00', 'UTC'));
        $this->audit->record(AuditEvent::Logout, actor: $this->doctor);
        // 2026-10-11 05:30 in Kolkata.
        $this->travelTo(Carbon::parse('2026-10-11 00:00:00', 'UTC'));
        $this->audit->record(AuditEvent::Login, actor: $this->officer);
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));

        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.index', ['from' => '2026-10-10', 'to' => '2026-10-10']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.event', 'auth.logout'));
    });

    test('entries can be filtered by staff member', function () {
        $this->audit->record(AuditEvent::PatientViewed, actor: $this->doctor);
        $this->audit->record(AuditEvent::PatientViewed, actor: userWithRole(UserRole::Practitioner));

        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.index', ['user_id' => $this->doctor->id, 'category' => 'phi_access']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.user_id', $this->doctor->id));
    });

    test("the patient filter finds every entry about that patient's records", function () {
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();
        $this->actingAs($this->doctor);

        $report = LabReport::factory()->for($patient)->create(); // lab_report.created, with patient_id
        $prescription = Prescription::factory()->for($patient)->create(); // prescription.issued
        ProtocolItem::factory()->for($prescription)->create(); // protocol_item.added, by prescription only
        $this->audit->record(AuditEvent::PatientViewed, auditable: $patient);
        $this->audit->record(AuditEvent::LabReportViewed, auditable: $report);
        LabReport::factory()->for($other)->create();
        $this->audit->record(AuditEvent::PatientViewed, auditable: $other);

        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.index', ['patient_id' => $patient->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.data', fn ($logs) => collect($logs)->pluck('event')->sort()->values()->all() === [
                    'lab_report.created', 'lab_report.viewed', 'patient.created', 'patient.viewed',
                    'prescription.issued', 'protocol_item.added',
                ]));
    });

    test('invalid filters are rejected', function (array $query, string $field) {
        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.index', $query))
            ->assertSessionHasErrors($field);
    })->with([
        'unknown category' => [['category' => 'gossip'], 'category'],
        'bad date' => [['from' => '12/10/2026'], 'from'],
        'end before start' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'to'],
    ]);

    test('views are audited with the filters used', function () {
        $this->actingAs($this->officer)->get(route('compliance.audit-logs.index', ['category' => 'prescriptions', 'patient_id' => 7]));

        expect(auditEntries(AuditEvent::AuditLogViewed)->sole())
            ->user_id->toBe($this->officer->id)
            ->metadata->toBe(['filters' => ['category' => 'prescriptions', 'patient_id' => 7]]);
    });
});

describe('exports', function () {
    test('a CSV export has every matching entry, oldest first, with the hash chain', function () {
        $this->audit->record(AuditEvent::Login, actor: $this->doctor);
        $this->audit->record(AuditEvent::PatientViewed, actor: $this->doctor, metadata: ['context' => 'show']);
        $this->audit->record(AuditEvent::Login, actor: $this->officer);

        $response = $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.export', ['format' => 'csv', 'user_id' => $this->doctor->id]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=audit-log-20261012-100000.csv');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        $rows = csvRows($response->streamedContent());
        $stored = AuditLog::where('user_id', $this->doctor->id)->orderBy('id')->get();

        expect($rows)->toHaveCount(2)
            ->and(array_column($rows, 'event'))->toBe(['auth.login', 'patient.viewed'])
            ->and($rows[1]['category'])->toBe('phi_access')
            ->and($rows[1]['metadata'])->toBe('{"context":"show"}')
            ->and($rows[1]['hash'])->toBe($stored[1]->hash)
            ->and($rows[1]['previous_hash'])->toBe($stored[1]->previous_hash);
    });

    test('cells a spreadsheet would run as formulas are neutralised', function () {
        // The email on a failed login is whatever the visitor typed.
        $this->audit->record(AuditEvent::LoginFailed, actorEmail: '=HYPERLINK("http://evil.test","x")');

        $response = $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.export', ['format' => 'csv', 'event' => 'auth.login_failed']));

        expect(csvRows($response->streamedContent())[0]['actor_email'])->toBe('\'=HYPERLINK("http://evil.test","x")');
    });

    test('a JSON export describes itself and lists the entries', function () {
        $this->audit->record(AuditEvent::PrescriptionIssued, actor: $this->doctor, metadata: ['patient_id' => 3]);

        $response = $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.export', ['format' => 'json', 'category' => 'prescriptions']));

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $export = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($export)
            ->exported_by->toBe($this->officer->email)
            ->filters->toBe(['category' => 'prescriptions'])
            ->count->toBe(1)
            ->and($export['entries'][0])->toHaveKeys(['hash', 'previous_hash'])
            ->and($export['entries'][0]['event'])->toBe('prescription.issued')
            ->and($export['entries'][0]['metadata'])->toBe(['patient_id' => 3]);
    });

    test('exports are audited before they start, and stop at that point in the log', function () {
        $this->audit->record(AuditEvent::Login, actor: $this->doctor);
        $before = AuditLog::max('id');

        $response = $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.export', ['format' => 'json', 'from' => '2026-10-01']));
        $export = json_decode($response->streamedContent(), true);

        $entry = auditEntries(AuditEvent::AuditLogExported)->sole();

        expect($entry->user_id)->toBe($this->officer->id)
            ->and($entry->metadata)->toBe([
                'filters' => ['from' => '2026-10-01'],
                'format' => 'json',
                'entries' => $export['count'],
                'up_to_id' => $before,
            ])
            ->and(collect($export['entries'])->pluck('id')->max())->toBe($before)
            ->and(collect($export['entries'])->pluck('event'))->not->toContain('audit.exported');
    });

    test('an export needs a known format', function () {
        $this->actingAs($this->officer)
            ->get(route('compliance.audit-logs.export', ['format' => 'xlsx']))
            ->assertSessionHasErrors('format');

        expect(auditEntries(AuditEvent::AuditLogExported))->toHaveCount(0);
    });
});

test('every event belongs to a category', function () {
    $covered = collect(AuditCategory::cases())->flatMap(fn (AuditCategory $category) => AuditEvent::inCategory($category));

    expect($covered)->toHaveCount(count(AuditEvent::cases()))
        ->and(AuditEvent::PrescriptionListed->category())->toBe(AuditCategory::PhiAccess)
        ->and(AuditEvent::ProtocolItemAdjusted->category())->toBe(AuditCategory::Prescriptions)
        ->and(AuditEvent::AuditLogExported->category())->toBe(AuditCategory::Compliance);
});

test('compliance officers have no clinical access', function () {
    $this->actingAs($this->officer);

    $patient = Patient::factory()->create();

    $this->get(route('patients.show', $patient))->assertForbidden();
    $this->get(route('labs.index'))->assertForbidden();
    $this->get(route('patients.prescriptions.index', $patient))->assertForbidden();
    expect(User::find($this->officer->id)->can('viewPrescriptions', Prescription::class))->toBeFalse();
});
