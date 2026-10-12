<?php

use App\Enums\AuditEvent;
use App\Enums\PrescriptionStatus;
use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    $this->doctor = userWithRole(UserRole::Doctor);
    $this->patient = Patient::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prescriptionPayload(array $overrides = []): array
{
    return [
        'title' => 'Metabolic health protocol',
        'notes' => 'HbA1c borderline',
        'starts_on' => '2026-10-12',
        'ends_on' => '2027-01-12',
        'items' => [
            ['type' => 'medication', 'name' => 'Metformin', 'dosage' => '500 mg', 'instructions' => 'With dinner', 'frequency' => 'daily'],
            ['type' => 'exercise', 'name' => 'Zone 2 cardio', 'dosage' => '45 minutes', 'frequency' => 'weekly'],
        ],
        ...$overrides,
    ];
}

function prescriptionFor(Patient $patient): Prescription
{
    $prescription = Prescription::factory()->for($patient)->create(['starts_on' => '2026-10-01', 'prescribed_by' => test()->doctor->id]);
    ProtocolItem::factory()->for($prescription)->create(['name' => 'Vitamin D3', 'dosage' => '2000 IU']);

    return $prescription;
}

describe('access', function () {
    test('doctors can manage and practitioners can only read', function () {
        $prescription = prescriptionFor($this->patient);
        $practitioner = userWithRole(UserRole::Practitioner);

        $this->actingAs($this->doctor)
            ->get(route('patients.prescriptions.index', $this->patient))
            ->assertInertia(fn (Assert $page) => $page->component('prescriptions/Index')->where('can.manage', true));

        $this->actingAs($practitioner)
            ->get(route('patients.prescriptions.index', $this->patient))
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        $this->post(route('patients.prescriptions.store', $this->patient), prescriptionPayload())->assertForbidden();
        $this->put(route('prescriptions.update', $prescription), ['title' => 'X'])->assertForbidden();
        $this->post(route('protocol-items.adjust', $prescription->items->first()), ['dosage' => '1', 'reason' => 'x'])->assertForbidden();
        expect(Prescription::count())->toBe(1);
    });

    test('everyone else is refused, whether or not the record exists', function (UserRole $role) {
        $prescription = prescriptionFor($this->patient);
        $item = $prescription->items->first();
        $this->actingAs(userWithRole($role));

        $this->get(route('patients.prescriptions.index', $this->patient))->assertForbidden();
        $this->get(route('patients.prescriptions.index', 999999))->assertForbidden();
        $this->post(route('patients.prescriptions.store', $this->patient), prescriptionPayload())->assertForbidden();
        $this->put(route('prescriptions.update', $prescription), ['title' => 'X'])->assertForbidden();
        $this->post(route('prescriptions.discontinue', $prescription), ['reason' => 'x'])->assertForbidden();
        $this->post(route('prescriptions.items.store', $prescription), [])->assertForbidden();
        $this->post(route('protocol-items.adjust', $item), ['dosage' => '1', 'reason' => 'x'])->assertForbidden();
        $this->post(route('protocol-items.discontinue', $item))->assertForbidden();
        $this->post(route('protocol-items.discontinue', 999999))->assertForbidden();
    })->with([
        'super admin' => UserRole::SuperAdmin,
        'clinic admin' => UserRole::ClinicAdmin,
        'lab staff' => UserRole::LabStaff,
        'patient' => UserRole::Patient,
    ]);

    test('archived patients cannot be prescribed to', function () {
        $this->patient->delete();

        $this->actingAs($this->doctor)
            ->post(route('patients.prescriptions.store', $this->patient), prescriptionPayload())
            ->assertNotFound();
    });
});

