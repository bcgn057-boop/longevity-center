<?php

namespace App\Http\Requests\Labs;

use App\Labs\LabResultRules;
use App\Models\LabResult;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A corrected value for one result, with the reason for the correction.
 */
class CorrectLabResultRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...LabResultRules::row(withBiomarker: false),
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for the correction.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $result = $this->route('labResult');

            if ($validator->errors()->isNotEmpty() || ! $result instanceof LabResult) {
                return;
            }

            if ($result->successor()->exists()) {
                $validator->errors()->add('value', 'This result has already been corrected. Correct the latest version.');

                return;
            }

            LabResultRules::checkRows($validator, ['' => $this->all()], $result->biomarker);
        }];
    }

    /**
     * @return array{value: string, unit: string, reference_low: string|null, reference_high: string|null, comment: string|null, biomarker_id?: int}
     */
    public function correction(): array
    {
        return LabResultRules::attributes($this->validated(), withBiomarker: false);
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
