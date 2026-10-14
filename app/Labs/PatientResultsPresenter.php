<?php

namespace App\Labs;

use App\Enums\BiomarkerCategory;
use App\Models\LabReport;
use App\Models\LabResult;
use Illuminate\Support\Collection;

/**
 * Shapes a patient's own released results for the portal.
 *
 * Patients see each result's current value, unit, the lab's range and the
 * flag against it. Staff-only details are left out: report notes, result
 * comments, who entered what, and earlier versions with correction reasons.
 * A result corrected after release is marked "updated".
 */
final class PatientResultsPresenter
{
    /**
     * Every biomarker the patient has results for, grouped by category, with
     * the latest value and one trend series per unit. Values are never
     * converted, so results reported in different units are separate series.
     *
     * @param  Collection<int, LabReport>  $reports  released reports, with results.biomarker loaded
     * @return array<int, array<string, mixed>>
     */
    public static function trends(Collection $reports): array
    {
        $points = $reports
            ->sortBy('collected_at')
            ->flatMap(fn (LabReport $report) => self::currentResults($report)->map(fn (LabResult $result) => [$report, $result]));

        $markers = $points
            ->groupBy(fn (array $pair) => $pair[1]->biomarker_id)
            ->map(function (Collection $pairs) {
                [$latestReport, $latestResult] = $pairs->last();
                $biomarker = $latestResult->biomarker;

                return [
                    'id' => $biomarker->id,
                    'name' => $biomarker->name,
                    'category' => $biomarker->category,
                    'sort_order' => $biomarker->sort_order,
                    'latest' => self::point($latestReport, $latestResult),
                    'series' => $pairs
                        ->groupBy(fn (array $pair) => $pair[1]->unit)
                        ->map(fn (Collection $unitPairs, string $unit) => [
                            'unit' => $unit,
                            'points' => $unitPairs->map(fn (array $pair) => self::point($pair[0], $pair[1]))->values()->all(),
                        ])
                        ->values()
                        ->all(),
                ];
            });

        return collect(BiomarkerCategory::cases())
            ->map(fn (BiomarkerCategory $category) => [
                'category' => $category->value,
                'label' => $category->label(),
                'markers' => $markers
                    ->filter(fn (array $marker) => $marker['category'] === $category)
                    ->sortBy('sort_order')
                    ->map(fn (array $marker) => collect($marker)->except(['category', 'sort_order'])->all())
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group) => $group['markers'] !== [])
            ->values()
            ->all();
    }

    /**
     * One released report, as the patient sees it.
     *
     * @return array<string, mixed>
     */
    public static function report(LabReport $report): array
    {
        $groups = self::currentResults($report)
            ->sortBy(fn (LabResult $result) => [
                (int) array_search($result->biomarker->category, BiomarkerCategory::cases(), true),
                $result->biomarker->sort_order,
            ])
            ->groupBy(fn (LabResult $result) => $result->biomarker->category->value);

        return [
            ...self::summary($report),
            // The lab's original document is the patient's record too. Its
            // name and size stay internal; only a link is shared.
            'attachment_url' => $report->hasAttachment() ? route('my-results.attachment', $report->id) : null,
            'groups' => $groups->map(fn (Collection $results, string $category) => [
                'category' => $category,
                'label' => BiomarkerCategory::from($category)->label(),
                'results' => $results->map(fn (LabResult $result) => [
                    'biomarker' => $result->biomarker->name,
                    ...self::point($report, $result),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, collected: string, released: string|null, lab_name: string|null}
     */
    public static function summary(LabReport $report): array
    {
        $timezone = (string) config('clinic.timezone');

        return [
            'id' => $report->id,
            'collected' => $report->collected_at->copy()->setTimezone($timezone)->format('D j M Y'),
            'released' => $report->reviewed_at?->copy()->setTimezone($timezone)->format('D j M Y'),
            'lab_name' => $report->lab_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function point(LabReport $report, LabResult $result): array
    {
        $value = $result->labValue();
        $flag = $result->flag();
        $collected = $report->collected_at->copy()->setTimezone((string) config('clinic.timezone'));

        return [
            'report_id' => $report->id,
            'date' => $collected->format('Y-m-d'),
            'date_label' => $collected->format('j M Y'),
            'value' => $result->value,
            // Plotted only when exact: "<0.5" has no exact position.
            'number' => $value->comparator === null ? (float) $value->number : null,
            'unit' => $result->unit,
            'reference_low' => self::number($result->reference_low),
            'reference_high' => self::number($result->reference_high),
            'flag' => $flag?->value,
            'flag_label' => $flag?->label(),
            'updated' => $result->supersedes_id !== null
                && $report->reviewed_at !== null
                && $result->created_at?->greaterThan($report->reviewed_at) === true,
        ];
    }

    /**
     * @return Collection<int, LabResult>
     */
    private static function currentResults(LabReport $report): Collection
    {
        $replaced = $report->results->pluck('supersedes_id')->filter()->flip();

        return $report->results->reject(fn (LabResult $result) => $replaced->has($result->id))->values();
    }

    private static function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
