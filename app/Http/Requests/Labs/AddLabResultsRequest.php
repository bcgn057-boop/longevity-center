<?php

namespace App\Http\Requests\Labs;

use App\Labs\LabResultRules;
use App\Models\LabReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * More results for a report still under review. A biomarker already on the
 * report must be corrected instead of entered again.
 */
class AddLabResultsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'results' => ['required', 'array', 'list', 'min:1', 'max:'.StoreLabReportRequest::MAX_RESULTS],
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
            'results.*.biomarker_id.distinct' => 'This biomarker is listed twice.',
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

            $report = $this->route('labReport');
            $existing = $report instanceof LabReport
                ? $report->currentResults()->pluck('biomarker_id')->all()
                : [];
            $rows = [];

            foreach ((array) $this->input('results') as $index => $row) {
                $rows["results.{$index}."] = $row;

                if (in_array((int) ($row['biomarker_id'] ?? 0), $existing, true)) {
                    $validator->errors()->add("results.{$index}.biomarker_id", 'This biomarker is already in the report. Correct it instead.');
                }
            }

            LabResultRules::checkRows($validator, $rows);
        }];
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
