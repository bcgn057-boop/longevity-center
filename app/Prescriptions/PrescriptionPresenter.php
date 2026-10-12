<?php

namespace App\Prescriptions;

use App\Enums\PrescriptionStatus;
use App\Enums\ProtocolFrequency;
use App\Enums\ProtocolItemType;
use App\Models\Prescription;
use App\Models\ProtocolAdherence;
use App\Models\ProtocolItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shapes prescriptions for the doctor's screen and the patient's dashboard.
 *
 * Patients see each item's name, dose, instructions and how often to follow
 * it, plus their own ticks. Staff-only details are left out: the doctor's
 * notes, adjustment and discontinuation reasons, earlier versions and who
 * prescribed what.
 */
final class PrescriptionPresenter
{
    /**
     * How many days of adherence the doctor's screen summarises.
     */
    public const ADHERENCE_WINDOW = 30;

    /**
     * @param  Prescription  $prescription  with items.adherences and prescriber loaded
     * @return array<string, mixed>
     */
    public static function forStaff(Prescription $prescription): array
    {
        $since = Carbon::parse(Prescription::today())->subDays(self::ADHERENCE_WINDOW - 1)->toDateString();

        return [
            'id' => $prescription->id,
            'title' => $prescription->title,
            'notes' => $prescription->notes,
            ...self::status($prescription),
            'starts_on' => $prescription->starts_on->toDateString(),
            'ends_on' => $prescription->ends_on?->toDateString(),
            'prescriber' => $prescription->prescriber?->name,
            'created_at' => $prescription->created_at?->toIso8601String(),
            'discontinued_at' => $prescription->discontinued_at?->toIso8601String(),
            'discontinued_reason' => $prescription->discontinued_reason,
            'items' => $prescription->items
                ->map(fn (ProtocolItem $item) => [
                    ...self::item($item),
                    'current' => ! $item->isDiscontinued(),
                    'supersedes_id' => $item->supersedes_id,
                    'adjustment_reason' => $item->adjustment_reason,
                    'created_at' => $item->created_at?->toIso8601String(),
                    'discontinued_at' => $item->discontinued_at?->toIso8601String(),
                    'adherence' => self::adherenceSummary($item, $since),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The patient's dashboard: items in effect today, each with the last few
     * days to tick off.
     *
     * @param  Collection<int, Prescription>  $prescriptions  in effect today, with currentItems.adherences loaded
     * @return array<int, array<string, mixed>>
     */
    public static function forPatient(Collection $prescriptions): array
    {
        $days = self::trackedDays();

        return $prescriptions
            ->map(fn (Prescription $prescription) => [
                'id' => $prescription->id,
                'title' => $prescription->title,
                'starts_on' => $prescription->starts_on->toDateString(),
                'ends_on' => $prescription->ends_on?->toDateString(),
                'items' => $prescription->currentItems
                    ->map(function (ProtocolItem $item) use ($days, $prescription) {
                        $item->setRelation('prescription', $prescription);
                        $byDay = $item->adherences->keyBy(fn (ProtocolAdherence $adherence) => $adherence->scheduled_date->toDateString());

                        return [
                            ...self::item($item),
                            'days' => array_map(fn (string $day) => [
                                'date' => $day,
                                'scheduled' => $item->isScheduledOn($day),
                                'taken' => (bool) $byDay->get($day)?->taken,
                                'notes' => $byDay->get($day)?->notes,
                            ], $days),
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The days a patient may tick off, oldest first, ending today.
     *
     * @return array<int, string>
     */
    public static function trackedDays(): array
    {
        $today = Carbon::parse(Prescription::today());

        return array_map(
            fn (int $back) => $today->copy()->subDays($back)->toDateString(),
            range(ProtocolAdherence::DAYS_TRACKED - 1, 0),
        );
    }

    /**
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'types' => array_map(fn (ProtocolItemType $type) => ['value' => $type->value, 'label' => $type->label()], ProtocolItemType::cases()),
            'frequencies' => array_map(fn (ProtocolFrequency $frequency) => ['value' => $frequency->value, 'label' => $frequency->label()], ProtocolFrequency::cases()),
        ];
    }

    /**
     * An ended prescription is still active in the records but shown as
     * completed.
     *
     * @return array{status: string, status_label: string}
     */
    private static function status(Prescription $prescription): array
    {
        if ($prescription->status === PrescriptionStatus::Active && $prescription->hasEnded()) {
            return ['status' => 'completed', 'status_label' => 'Completed'];
        }

        return ['status' => $prescription->status->value, 'status_label' => $prescription->status->label()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(ProtocolItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'name' => $item->name,
            'dosage' => $item->dosage,
            'instructions' => $item->instructions,
            'frequency' => $item->frequency->value,
            'frequency_label' => $item->frequency->label(),
            'daily' => $item->frequency->isDaily(),
        ];
    }

    /**
     * Days ticked as taken out of the days the item was scheduled, within
     * the window.
     *
     * @return array{taken: int, scheduled: int}
     */
    private static function adherenceSummary(ProtocolItem $item, string $since): array
    {
        $scheduled = 0;

        for ($day = Carbon::parse($since); $day->toDateString() <= Prescription::today(); $day = $day->addDay()) {
            $scheduled += $item->isScheduledOn($day->toDateString()) ? 1 : 0;
        }

        return [
            'taken' => $item->adherences
                ->filter(fn (ProtocolAdherence $adherence) => $adherence->taken && $adherence->scheduled_date->toDateString() >= $since)
                ->count(),
            'scheduled' => $scheduled,
        ];
    }
}
