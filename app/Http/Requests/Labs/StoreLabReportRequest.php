<?php

namespace App\Http\Requests\Labs;

use App\Labs\LabResultRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new lab report with its results. The collection time is entered in the
 * clinic's timezone.
 */
class StoreLabReportRequest extends FormRequest
{
    public const MAX_RESULTS = 100;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('deleted_at')],
            'collected_date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01'],
            'collected_time' => ['required', 'date_format:H:i'],
            'lab_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'results' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_RESULTS],
            'results.*' => ['array'],
            ...LabResultRules::row('results.*.'),
        ];

        // Added after the shared row rules, which define the same key.
        $rules['results.*.biomarker_id'][] = 'distinct';

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.required' => 'Choose a patient.',
            'patient_id.exists' => 'Choose an active patient.',
            'results.required' => 'Add at least one result.',
            'results.min' => 'Add at least one result.',
            'results.*.biomarker_id.distinct' => 'This biomarker is already in the report.',
            'results.*.biomarker_id.required' => 'Choose a biomarker.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->collectedAt()->isFuture()) {
                $validator->errors()->add('collected_time', 'The collection time cannot be in the future.');
            }

            $rows = [];

            foreach ((array) $this->input('results') as $index => $row) {
                $rows["results.{$index}."] = $row;
            }

            LabResultRules::checkRows($validator, $rows);
        }];
    }

    public function collectedAt(): Carbon
    {
        return Carbon::createFromFormat(
            '!Y-m-d H:i',
            $this->input('collected_date').' '.$this->input('collected_time'),
            (string) config('clinic.timezone'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function results(): array
    {
        return array_map(
            fn (array $row) => LabResultRules::attributes($row),
            array_values((array) $this->validated('results')),
        );
    }
}
