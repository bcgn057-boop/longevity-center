<?php

namespace App\Labs;

use App\Models\Biomarker;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation shared by every form that records result values.
 */
final class LabResultRules
{
    /**
     * Rules for one result row, with keys prefixed (e.g. "results.*.").
     *
     * @return array<string, array<int, mixed>>
     */
    public static function row(string $prefix = '', bool $withBiomarker = true): array
    {
        $rules = [
            "{$prefix}value" => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! LabValue::isValid($value)) {
                    $fail('Enter a number, optionally starting with <, <=, > or >=.');
                }
            }],
            "{$prefix}unit" => ['required', 'string', 'max:32'],
            "{$prefix}reference_low" => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            "{$prefix}reference_high" => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            "{$prefix}comment" => ['nullable', 'string', 'max:500'],
        ];

        if ($withBiomarker) {
            $rules = [
                "{$prefix}biomarker_id" => ['required', 'integer', Rule::exists('biomarkers', 'id')->where('is_active', true)],
                ...$rules,
            ];
        }

        return $rules;
    }

    /**
     * Check each row's unit against its biomarker and that its range is the
     * right way round. Runs only once the basic rules have passed.
     *
     * @param  array<int|string, mixed>  $rows  keyed by the error prefix of each row
     * @param  Biomarker|null  $biomarker  the biomarker for every row, when fixed (corrections)
     */
    public static function checkRows(Validator $validator, array $rows, ?Biomarker $biomarker = null): void
    {
        /** @var Collection<int, Biomarker> $biomarkers */
        $biomarkers = Biomarker::whereIn('id', collect($rows)->pluck('biomarker_id')->filter()->all())->get()->keyBy('id');

        foreach ($rows as $prefix => $row) {
            if (! is_array($row)) {
                continue;
            }

            $marker = $biomarker ?? $biomarkers->get((int) ($row['biomarker_id'] ?? 0));
            $unit = (string) ($row['unit'] ?? '');

            if ($marker !== null && ! $marker->allowsUnit($unit)) {
                $validator->errors()->add("{$prefix}unit", "{$marker->name} is recorded in ".implode(' or ', $marker->units).'.');
            }

            $low = $row['reference_low'] ?? null;
            $high = $row['reference_high'] ?? null;

            if (is_numeric($low) && is_numeric($high) && (float) $low > (float) $high) {
                $validator->errors()->add("{$prefix}reference_high", 'The upper limit must not be below the lower limit.');
            }
        }
    }

    /**
     * The attributes to save for one validated row, with empty strings as null.
     *
     * @param  array<string, mixed>  $row
     * @return array{value: string, unit: string, reference_low: string|null, reference_high: string|null, comment: string|null, biomarker_id?: int}
     */
    public static function attributes(array $row, bool $withBiomarker = true): array
    {
        $blank = fn (mixed $value): ?string => $value === null || trim((string) $value) === '' ? null : trim((string) $value);

        $attributes = [
            'value' => (string) $row['value'],
            'unit' => (string) $row['unit'],
            'reference_low' => $blank($row['reference_low'] ?? null),
            'reference_high' => $blank($row['reference_high'] ?? null),
            'comment' => $blank($row['comment'] ?? null),
        ];

        if ($withBiomarker) {
            $attributes['biomarker_id'] = (int) $row['biomarker_id'];
        }

        return $attributes;
    }
}
