<?php

use App\Enums\AuditEvent;
use App\Enums\PrescriptionStatus;
use App\Enums\ProtocolFrequency;
use App\Enums\ProtocolItemType;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Models\AuditLog;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->doctor = userWithRole(UserRole::Doctor);
    $this->prescription = Prescription::factory()->create();
});

function item(array $attributes = []): ProtocolItem
{
    return ProtocolItem::factory()->for(test()->prescription)->create($attributes);
}

describe('storage', function () {
    test('titles, notes, names, doses and instructions are encrypted at rest', function () {
        $prescription = Prescription::factory()->create(['title' => 'Insulin resistance protocol', 'notes' => 'Pre-diabetic HbA1c']);
        $item = ProtocolItem::factory()->for($prescription)->create(['name' => 'Metformin', 'dosage' => '500 mg', 'instructions' => 'Take with dinner']);

        $rawPrescription = DB::table('prescriptions')->where('id', $prescription->id)->first();
        $rawItem = DB::table('protocol_items')->where('id', $item->id)->first();

        expect($rawPrescription->title)->not->toContain('Insulin')
            ->and($rawPrescription->notes)->not->toContain('HbA1c')
            ->and($rawItem->name)->not->toContain('Metformin')
            ->and(decrypt($rawItem->name, false))->toBe('Metformin')
            ->and($rawItem->dosage)->not->toContain('500')
            ->and($rawItem->instructions)->not->toContain('dinner')
            ->and($prescription->toArray())->not->toHaveKeys(['title', 'notes'])
            ->and($item->toArray())->not->toHaveKeys(['name', 'dosage', 'instructions']);
    });

    test('a prescription cannot end before it starts', function () {
        Prescription::factory()->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-09']);
    })->throws(InvalidArgumentException::class);

    test('items are cast to their type and frequency', function () {
        $item = item(['type' => 'supplement', 'frequency' => 'twice_daily'])->fresh();

        expect($item->type)->toBe(ProtocolItemType::Supplement)
            ->and($item->frequency)->toBe(ProtocolFrequency::TwiceDaily)
            ->and($item->frequency->isDaily())->toBeTrue();
    });
});

describe('prescriptions in effect', function () {
    test('only active prescriptions covering the day are in effect', function () {
        Prescription::query()->update(['starts_on' => '2026-01-01']);
        $ended = Prescription::factory()->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-02-01']);
        $future = Prescription::factory()->create(['starts_on' => '2026-12-01']);
        $stopped = Prescription::factory()->discontinued()->create(['starts_on' => '2026-01-01']);
        $lastDay = Prescription::factory()->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-10-12']);

        expect(Prescription::inEffect('2026-10-12')->pluck('id')->all())
            ->toBe([$this->prescription->id, $lastDay->id])
            ->not->toContain($ended->id, $future->id, $stopped->id);
    });
});

describe('adjustments and discontinuation', function () {
    test('an adjustment is a new item and the old one is kept but stopped', function () {
        $original = item(['name' => 'Vitamin D3', 'dosage' => '2000 IU']);

        $adjusted = $original->adjust(['dosage' => '4000 IU'], 'Level still low on retest', $this->doctor);

        expect($adjusted->supersedes_id)->toBe($original->id)
            ->and($adjusted->name)->toBe('Vitamin D3')
            ->and($adjusted->dosage)->toBe('4000 IU')
            ->and($adjusted->created_by)->toBe($this->doctor->id)
            ->and($adjusted->fresh()->adjustment_reason)->toBe('Level still low on retest')
            ->and($original->fresh()->dosage)->toBe('2000 IU')
            ->and($original->fresh()->isDiscontinued())->toBeTrue()
            ->and($this->prescription->currentItems()->pluck('id')->all())->toBe([$adjusted->id])
            ->and($this->prescription->items()->count())->toBe(2);
    });

    test('an adjustment needs a reason', function () {
        item()->adjust(['dosage' => '1 g'], ' ', $this->doctor);
    })->throws(InvalidArgumentException::class);

    test('only the latest version can be adjusted', function () {
        $original = item();
        $original->adjust(['dosage' => '1 g'], 'Fix', null);

        $original->adjust(['dosage' => '2 g'], 'Fix', null);
    })->throws(LogicException::class);

    test('items cannot be edited in place', function () {
        item()->update(['dosage' => '10 g']);
    })->throws(LogicException::class, 'Adjust the item instead.');

    test('discontinuing a prescription stops its items and is final', function () {
        $first = item();
        $second = item();

        $this->prescription->discontinue('Side effects', $this->doctor);

        $prescription = $this->prescription->fresh();

        expect($prescription->status)->toBe(PrescriptionStatus::Discontinued)
            ->and($prescription->discontinued_by)->toBe($this->doctor->id)
            ->and($prescription->discontinued_reason)->toBe('Side effects')
            ->and($first->fresh()->isDiscontinued())->toBeTrue()
            ->and($second->fresh()->isDiscontinued())->toBeTrue()
            ->and(fn () => $prescription->update(['notes' => 'Changed']))->toThrow(LogicException::class)
            ->and(fn () => $prescription->addItem(['type' => 'supplement', 'name' => 'Zinc', 'frequency' => 'daily'], $this->doctor))
            ->toThrow(LogicException::class, 'active prescription');
    });

    test('discontinuing needs a reason', function () {
        $this->prescription->discontinue('', $this->doctor);
    })->throws(InvalidArgumentException::class);

    test('prescriptions and items cannot be deleted', function () {
        $item = item();

        expect(fn () => $item->delete())->toThrow(MedicalRecordRetentionException::class)
            ->and(fn () => $this->prescription->delete())->toThrow(MedicalRecordRetentionException::class)
            ->and(Prescription::count())->toBe(1)
            ->and(ProtocolItem::count())->toBe(1);
    });
});

describe('audit', function () {
    test('changes are audited by id and field name, never by content', function () {
        $this->actingAs($this->doctor);

        $item = $this->prescription->addItem(['type' => 'medication', 'name' => 'Metformin', 'dosage' => '500 mg', 'frequency' => 'daily'], $this->doctor);
        $item->adjust(['dosage' => '1000 mg'], 'Tolerated well', $this->doctor);
        $this->prescription->update(['notes' => 'Recheck HbA1c in 3 months']);
        $this->prescription->fresh()->discontinue('Goal reached', $this->doctor);

        $events = AuditLog::pluck('event')->map->value->all();

        expect($events)->toContain(
            AuditEvent::PrescriptionIssued->value,
            AuditEvent::ProtocolItemAdded->value,
            AuditEvent::ProtocolItemAdjusted->value,
            AuditEvent::PrescriptionUpdated->value,
            AuditEvent::ProtocolItemDiscontinued->value,
            AuditEvent::PrescriptionDiscontinued->value,
        )
            // The adjusted original is not reported as separately stopped.
            ->and(array_count_values($events)[AuditEvent::ProtocolItemDiscontinued->value])->toBe(1)
            ->and(AuditLog::where('event', AuditEvent::PrescriptionUpdated->value)->sole()->metadata['fields'])->toBe(['notes']);

        $log = DB::table('audit_logs')->get()->toJson();

        foreach (['Metformin', '500 mg', '1000 mg', 'Tolerated', 'HbA1c', 'Goal reached'] as $phi) {
            expect($log)->not->toContain($phi);
        }
    });
});