describe('the prescriptions screen', function () {
    test('lists prescriptions with item history and recent adherence, and is audited', function () {
        $prescription = prescriptionFor($this->patient);
        $original = $prescription->items->first();
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
        $adjusted = $original->adjust(['dosage' => '4000 IU'], 'Still low on retest', $this->doctor);
        $adjusted->recordAdherence('2026-10-10', true, null);
        $adjusted->recordAdherence('2026-10-11', false, null);
        $this->travelBack();
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
        Prescription::factory()->for($this->patient)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-03-01']);

        $this->actingAs($this->doctor)
            ->get(route('patients.prescriptions.index', $this->patient))
            ->assertInertia(fn (Assert $page) => $page
                ->where('patient.id', $this->patient->id)
                ->has('prescriptions', 2)
                ->where('prescriptions.0.id', $prescription->id)
                ->where('prescriptions.0.status', 'active')
                ->where('prescriptions.0.notes', $prescription->notes)
                ->where('prescriptions.0.prescriber', $this->doctor->name)
                ->has('prescriptions.0.items', 2)
                ->where('prescriptions.0.items.0.current', false)
                ->where('prescriptions.0.items.1.id', $adjusted->id)
                ->where('prescriptions.0.items.1.dosage', '4000 IU')
                ->where('prescriptions.0.items.1.adjustment_reason', 'Still low on retest')
                ->where('prescriptions.0.items.1.adherence', ['taken' => 1, 'scheduled' => 3])
                ->where('prescriptions.1.status', 'completed')
                ->has('options.types')
                ->has('options.frequencies'));

        expect(auditEntries(AuditEvent::PrescriptionListed)->sole())
            ->auditable_id->toBe($this->patient->id)
            ->metadata->toBe(['results' => 2]);
    });
});

