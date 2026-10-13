<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolAdherence;
use App\Models\ProtocolItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create();
    $this->doctor = userWithRole(UserRole::Doctor);

    // Issued a week ago, so every tracked day is in range.
    $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'UTC'));
    $this->prescription = Prescription::factory()->for($this->patient)->create([
        'title' => 'Metabolic health protocol',
        'notes' => 'Doctor only: borderline HbA1c',
        'starts_on' => '2026-10-01',
        'prescribed_by' => $this->doctor->id,
    ]);
    $this->item = ProtocolItem::factory()->for($this->prescription)->create([
        'name' => 'Metformin',
        'dosage' => '500 mg',
        'instructions' => 'With dinner',
    ]);
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $data
 */
function tick(ProtocolItem $item, array $data = []): TestResponse
{
    return test()->put(route('my-protocols.adherence.update', $item), [
        'date' => '2026-10-12',
        'taken' => true,
        ...$data,
    ]);
}

describe('the dashboard', function () {
    test('shows protocols in effect today, with the last seven days to tick off', function () {
        $this->item->recordAdherence('2026-10-11', true, 'Felt fine');

        $this->actingAs($this->account)
            ->get(route('my-protocols.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('prescriptions/MyProtocols')
                ->where('linked', true)
                ->where('today', '2026-10-12')
                ->where('days', ['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'])
                ->has('prescriptions', 1)
                ->where('prescriptions.0.title', 'Metabolic health protocol')
                ->missing('prescriptions.0.notes')
                ->where('prescriptions.0.items.0.name', 'Metformin')
                ->where('prescriptions.0.items.0.dosage', '500 mg')
                ->where('prescriptions.0.items.0.instructions', 'With dinner')
                ->where('prescriptions.0.items.0.daily', true)
                ->where('prescriptions.0.items.0.days.5', ['date' => '2026-10-11', 'scheduled' => true, 'taken' => true, 'notes' => 'Felt fine'])
                ->where('prescriptions.0.items.0.days.6.taken', false));
    });

    test('leaves out staff-only details, other patients and protocols not in effect', function () {
        $adjusted = $this->item->adjust(['dosage' => '1000 mg'], 'Doctor only: tolerated well', $this->doctor);
        Prescription::factory()->for($this->patient)->create(['title' => 'Old protocol', 'starts_on' => '2026-01-01', 'ends_on' => '2026-02-01']);
        Prescription::factory()->for($this->patient)->create(['title' => 'Future protocol', 'starts_on' => '2026-12-01']);
        Prescription::factory()->for($this->patient)->discontinued()->create(['title' => 'Stopped protocol']);
        Prescription::factory()->create(['title' => 'Someone else protocol']);

        $response = $this->actingAs($this->account)->get(route('my-protocols.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)
            ->has('prescriptions.0.items', 1)
            ->where('prescriptions.0.items.0.id', $adjusted->id)
            ->where('prescriptions.0.items.0.days.0.scheduled', false)
            ->where('prescriptions.0.items.0.days.6.scheduled', true));

        expect($response->getContent())
            ->not->toContain('Doctor only')
            ->not->toContain('Old protocol')
            ->not->toContain('Future protocol')
            ->not->toContain('Stopped protocol')
            ->not->toContain('Someone else')
            ->not->toContain($this->doctor->name);
    });

    test('viewing is audited without content', function () {
        $this->actingAs($this->account)->get(route('my-protocols.index'));

        expect(auditEntries(AuditEvent::PrescriptionListed)->sole())
            ->auditable_id->toBe($this->patient->id)
            ->metadata->toBe(['context' => 'self', 'results' => 1]);
    });

    test('an unlinked account sees a notice', function () {
        $this->actingAs(userWithRole(UserRole::Patient))
            ->get(route('my-protocols.index'))
            ->assertInertia(fn (Assert $page) => $page->where('linked', false)->has('prescriptions', 0));
    });

    test('staff cannot use the patient protocol pages', function (UserRole $role) {
        $this->actingAs(userWithRole($role));

        $this->get(route('my-protocols.index'))->assertForbidden();
        tick($this->item)->assertForbidden();
    })->with(['doctor' => UserRole::Doctor, 'super admin' => UserRole::SuperAdmin, 'clinic admin' => UserRole::ClinicAdmin]);
});

describe('recording adherence', function () {
    test('ticking records the day, with the note encrypted', function () {
        $this->actingAs($this->account);

        tick($this->item, ['notes' => 'Took it with a late dinner'])->assertRedirect(route('my-protocols.index'));

        $adherence = ProtocolAdherence::sole();

        expect($adherence)
            ->protocol_item_id->toBe($this->item->id)
            ->patient_id->toBe($this->patient->id)
            ->taken->toBeTrue()
            ->notes->toBe('Took it with a late dinner')
            ->and($adherence->taken_at->toDateTimeString())->toBe('2026-10-12 10:00:00')
            ->and($adherence->scheduled_date->toDateString())->toBe('2026-10-12')
            ->and(DB::table('protocol_adherences')->value('notes'))->not->toContain('dinner');
    });

    test('unticking keeps the row and note, and there is only ever one row per day', function () {
        $this->actingAs($this->account);

        tick($this->item, ['notes' => 'Nausea']);
        $this->travel(2)->hours();
        tick($this->item, ['taken' => false, 'notes' => 'Nausea']);

        expect(ProtocolAdherence::sole())
            ->taken->toBeFalse()
            ->taken_at->toBeNull()
            ->notes->toBe('Nausea');

        tick($this->item, ['notes' => 'Nausea']);

        expect(ProtocolAdherence::count())->toBe(1)
            ->and(ProtocolAdherence::sole()->taken_at->toDateTimeString())->toBe('2026-10-12 12:00:00');
    });

    test('a patient can catch up on the last seven days, but not further back or ahead', function (string $date, bool $allowed) {
        $this->actingAs($this->account);

        $response = tick($this->item, ['date' => $date]);

        $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('date');
        expect(ProtocolAdherence::count())->toBe($allowed ? 1 : 0);
    })->with([
        'today' => ['2026-10-12', true],
        'six days ago' => ['2026-10-06', true],
        'seven days ago' => ['2026-10-05', false],
        'tomorrow' => ['2026-10-13', false],
        'not a date' => ['yesterday', false],
    ]);

    test('days before the item was prescribed cannot be ticked', function () {
        $added = $this->prescription->addItem(['type' => 'supplement', 'name' => 'Omega-3', 'frequency' => 'daily'], $this->doctor);
        $this->actingAs($this->account);

        tick($added, ['date' => '2026-10-11'])->assertSessionHasErrors('date');
        tick($added)->assertSessionHasNoErrors();
    });

    test("another patient's item and a missing item look the same", function () {
        $other = ProtocolItem::factory()->create();
        $this->actingAs($this->account);

        tick($other)->assertNotFound();
        $this->put(route('my-protocols.adherence.update', 999999), ['date' => '2026-10-12', 'taken' => true])->assertNotFound();
        expect(ProtocolAdherence::count())->toBe(0);
    });

    test('an unlinked account cannot record adherence', function () {
        $this->actingAs(userWithRole(UserRole::Patient));

        tick($this->item)->assertNotFound();
    });

    test('recording is audited by field name, never by value', function () {
        $this->actingAs($this->account);

        tick($this->item, ['notes' => 'Skipped breakfast']);
        tick($this->item, ['taken' => false, 'notes' => 'Skipped breakfast']);

        $entries = auditEntries(AuditEvent::AdherenceRecorded);

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->metadata)->toBe(['protocol_item_id' => $this->item->id, 'scheduled_date' => '2026-10-12', 'fields' => ['taken', 'notes']])
            ->and($entries[1]->metadata['fields'])->toBe(['taken'])
            ->and($entries[1]->user_id)->toBe($this->account->id)
            ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('breakfast');
    });

    test("today follows the clinic's timezone", function () {
        config(['clinic.timezone' => 'Asia/Kolkata']);
        $this->travelTo(Carbon::parse('2026-10-12 20:00:00', 'UTC'));
        $this->actingAs($this->account);

        $this->get(route('my-protocols.index'))->assertInertia(fn (Assert $page) => $page->where('today', '2026-10-13'));
        tick($this->item, ['date' => '2026-10-13'])->assertSessionHasNoErrors();
    });
});

describe("a clinician's view of the patient's page", function () {
    test('doctors and practitioners see the page read-only, with ticks and notes', function (UserRole $role) {
        $this->item->recordAdherence('2026-10-11', true, 'Felt dizzy');

        $this->actingAs(userWithRole($role))
            ->get(route('patients.protocols.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page
                ->component('prescriptions/MyProtocols')
                ->where('readOnly', true)
                ->where('patient', ['id' => $this->patient->id, 'full_name' => $this->patient->full_name])
                ->where('prescriptions.0.items.0.name', 'Metformin')
                ->where('prescriptions.0.items.0.days.5', ['date' => '2026-10-11', 'scheduled' => true, 'taken' => true, 'notes' => 'Felt dizzy'])
                ->missing('prescriptions.0.notes'));
    })->with(['doctor' => UserRole::Doctor, 'practitioner' => UserRole::Practitioner]);

    test('the patient portal itself stays editable', function () {
        $this->actingAs($this->account)
            ->get(route('my-protocols.index'))
            ->assertInertia(fn (Assert $page) => $page->where('readOnly', false)->where('patient', null));
    });

    test('viewing is audited as a clinician view, without content', function () {
        $this->actingAs($this->doctor)->get(route('patients.protocols.show', $this->patient));

        expect(auditEntries(AuditEvent::PrescriptionListed)->sole())
            ->user_id->toBe($this->doctor->id)
            ->auditable_id->toBe($this->patient->id)
            ->metadata->toBe(['context' => 'patient_view', 'results' => 1]);
    });

    test('everyone else is refused, whether or not the patient exists', function (UserRole $role) {
        $this->actingAs($role === UserRole::Patient ? $this->account : userWithRole($role));

        $this->get(route('patients.protocols.show', $this->patient))->assertForbidden();
        $this->get(route('patients.protocols.show', 999999))->assertForbidden();
    })->with([
        'the patient' => UserRole::Patient,
        'super admin' => UserRole::SuperAdmin,
        'clinic admin' => UserRole::ClinicAdmin,
        'lab staff' => UserRole::LabStaff,
    ]);

    test('clinicians still cannot tick on the patient\'s behalf', function () {
        $this->actingAs($this->doctor);

        tick($this->item)->assertForbidden();
        expect(ProtocolAdherence::count())->toBe(0);
    });
});
