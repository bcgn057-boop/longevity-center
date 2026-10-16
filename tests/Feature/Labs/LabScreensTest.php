<?php

use App\Enums\AuditEvent;
use App\Enums\LabReportStatus;
use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Labs\LabReportService;
use App\Models\AuditLog;
use App\Models\Biomarker;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\Patient;
use Database\Seeders\BiomarkerSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'UTC'));
    $this->seed(BiomarkerSeeder::class);
    $this->apob = Biomarker::where('code', 'apob')->sole();
    $this->hba1c = Biomarker::where('code', 'hba1c')->sole();
    $this->patient = Patient::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $this->partner = LabPartner::factory()->create();
    $this->labStaff = userWithRole(UserRole::LabStaff, $this->partner);
    $this->requisition = requisitionFor($this->patient, $this->partner);
    $this->doctor = userWithRole(UserRole::Doctor);
});

function labPayload(array $overrides = []): array
{
    return [
        'patient_id' => test()->patient->id,
        'lab_requisition_id' => test()->requisition->id,
        'collected_date' => '2026-10-30',
        'collected_time' => '08:15',
        'lab_name' => 'Quest Diagnostics',
        'notes' => 'Not fasting',
        'results' => [
            ['biomarker_id' => test()->apob->id, 'value' => '98.5', 'unit' => 'mg/dL', 'reference_low' => '', 'reference_high' => '90', 'comment' => ''],
            ['biomarker_id' => test()->hba1c->id, 'value' => '5.4', 'unit' => '%', 'reference_low' => '4', 'reference_high' => '5.6', 'comment' => 'Repeat in 3 months'],
        ],
        ...$overrides,
    ];
}

function enterReport(): LabReport
{
    test()->actingAs(test()->labStaff)->post(route('labs.store'), labPayload())->assertSessionHasNoErrors();

    return LabReport::sole();
}

describe('access', function () {
    test('lab staff and clinicians can use the lab screens', function (UserRole $role) {
        $report = LabReport::factory()->create(['lab_partner_id' => $this->partner->id]);
        $this->actingAs(userWithRole($role, $this->partner));

        $this->get(route('labs.index'))->assertOk();
        $this->get(route('labs.create'))->assertOk();
        $this->get(route('labs.show', $report))->assertOk();
    })->with([
        'lab staff' => UserRole::LabStaff,
        'doctor' => UserRole::Doctor,
        'practitioner' => UserRole::Practitioner,
    ]);

    test('everyone else is refused, whether or not the report exists', function (UserRole $role) {
        $report = LabReport::factory()->create();
        $result = LabResult::factory()->for($report, 'report')->for($this->apob)->create();
        $this->actingAs(userWithRole($role));

        $this->get(route('labs.index'))->assertForbidden();
        $this->get(route('labs.create'))->assertForbidden();
        $this->post(route('labs.store'), labPayload())->assertForbidden();
        $this->get(route('labs.show', $report))->assertForbidden();
        $this->get(route('labs.show', 999999))->assertForbidden();
        $this->post(route('labs.results.store', $report), ['results' => []])->assertForbidden();
        $this->post(route('labs.release', $report))->assertForbidden();
        $this->post(route('lab-results.corrections.store', $result), ['value' => '1', 'unit' => 'mg/dL', 'reason' => 'x'])->assertForbidden();

        expect(LabReport::count())->toBe(1);
    })->with([
        'super admin' => UserRole::SuperAdmin,
        'clinic admin' => UserRole::ClinicAdmin,
        'patient' => UserRole::Patient,
    ]);

    test('lab staff cannot open patient profiles', function () {
        $this->actingAs($this->labStaff)->get(route('patients.show', $this->patient))->assertForbidden();
    });

    test('values are never sent to the super admin through the patient page', function () {
        $report = enterReport();

        $response = $this->actingAs(userWithRole(UserRole::SuperAdmin))->get(route('patients.show', $this->patient));

        $response->assertInertia(fn (Assert $page) => $page->where('labReports', null));
        expect($response->getContent())->not->toContain('98.5');
    });

    test('doctors see the patient lab reports list without values', function () {
        enterReport();

        $response = $this->actingAs($this->doctor)->get(route('patients.show', $this->patient));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('labReports', 1)
            ->where('labReports.0.results_count', 2)
            ->where('can.addLabReport', true));
        expect($response->getContent())->not->toContain('98.5');
    });
});

