<?php

use App\Enums\AuditEvent;
use App\Enums\LabRequisitionStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Biomarker;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\LabRequisition;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\BiomarkerSeeder;
use Database\Seeders\LabPartnerSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'UTC'));
    $this->seed([BiomarkerSeeder::class, LabPartnerSeeder::class]);
    $this->apob = Biomarker::where('code', 'apob')->sole();
    $this->strand = LabPartner::where('code', 'strand')->sole();
    $this->biocogniz = LabPartner::where('code', 'biocogniz')->sole();
    $this->strandUser = userWithRole(UserRole::LabStaff, $this->strand);
    $this->biocognizUser = userWithRole(UserRole::LabStaff, $this->biocogniz);
    $this->doctor = userWithRole(UserRole::Doctor);
    $this->patient = Patient::factory()->create();

    $this->strandOrder = requisitionFor($this->patient, $this->strand);
    $this->biocognizOrder = requisitionFor($this->patient, $this->biocogniz);
    $this->strandReport = LabReport::factory()->for($this->patient)->create(['lab_partner_id' => $this->strand->id, 'lab_requisition_id' => $this->strandOrder->id]);
    $this->biocognizReport = LabReport::factory()->for($this->patient)->create(['lab_partner_id' => $this->biocogniz->id, 'lab_requisition_id' => $this->biocognizOrder->id]);
    $this->clinicReport = LabReport::factory()->for($this->patient)->create();
});

