<?php

namespace App\Labs;

use App\Enums\BiomarkerCategory;
use App\Enums\ResultFlag;
use App\Models\LabReport;
use App\Models\LabResult;
use Illuminate\Support\Collection;

/**
 * One patient's reports from every lab, put together: which labs they came
 * from, a timeline, and a side-by-side table of each biomarker across
 * reports. Values are shown as each lab reported them and never converted,
 * so a different unit stays visible in its cell.
 */
final class LabComparison
{
    /**
     * Columns in the side-by-side table: the most recent reports.
     */
    public const MAX_COLUMNS = 12;

    /**
     * @param  Collection<int, LabReport>  $reports  with results.biomarker, labPartner and requisition.labPartner loaded
     * @return array{sources: array<int, array<string, mixed>>, timeline: array<int, array<string, mixed>>, comparison: array<string, mixed>}
     */
    public static function build(Collection $reports): array
    {
        $timezone = (string) config('clinic.timezone');
        $oldestFirst = $reports->sortBy(fn (LabReport $report) => [$report->collected_at->getTimestamp(), $report->id])->values();
        $columns = $oldestFirst->slice(-self::MAX_COLUMNS)->values();
        $current = $oldestFirst->mapWithKeys(fn (LabReport $report) => [$report->id => self::currentResults($report)]);

        return [
            'sources' => $oldestFirst
                ->groupBy(fn (LabReport $report) => $report->sourceName())
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'partner' => $group->first()?->lab_partner_id !== null,
                    'reports' => $group->count(),
                    'latest' => $group->last()?->collected_at->copy()->setTimezone($timezone)->format('j M Y'),
                ])
                ->sortByDesc('reports')
                ->values()
                ->all(),
            'timeline' => $oldestFirst->reverse()->map(function (LabReport $report) use ($current, $timezone) {
                $results = $current[$report->id];

                return [
                    'id' => $report->id,
                    'date' => $report->collected_at->copy()->setTimezone($timezone)->format('Y-m-d'),
                    'date_label' => $report->collected_at->copy()->setTimezone($timezone)->format('D j M Y'),
                    'source' => $report->sourceName(),
                    'status' => $report->status->value,
                    'status_label' => $report->status->label(),
                    'tests' => $report->requisition?->testLabels() ?? [],
                    'results' => $results->count(),
                    'flagged' => $results->filter(fn (LabResult $result) => in_array($result->flag(), [ResultFlag::Low, ResultFlag::High], true))->count(),
                ];
            })->values()->all(),
            'comparison' => [
                'columns' => $columns->map(fn (LabReport $report) => [
                    'id' => $report->id,
                    'date_label' => $report->collected_at->copy()->setTimezone($timezone)->format('j M Y'),
                    'source' => $report->sourceName(),
                    'status' => $report->status->value,
                ])->all(),
                'groups' => self::groups($columns, $current),
                'hidden' => max(0, $oldestFirst->count() - self::MAX_COLUMNS),
            ],
        ];
    }

    /**
     * Biomarkers by category, each with one cell per column (null where the
     * report has no value for it).
     *
     * @param  Collection<int, LabReport>  $columns
     * @param  Collection<int, Collection<int, LabResult>>  $current
     * @return array<int, array<string, mixed>>
     */
    private static function groups(Collection $columns, Collection $current): array
    {
        $cells = [];
        $markers = [];

        foreach ($columns as $index => $report) {
            foreach ($current[$report->id] as $result) {
                $markers[$result->biomarker_id] = $result->biomarker;
                $flag = $result->flag();
                $cells[$result->biomarker_id][$index] = [
                    'value' => $result->value,
                    'unit' => $result->unit,
                    'flag' => $flag?->value,
                    'flag_label' => $flag?->label(),
                ];
            }
        }

        return collect($markers)
            ->sortBy(fn ($marker) => [(int) array_search($marker->category, BiomarkerCategory::cases(), true), $marker->sort_order])
            ->groupBy(fn ($marker) => $marker->category->value)
            ->map(fn (Collection $group, string $category) => [
                'category' => $category,
                'label' => BiomarkerCategory::from($category)->label(),
                'rows' => $group->map(fn ($marker) => [
                    'biomarker' => $marker->name,
                    'cells' => array_map(fn (int $index) => $cells[$marker->id][$index] ?? null, range(0, max(0, $columns->count() - 1))),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, LabResult>
     */
    private static function currentResults(LabReport $report): Collection
    {
        $replaced = $report->results->pluck('supersedes_id')->filter()->flip();

        return $report->results->reject(fn (LabResult $result) => $replaced->has($result->id))->values();
    }
}
