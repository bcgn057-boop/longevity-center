<?php

namespace App\Assessments;

use App\Enums\AssessmentKind;
use App\Models\FunctionalAssessment;
use Illuminate\Support\Collection;

/**
 * Assessments for the clinician's page and the patient's portal: the field
 * definitions, the latest value of each measurement, and the history.
 */
final class AssessmentPresenter
{
    /**
     * @return array<int, array{kind: string, label: string, fields: array<int, array<string, mixed>>}>
     */
    public static function forms(): array
    {
        return array_map(fn (AssessmentKind $kind) => [
            'kind' => $kind->value,
            'label' => $kind->label(),
            'fields' => array_map(
                fn (string $field, array $spec) => ['field' => $field, ...$spec],
                array_keys($kind->fields()),
                $kind->fields(),
            ),
        ], AssessmentKind::cases());
    }

    /**
     * The most recent value of every measurement, with its date.
     *
     * @param  Collection<int, FunctionalAssessment>  $assessments  newest first
     * @return array<int, array<string, mixed>>
     */
    public static function latest(Collection $assessments): array
    {
        $latest = [];

        foreach (AssessmentKind::cases() as $kind) {
            foreach ($kind->fields() as $field => $spec) {
                $entry = $assessments->first(fn (FunctionalAssessment $a) => $a->kind === $kind && isset($a->measurements[$field]));

                if ($entry !== null) {
                    $latest[] = [
                        'field' => $field,
                        'label' => $spec['label'],
                        'unit' => $spec['unit'],
                        'value' => $entry->measurements[$field],
                        'date' => $entry->measured_on->format('j M Y'),
                    ];
                }
            }
        }

        $withBmi = $assessments->first(fn (FunctionalAssessment $a) => $a->bmi() !== null);

        if ($withBmi !== null) {
            $latest[] = ['field' => 'bmi', 'label' => 'Body mass index', 'unit' => 'kg/m²', 'value' => $withBmi->bmi(), 'date' => $withBmi->measured_on->format('j M Y')];
        }

        return $latest;
    }

    /**
     * @param  Collection<int, FunctionalAssessment>  $assessments  newest first, with recorder loaded
     * @return array<int, array<string, mixed>>
     */
    public static function history(Collection $assessments, bool $showRecorder): array
    {
        return $assessments->map(fn (FunctionalAssessment $assessment) => [
            'id' => $assessment->id,
            'kind' => $assessment->kind->value,
            'kind_label' => $assessment->kind->label(),
            'measured_on' => $assessment->measured_on->format('j M Y'),
            'values' => array_values(array_filter(array_map(
                fn (string $field, array $spec) => isset($assessment->measurements[$field])
                    ? ['label' => $spec['label'], 'unit' => $spec['unit'], 'value' => $assessment->measurements[$field]]
                    : null,
                array_keys($assessment->kind->fields()),
                $assessment->kind->fields(),
            ))),
            'bmi' => $assessment->bmi(),
            'notes' => $assessment->notes,
            'via' => $assessment->recorded_via,
            'recorded_by' => $showRecorder ? $assessment->recorder?->name : null,
        ])->all();
    }
}