describe('reports', function () {
    test('a lab user lists only their own lab\'s reports', function () {
        $this->actingAs($this->strandUser)
            ->get(route('labs.index', ['status' => 'all']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.id', $this->strandReport->id)
                ->where('reports.data.0.source', 'Strand Life Sciences'));
    });

    test('another lab\'s report, or the clinic\'s own, cannot be opened or changed', function (string $which) {
        $report = $this->{$which};
        $result = LabResult::factory()->for($report, 'report')->for($this->apob)->create();
        $this->actingAs($this->strandUser);

        $this->get(route('labs.show', $report))->assertForbidden();
        $this->get(route('labs.attachment', $report))->assertForbidden();
        $this->post(route('labs.results.store', $report), ['results' => [['biomarker_id' => $this->apob->id, 'value' => '1', 'unit' => 'mg/dL']]])->assertForbidden();
        $this->post(route('lab-results.corrections.store', $result), ['value' => '2', 'unit' => 'mg/dL', 'reason' => 'x'])->assertForbidden();

        expect($report->results()->count())->toBe(1);
    })->with(['another lab' => 'biocognizReport', 'the clinic lab' => 'clinicReport']);

    test('clinicians see every lab\'s reports', function () {
        $this->actingAs($this->doctor)
            ->get(route('labs.index', ['status' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->has('reports.data', 3));
    });

    test('a lab user without a partner sees nothing at all', function () {
        $this->actingAs(User::factory()->withRole(UserRole::LabStaff)->create(['lab_partner_id' => null]));

        $this->get(route('labs.index'))->assertForbidden();
        $this->get(route('lab-requisitions.index'))->assertForbidden();
        $this->get(route('labs.show', $this->strandReport))->assertForbidden();
    });
});

describe('entering results', function () {
    /**
     * @return array<string, mixed>
     */
    function partnerReport(LabRequisition $requisition, array $overrides = []): array
    {
        return [
            'patient_id' => $requisition->patient_id,
            'lab_requisition_id' => $requisition->id,
            'collected_date' => '2026-10-30',
            'collected_time' => '08:00',
            'results' => [['biomarker_id' => test()->apob->id, 'value' => '90', 'unit' => 'mg/dL']],
            ...$overrides,
        ];
    }

    test('a lab user reports against their own requisition, which is then marked reported', function () {
        $order = requisitionFor($this->patient, $this->strand);

        $this->actingAs($this->strandUser)->post(route('labs.store'), partnerReport($order))->assertSessionHasNoErrors();

        $report = LabReport::latest('id')->first();

        expect($report->lab_partner_id)->toBe($this->strand->id)
            ->and($report->lab_requisition_id)->toBe($order->id)
            ->and($report->lab_name)->toBe('Strand Life Sciences')
            ->and($order->fresh()->status)->toBe(LabRequisitionStatus::Reported);
    });

    test('a lab user cannot report against another lab\'s requisition, or without one', function () {
        $this->actingAs($this->strandUser);

        $this->post(route('labs.store'), partnerReport($this->biocognizOrder))->assertSessionHasErrors('lab_requisition_id');
        $this->post(route('labs.store'), partnerReport($this->strandOrder, ['lab_requisition_id' => null]))->assertSessionHasErrors('lab_requisition_id');

        expect(LabReport::count())->toBe(3);
    });

    test('the patient must be the one on the requisition', function () {
        $this->actingAs($this->strandUser)
            ->post(route('labs.store'), partnerReport($this->strandOrder, ['patient_id' => Patient::factory()->create()->id]))
            ->assertSessionHasErrors('patient_id');
    });

    test('a cancelled requisition takes no reports', function () {
        $order = requisitionFor($this->patient, $this->strand);
        $order->cancel($this->doctor);

        $this->actingAs($this->strandUser)->post(route('labs.store'), partnerReport($order))->assertSessionHasErrors('lab_requisition_id');
    });

    test('the entry page offers a lab user only their own open requisitions, and no patient search', function () {
        $this->actingAs($this->strandUser)
            ->get(route('labs.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('requiresRequisition', true)
                ->where('requisitions', fn ($requisitions) => collect($requisitions)->pluck('id')->all() === [$this->strandOrder->id]));

        $this->get(route('labs.create', ['requisition' => $this->biocognizOrder->id]))
            ->assertInertia(fn (Assert $page) => $page->where('requisition', null)->where('patient', null));
    });
});

describe('requisitions', function () {
    test('clinicians order tests the lab offers, audited without the tests', function () {
        $this->actingAs($this->doctor)
            ->post(route('patients.lab-requisitions.store', $this->patient), [
                'lab_partner_id' => $this->strand->id,
                'tests' => ['circulating_tumour_cells'],
                'instructions' => 'Fasting not required',
            ])
            ->assertRedirect(route('patients.show', $this->patient));

        $order = LabRequisition::latest('id')->first();

        expect($order->tests)->toBe(['circulating_tumour_cells'])
            ->and($order->ordered_by)->toBe($this->doctor->id)
            ->and(auditEntries(AuditEvent::LabRequisitionOrdered)->last()->metadata)
            ->toBe(['patient_id' => $this->patient->id, 'lab_partner_id' => $this->strand->id])
            ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('tumour')->not->toContain('Fasting');
    });

    test('tests another lab offers are rejected', function () {
        $this->actingAs($this->doctor)
            ->post(route('patients.lab-requisitions.store', $this->patient), ['lab_partner_id' => $this->strand->id, 'tests' => ['nutrigenomics']])
            ->assertSessionHasErrors('tests');
    });

    test('a lab user lists only requisitions sent to their lab, and cannot order or cancel', function () {
        $this->actingAs($this->strandUser)
            ->get(route('lab-requisitions.index', ['status' => 'all']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('labPartner', 'Strand Life Sciences')
                ->where('requisitions', fn ($rows) => collect($rows)->pluck('lab_partner.name')->unique()->all() === ['Strand Life Sciences']));

        $this->post(route('patients.lab-requisitions.store', $this->patient), ['lab_partner_id' => $this->strand->id, 'tests' => ['circulating_tumour_cells']])->assertForbidden();
        $this->post(route('lab-requisitions.cancel', $this->strandOrder))->assertForbidden();
    });

    test('only open requisitions can be cancelled', function () {
        $this->actingAs($this->doctor)->post(route('lab-requisitions.cancel', $this->strandOrder))->assertRedirect();
        expect($this->strandOrder->fresh()->status)->toBe(LabRequisitionStatus::Cancelled);

        $this->post(route('lab-requisitions.cancel', $this->strandOrder))->assertForbidden();
    });

    test('the patient record shows requisitions and lab choices to clinicians only', function () {
        $this->actingAs($this->doctor)
            ->get(route('patients.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page
                ->has('labRequisitions', 2)
                ->where('can.orderLabTests', true)
                ->has('labPartners', 4));

        $this->actingAs(userWithRole(UserRole::ClinicAdmin))
            ->get(route('patients.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page->where('labRequisitions', null)->where('can.orderLabTests', false));
    });
});