describe('entering a report', function () {
    test('a report and its results are saved together, pending review', function () {
        config(['clinic.timezone' => 'Asia/Kolkata']);

        $this->actingAs($this->labStaff)
            ->post(route('labs.store'), labPayload())
            ->assertRedirect(route('labs.show', LabReport::sole()));

        $report = LabReport::sole();

        expect($report->status)->toBe(LabReportStatus::PendingReview)
            ->and($report->collected_at->toDateTimeString())->toBe('2026-10-30 02:45:00')
            ->and($report->entered_by)->toBe($this->labStaff->id)
            ->and($report->notes)->toBe('Not fasting')
            ->and($report->results()->count())->toBe(2);

        $hba1c = $report->results()->where('biomarker_id', $this->hba1c->id)->sole();

        expect($hba1c->value)->toBe('5.4')
            ->and($hba1c->reference_high)->toBe('5.6000')
            ->and($hba1c->comment)->toBe('Repeat in 3 months');
    });

    test('invalid entries are rejected and nothing is saved', function (Closure $overrides, string $field) {
        $this->actingAs($this->labStaff)
            ->post(route('labs.store'), labPayload($overrides()))
            ->assertSessionHasErrors($field);

        expect(LabReport::count())->toBe(0)
            ->and(LabResult::count())->toBe(0);
    })->with([
        'no results' => [fn () => ['results' => []], 'results'],
        'not a number' => [fn () => ['results' => [['biomarker_id' => test()->apob->id, 'value' => 'high', 'unit' => 'mg/dL']]], 'results.0.value'],
        'wrong unit' => [fn () => ['results' => [['biomarker_id' => test()->apob->id, 'value' => '90', 'unit' => 'mmol/L']]], 'results.0.unit'],
        'range upside down' => [fn () => ['results' => [['biomarker_id' => test()->apob->id, 'value' => '90', 'unit' => 'mg/dL', 'reference_low' => '100', 'reference_high' => '50']]], 'results.0.reference_high'],
        'same biomarker twice' => [fn () => ['results' => [
            ['biomarker_id' => test()->apob->id, 'value' => '90', 'unit' => 'mg/dL'],
            ['biomarker_id' => test()->apob->id, 'value' => '91', 'unit' => 'mg/dL'],
        ]], 'results.1.biomarker_id'],
        'unknown biomarker' => [fn () => ['results' => [['biomarker_id' => 999999, 'value' => '90', 'unit' => 'mg/dL']]], 'results.0.biomarker_id'],
        'collected in the future' => [fn () => ['collected_date' => '2026-11-02'], 'collected_time'],
        'no patient' => [fn () => ['patient_id' => null], 'patient_id'],
    ]);

    test('archived patients cannot get new reports', function () {
        $this->patient->delete();

        $this->actingAs($this->labStaff)
            ->post(route('labs.store'), labPayload())
            ->assertSessionHasErrors(['patient_id' => 'Choose an active patient.']);
    });

    test('a failure part-way through saves nothing', function () {
        expect(fn () => app(LabReportService::class)->create(
            $this->patient,
            now()->subDay(),
            null,
            null,
            [
                ['biomarker_id' => $this->apob->id, 'value' => '90', 'unit' => 'mg/dL'],
                ['biomarker_id' => $this->hba1c->id, 'value' => '5', 'unit' => 'mg/dL'],
            ],
            $this->labStaff,
        ))->toThrow(InvalidArgumentException::class);

        expect(LabReport::count())->toBe(0)
            ->and(LabResult::count())->toBe(0);
    });

    test('clinicians search patients by name only, and the search is audited', function () {
        $this->actingAs($this->doctor)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'labs/Create',
                'X-Inertia-Partial-Data' => 'patientOptions',
            ])
            ->get(route('labs.create', ['patient_search' => 'Love']))
            ->assertOk()
            ->assertJsonPath('props.patientOptions.0.full_name', 'Ada Lovelace')
            ->assertJsonMissingPath('props.patientOptions.0.date_of_birth');

        expect(auditEntries(AuditEvent::PatientSearched)->sole()->metadata)
            ->toBe(['context' => 'lab_entry', 'search' => 'Love', 'results' => 1]);
    });

    test('partner lab staff cannot search the patient list', function () {
        $this->actingAs($this->labStaff)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'labs/Create',
                'X-Inertia-Partial-Data' => 'patientOptions',
            ])
            ->get(route('labs.create', ['patient_search' => 'Love']))
            ->assertOk()
            ->assertJsonPath('props.patientOptions', []);

        expect(auditEntries(AuditEvent::PatientSearched))->toHaveCount(0);
    });
});

