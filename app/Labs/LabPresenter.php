<?php

namespace App\Labs;

use App\Enums\BiomarkerCategory;
use App\Models\Biomarker;
use App\Models\LabReport;
use App\Models\LabResult;
use Illuminate\Support\Collection;

/**
 * Shapes lab reports and the biomarker catalog for pages. Values are only
 * decrypted here, for viewers who passed the lab access checks.
 */
final class LabPresenter
{
    /**
     * A full report: current results grouped by category, each with the
     * versions it replaced.
     *
     * @return array<string, mixed>
     */
    public static function report(LabReport $report): array
    {
        $report->loadMissing(['patient', 'enteredBy:id,name', 'reviewer:id,name', 'results.biomarker', 'results.enteredBy:id,name']);

        $results = $report->results;
        $byId = $results->keyBy('id');
        $replaced = $results->pluck('supersedes_id')->filter()->flip();

        $groups = $results
            ->reject(fn (LabResult $result) => $replaced->has($result->id))
            ->sortBy(fn (LabResult $result) => [self::categoryOrder($result->biomarker->category), $result->biomarker->sort_order])
            ->groupBy(fn (LabResult $result) => $result->biomarker->category->value);

        return [
            ...self::summary($report),
            'notes' => $report->notes,
            'attachment' => $report->hasAttachment() ? [
                'name' => $report->attachment_name,
                'size' => $report->attachment_size,
                'url' => route('labs.attachment', $report),
            ] : null,
            'reviewed_by' => $report->reviewer?->name,
            'reviewed_at' => $report->reviewed_at?->copy()->setTimezone(self::timezone())->format('D j M Y H:i'),
            'groups' => $groups->map(fn (Collection $items, string $category) => [
                'category' => $category,
                'label' => BiomarkerCategory::from($category)->label(),
                'results' => $items->map(fn (LabResult $result) => [
                    ...self::result($result),
                    'history' => self::history($result, $byId),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * A report's list row: no values.
     *
     * @return array<string, mixed>
     */
    public static function summary(LabReport $report): array
    {
        return [
            'id' => $report->id,
            'patient' => [
                'id' => $report->patient->id,
                'full_name' => $report->patient->full_name,
                'medical_record_number' => $report->patient->medical_record_number,
            ],
            'collected' => $report->collected_at->copy()->setTimezone(self::timezone())->format('D j M Y H:i'),
            'lab_name' => $report->lab_name,
            'source' => $report->sourceName(),
            'lab_partner_id' => $report->lab_partner_id,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'entered_by' => $report->enteredBy?->name,
            'results_count' => $report->getAttribute('results_count'),
            'has_attachment' => $report->hasAttachment(),
        ];
    }

    /**
     * Active biomarkers grouped by category, for choosing in forms.
     *
     * @return array<int, array{category: string, label: string, biomarkers: array<int, array{id: int, code: string, name: string, units: array<int, string>}>}>
     */
    public static function catalog(): array
    {
        $biomarkers = Biomarker::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->groupBy(fn (Biomarker $biomarker) => $biomarker->category->value);

        return collect(BiomarkerCategory::cases())
            ->filter(fn (BiomarkerCategory $category) => $biomarkers->has($category->value))
            ->map(fn (BiomarkerCategory $category) => [
                'category' => $category->value,
                'label' => $category->label(),
                'biomarkers' => $biomarkers->get($category->value, collect())->map(fn (Biomarker $biomarker) => [
                    'id' => $biomarker->id,
                    'code' => $biomarker->code,
                    'name' => $biomarker->name,
                    'units' => $biomarker->units,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(LabResult $result): array
    {
        $flag = $result->flag();
        $low = self::number($result->reference_low);
        $high = self::number($result->reference_high);

        return [
            'id' => $result->id,
            'biomarker' => [
                'id' => $result->biomarker->id,
                'name' => $result->biomarker->name,
                'units' => $result->biomarker->units,
            ],
            'value' => $result->value,
            'unit' => $result->unit,
            'reference_low' => $low,
            'reference_high' => $high,
            'range' => match (true) {
                $low !== null && $high !== null => "{$low}–{$high}",
                $high !== null => "≤ {$high}",
                $low !== null => "≥ {$low}",
                default => null,
            },
            'flag' => $flag?->value,
            'flag_label' => $flag?->label(),
            'comment' => $result->comment,
            'correction_reason' => $result->correction_reason,
            'entered_by' => $result->enteredBy?->name,
            'entered_at' => $result->created_at?->copy()->setTimezone(self::timezone())->format('D j M Y H:i'),
        ];
    }

    /**
     * The versions a result replaced, newest first.
     *
     * @param  Collection<int, LabResult>  $byId
     * @return array<int, array<string, mixed>>
     */
    private static function history(LabResult $result, Collection $byId): array
    {
        $history = [];
        $previous = $result->supersedes_id !== null ? $byId->get($result->supersedes_id) : null;

        while ($previous !== null) {
            $history[] = self::result($previous);
            $previous = $previous->supersedes_id !== null ? $byId->get($previous->supersedes_id) : null;
        }

        return $history;
    }

    /**
     * "40.0000" → "40", "0.8500" → "0.85".
     */
    private static function number(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return in_array($trimmed, ['', '-', '-0'], true) ? '0' : $trimmed;
    }

    private static function categoryOrder(BiomarkerCategory $category): int
    {
        return (int) array_search($category, BiomarkerCategory::cases(), true);
    }

    private static function timezone(): string
    {
        return (string) config('clinic.timezone');
    }
}
