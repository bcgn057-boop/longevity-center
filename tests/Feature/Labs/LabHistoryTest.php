<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Biomarker;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\Patient;
use Database\Seeders\BiomarkerSeeder;
use Database\Seeders\LabPartnerSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'UTC'));
    $this->seed([BiomarkerSeeder::class, LabPartnerSeeder::class]);
    $this->apob = Biomarker::where('code', 'apob')->sole();
    $this->il6 = Biomarker::where('code', 'il_6')->sole();
    $this->tata = LabPartner::where('code', 'tata_1mg')->sole();
    $this->ammo = LabPartner::where('code', 'ammogenomics')->sole();
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create();
    $this->doctor = userWithRole(UserRole::Doctor);

    $report = function (?LabPartner $partner, string $collected, array $results, bool $release = true) {
        $report = LabReport::factory()->for($this->patient)->create([
            'lab_partner_id' => $partner?->id,
            'lab_name' => $partner ? null : 'In-house lab',
            'collected_at' => $collected,
        ]);

        foreach ($results as [$marker, $value, $unit, $high]) {
            LabResult::factory()->for($report, 'report')->for($marker)->create(['value' => $value, 'unit' => $unit, 'reference_high' => $high]);
        }

        if ($release) {
            $report->release($this->doctor);
        }

        return $report;
    };

    $this->first = $report($this->tata, '2026-06-01 08:00', [[$this->apob, '110', 'mg/dL', '90']]);
    $this->second = $report($this->ammo, '2026-08-01 08:00', [[$this->il6, '2.1', 'pg/mL', '3.4']]);
    $this->third = $report(null, '2026-10-01 08:00', [[$this->apob, '0.85', 'g/L', null]]);
    $this->pending = $report($this->tata, '2026-10-20 08:00', [[$this->apob, '95', 'mg/dL', '90']], release: false);
});

test('clinicians see every lab side by side, including reports under review, and it is audited', function () {
    $this->actingAs($this->doctor)
        ->get(route('patients.lab-history', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->component('labs/History')
            ->has('history.sources', 3)
            ->where('history.sources.0', ['name' => 'TATA 1MG', 'partner' => true, 'reports' => 2, 'latest' => '20 Oct 2026'])
            ->has('history.timeline', 4)
            ->where('history.timeline.0.id', $this->pending->id)
            ->where('history.timeline.0.status', 'pending_review')
            ->where('history.timeline.1.source', 'In-house lab')
            ->where('history.comparison.columns', fn ($columns) => collect($columns)->pluck('source')->all() === ['TATA 1MG', 'Ammogenomics', 'In-house lab', 'TATA 1MG'])
            // ApoB across labs, each in the unit its lab used, never converted.
            ->where('history.comparison.groups.0.rows.0.biomarker', 'Apolipoprotein B (ApoB)')
            ->where('history.comparison.groups.0.rows.0.cells.0', ['value' => '110', 'unit' => 'mg/dL', 'flag' => 'high', 'flag_label' => 'Above range'])
            ->where('history.comparison.groups.0.rows.0.cells.1', null)
            ->where('history.comparison.groups.0.rows.0.cells.2.unit', 'g/L')
            ->where('history.comparison.groups.1.label', 'Inflammation'));

    expect(auditEntries(AuditEvent::LabReportListed)->sole())
        ->auditable_id->toBe($this->patient->id)
        ->metadata->toBe(['context' => 'history', 'results' => 4]);
});

test('partner labs, admins and patients cannot open the clinician history', function (UserRole $role) {
    $this->actingAs(userWithRole($role, $this->tata))
        ->get(route('patients.lab-history', $this->patient))
        ->assertForbidden();
})->with([
    'a partner lab' => UserRole::LabStaff,
    'clinic admin' => UserRole::ClinicAdmin,
    'super admin' => UserRole::SuperAdmin,
    'patient' => UserRole::Patient,
]);

test('patients see their released reports from every lab together', function () {
    $this->actingAs($this->account)
        ->get(route('my-results.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.timeline', 3)
            ->where('history.timeline', fn ($timeline) => ! collect($timeline)->pluck('id')->contains($this->pending->id))
            ->where('history.sources', fn ($sources) => collect($sources)->pluck('name')->sort()->values()->all() === ['Ammogenomics', 'In-house lab', 'TATA 1MG'])
            ->where('reports.0.source', 'In-house lab'));
});

test("the patient's dashboard counts the labs their results came from", function () {
    $props = $this->actingAs($this->account)->get(route('dashboard'))->viewData('page')['props'];
    $card = collect($props['sections'][0]['cards'])->firstWhere('key', 'results');

    expect($card['value'])->toBe(3)
        ->and($card['detail'])->toStartWith('From 3 labs');
});

test('the clinician dashboard counts tests awaiting results', function () {
    requisitionFor($this->patient, $this->tata);
    requisitionFor($this->patient, $this->ammo)->cancel($this->doctor);

    $props = $this->actingAs($this->doctor)->get(route('dashboard'))->viewData('page')['props'];
    $card = collect(collect($props['sections'])->firstWhere('title', 'Your day')['cards'])->firstWhere('key', 'requisitions');

    expect($card['value'])->toBe(1);
});