describe('reviewing', function () {
    test('the report page shows grouped results with flags against the lab range', function () {
        $report = enterReport();

        $this->actingAs($this->doctor)
            ->get(route('labs.show', $report))
            ->assertInertia(fn (Assert $page) => $page
                ->component('labs/Show')
                ->where('report.groups.0.label', 'Lipids & cardiovascular')
                ->where('report.groups.0.results.0.value', '98.5')
                ->where('report.groups.0.results.0.range', '≤ 90')
                ->where('report.groups.0.results.0.flag', 'high')
                ->where('report.groups.1.label', 'Metabolic')
                ->where('report.groups.1.results.0.range', '4–5.6')
                ->where('report.groups.1.results.0.flag', 'normal')
                ->where('can.release', true)
                ->where('can.addResults', true));
    });

    test('clinicians start on the review queue, oldest first', function () {
        $older = LabReport::factory()->create(['collected_at' => now()->subDays(5), 'lab_partner_id' => $this->partner->id]);
        $newer = LabReport::factory()->create(['collected_at' => now()->subDay(), 'lab_partner_id' => $this->partner->id]);
        LabReport::factory()->released()->create();

        $this->actingAs($this->doctor)
            ->get(route('labs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'pending_review')
                ->has('reports.data', 2)
                ->where('reports.data.0.id', $older->id)
                ->where('reports.data.1.id', $newer->id));

        $this->actingAs($this->labStaff)
            ->get(route('labs.index'))
            // Only their own lab's reports: the clinic lab's released one is not theirs.
            ->assertInertia(fn (Assert $page) => $page->where('filters.status', 'all')->has('reports.data', 2));
    });

    test('the list never includes values', function () {
        enterReport();

        expect($this->actingAs($this->doctor)->get(route('labs.index'))->getContent())->not->toContain('98.5');
    });

    test('results can be added while pending, but not for a biomarker already there', function () {
        $report = enterReport();
        $vitaminD = Biomarker::where('code', 'vitamin_d_25oh')->sole();

        $this->post(route('labs.results.store', $report), ['results' => [
            ['biomarker_id' => $this->apob->id, 'value' => '80', 'unit' => 'mg/dL'],
        ]])->assertSessionHasErrors(['results.0.biomarker_id' => 'This biomarker is already in the report. Correct it instead.']);

        $this->post(route('labs.results.store', $report), ['results' => [
            ['biomarker_id' => $vitaminD->id, 'value' => '32', 'unit' => 'ng/mL'],
        ]])->assertSessionHasNoErrors();

        expect($report->currentResults()->count())->toBe(3);
    });

    test('only clinicians can release, and an empty report cannot be released', function () {
        $report = enterReport();
        $empty = LabReport::factory()->create();

        $this->actingAs($this->labStaff)->post(route('labs.release', $report))->assertForbidden();
        $this->actingAs($this->doctor)->post(route('labs.release', $empty))->assertSessionHasErrors('release');
        $this->post(route('labs.release', $report))->assertRedirect(route('labs.show', $report));

        expect($report->fresh()->status)->toBe(LabReportStatus::Released)
            ->and($report->fresh()->reviewed_by)->toBe($this->doctor->id)
            ->and($empty->fresh()->status)->toBe(LabReportStatus::PendingReview);
    });

    test('after release nothing can be added and lab staff cannot correct', function () {
        $report = enterReport();
        $report->release($this->doctor);
        $result = $report->results()->first();

        $this->actingAs($this->labStaff)
            ->post(route('lab-results.corrections.store', $result), ['value' => '1', 'unit' => $result->unit, 'reason' => 'Typo'])
            ->assertForbidden();

        $this->actingAs($this->doctor)
            ->post(route('labs.results.store', $report), ['results' => [['biomarker_id' => Biomarker::where('code', 'tsh')->value('id'), 'value' => '2', 'unit' => 'mIU/L']]])
            ->assertForbidden();

        $this->get(route('labs.show', $report))
            ->assertInertia(fn (Assert $page) => $page->where('can.addResults', false)->where('can.release', false)->where('can.correct', true));
    });
});

describe('corrections', function () {
    test('a correction needs a reason and keeps the history', function () {
        $report = enterReport();
        $original = $report->results()->where('biomarker_id', $this->apob->id)->sole();

        $this->actingAs($this->doctor)
            ->post(route('lab-results.corrections.store', $original), ['value' => '89.5', 'unit' => 'mg/dL', 'reference_high' => '90'])
            ->assertSessionHasErrors(['reason' => 'Give the reason for the correction.']);

        $this->post(route('lab-results.corrections.store', $original), [
            'value' => '89.5', 'unit' => 'mg/dL', 'reference_high' => '90', 'reason' => 'Transcription error',
        ])->assertRedirect(route('labs.show', $report));

        $this->get(route('labs.show', $report))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.groups.0.results.0.value', '89.5')
                ->where('report.groups.0.results.0.flag', 'normal')
                ->where('report.groups.0.results.0.correction_reason', 'Transcription error')
                ->where('report.groups.0.results.0.entered_by', $this->doctor->name)
                ->has('report.groups.0.results.0.history', 1)
                ->where('report.groups.0.results.0.history.0.value', '98.5'));
    });

    test('an old version cannot be corrected again', function () {
        $report = enterReport();
        $original = $report->results()->where('biomarker_id', $this->apob->id)->sole();
        $original->correct(['value' => '80', 'unit' => 'mg/dL'], 'Fix', $this->doctor);

        $this->actingAs($this->doctor)
            ->post(route('lab-results.corrections.store', $original), ['value' => '81', 'unit' => 'mg/dL', 'reason' => 'Again'])
            ->assertSessionHasErrors('value');
    });

    test('a correction must use one of the biomarker units', function () {
        $report = enterReport();
        $original = $report->results()->where('biomarker_id', $this->apob->id)->sole();

        $this->actingAs($this->doctor)
            ->post(route('lab-results.corrections.store', $original), ['value' => '0.9', 'unit' => 'mmol/L', 'reason' => 'Unit'])
            ->assertSessionHasErrors('unit');
    });
});

describe('auditing', function () {
    test('lists and views are audited, and the log never holds values', function () {
        $report = enterReport();
        $this->actingAs($this->doctor)->get(route('labs.index'));
        $this->get(route('labs.show', $report));
        $original = $report->results()->where('biomarker_id', $this->apob->id)->sole();
        $this->post(route('lab-results.corrections.store', $original), ['value' => '89.5', 'unit' => 'mg/dL', 'reason' => 'Transcription error']);
        $this->post(route('labs.release', $report));

        expect(auditEntries(AuditEvent::LabReportListed)->sole()->metadata)->toBe(['status' => 'pending_review', 'page' => 1, 'results' => 1])
            ->and(auditEntries(AuditEvent::LabReportViewed)->sole()->auditable_id)->toBe($report->id)
            ->and(auditEntries(AuditEvent::LabReportReleased)->sole()->user_id)->toBe($this->doctor->id);

        expect(json_encode(AuditLog::query()->toBase()->get()))
            ->not->toContain('98.5')
            ->not->toContain('89.5')
            ->not->toContain('Transcription')
            ->not->toContain('Repeat in 3 months')
            ->not->toContain('Not fasting');

        $this->artisan('audit:verify')->assertSuccessful();
    });
});