describe('issuing and changing', function () {
    test('a doctor issues a prescription with its items', function () {
        $this->actingAs($this->doctor)
            ->post(route('patients.prescriptions.store', $this->patient), prescriptionPayload())
            ->assertRedirect(route('patients.prescriptions.index', $this->patient));

        $prescription = Prescription::sole();

        expect($prescription->patient_id)->toBe($this->patient->id)
            ->and($prescription->prescribed_by)->toBe($this->doctor->id)
            ->and($prescription->title)->toBe('Metabolic health protocol')
            ->and($prescription->ends_on->toDateString())->toBe('2027-01-12')
            ->and($prescription->items->pluck('name')->all())->toBe(['Metformin', 'Zone 2 cardio'])
            ->and($prescription->items->pluck('sort_order')->all())->toBe([0, 1])
            ->and($prescription->items->first()->created_by)->toBe($this->doctor->id);
    });

    test('invalid prescriptions are rejected', function (array $overrides, string $field) {
        $this->actingAs($this->doctor)
            ->post(route('patients.prescriptions.store', $this->patient), prescriptionPayload($overrides))
            ->assertSessionHasErrors($field);

        expect(Prescription::count())->toBe(0);
    })->with([
        'no items' => [['items' => []], 'items'],
        'ends before it starts' => [['ends_on' => '2026-10-01'], 'ends_on'],
        'unknown frequency' => [['items' => [['type' => 'supplement', 'name' => 'Zinc', 'frequency' => 'hourly']]], 'items.0.frequency'],
        'no name' => [['items' => [['type' => 'supplement', 'name' => '', 'frequency' => 'daily']]], 'items.0.name'],
    ]);

    test('a doctor edits the title, notes and end date', function () {
        $prescription = prescriptionFor($this->patient);

        $this->actingAs($this->doctor)
            ->put(route('prescriptions.update', $prescription), ['title' => 'Revised protocol', 'notes' => '', 'ends_on' => '2026-12-31'])
            ->assertRedirect(route('patients.prescriptions.index', $this->patient));

        expect($prescription->fresh())
            ->title->toBe('Revised protocol')
            ->notes->toBeNull()
            ->and($prescription->fresh()->ends_on->toDateString())->toBe('2026-12-31');

        $this->put(route('prescriptions.update', $prescription), ['title' => 'X', 'ends_on' => '2026-09-01'])
            ->assertSessionHasErrors('ends_on');
    });

    test('a doctor adds an item at the end', function () {
        $prescription = prescriptionFor($this->patient);

        $this->actingAs($this->doctor)
            ->post(route('prescriptions.items.store', $prescription), ['type' => 'supplement', 'name' => 'Omega-3', 'dosage' => '1 g', 'frequency' => 'daily'])
            ->assertRedirect();

        expect($prescription->items()->get()->last())
            ->name->toBe('Omega-3')
            ->sort_order->toBe(1);
    });

    test('a doctor adjusts an item, keeping the earlier version', function () {
        $prescription = prescriptionFor($this->patient);
        $item = $prescription->items->first();

        $this->actingAs($this->doctor)
            ->post(route('protocol-items.adjust', $item), ['dosage' => '4000 IU', 'reason' => 'Still low on retest'])
            ->assertRedirect(route('patients.prescriptions.index', $this->patient));

        $current = $prescription->currentItems()->sole();

        expect($current->supersedes_id)->toBe($item->id)
            ->and($current->name)->toBe('Vitamin D3')
            ->and($current->dosage)->toBe('4000 IU')
            ->and($item->fresh()->isDiscontinued())->toBeTrue();
    });

    test('an adjustment needs a reason and a change, and only to the current version', function () {
        $item = prescriptionFor($this->patient)->items->first();
        $this->actingAs($this->doctor);

        $this->post(route('protocol-items.adjust', $item), ['dosage' => '4000 IU'])->assertSessionHasErrors('reason');
        $this->post(route('protocol-items.adjust', $item), ['dosage' => '2000 IU', 'reason' => 'Same'])->assertSessionHasErrors('reason');

        $item->adjust(['dosage' => '3000 IU'], 'Raised', $this->doctor);

        $this->post(route('protocol-items.adjust', $item), ['dosage' => '4000 IU', 'reason' => 'Raised again'])->assertSessionHasErrors('reason');
        expect(ProtocolItem::count())->toBe(2);
    });

    test('a doctor stops one item', function () {
        $item = prescriptionFor($this->patient)->items->first();
        $this->actingAs($this->doctor);

        $this->post(route('protocol-items.discontinue', $item))->assertRedirect();
        expect($item->fresh()->discontinued_by)->toBe($this->doctor->id);

        $this->post(route('protocol-items.discontinue', $item))->assertSessionHasErrors('item');
    });

    test('a doctor discontinues a prescription with a reason, after which it is read-only', function () {
        $prescription = prescriptionFor($this->patient);
        $item = $prescription->items->first();
        $this->actingAs($this->doctor);

        $this->post(route('prescriptions.discontinue', $prescription), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('prescriptions.discontinue', $prescription), ['reason' => 'Side effects'])->assertRedirect();

        expect($prescription->fresh())
            ->status->toBe(PrescriptionStatus::Discontinued)
            ->discontinued_reason->toBe('Side effects')
            ->and($item->fresh()->isDiscontinued())->toBeTrue();

        $this->put(route('prescriptions.update', $prescription), ['title' => 'X'])->assertForbidden();
        $this->post(route('prescriptions.discontinue', $prescription), ['reason' => 'Again'])->assertForbidden();
        $this->post(route('prescriptions.items.store', $prescription), ['type' => 'other', 'name' => 'X', 'frequency' => 'daily'])->assertForbidden();
        $this->post(route('protocol-items.adjust', $item), ['dosage' => '1', 'reason' => 'x'])->assertForbidden();
    });
});

test('the patient record links clinicians to prescriptions', function (UserRole $role, bool $shown) {
    $this->actingAs(userWithRole($role))
        ->get(route('patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewPrescriptions', $shown));
})->with([
    'doctor' => [UserRole::Doctor, true],
    'practitioner' => [UserRole::Practitioner, true],
    'clinic admin' => [UserRole::ClinicAdmin, false],
    'super admin' => [UserRole::SuperAdmin, false],
]);
