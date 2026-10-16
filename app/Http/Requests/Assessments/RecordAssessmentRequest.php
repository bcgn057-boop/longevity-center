<?php

namespace App\Http\Requests\Assessments;

use App\Enums\AssessmentKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One day's measurements of one kind. Each value must be in its plausible
 * range; at least one is needed, and blood pressure comes as a pair.
 */
class RecordAssessmentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'kind' => ['required', Rule::enum(AssessmentKind::class)],
            'measured_on' => ['required', 'date_format:Y-m-d', 'after:1900-01-01'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        foreach ($this->kind()?->fields() ?? [] as $field => $spec) {
            $rules[$field] = ['nullable', 'numeric', "between:{$spec['min']},{$spec['max']}"];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $kind = $this->kind();

            if ($validator->errors()->isNotEmpty() || $kind === null) {
                return;
            }

            if ($this->measurements() === []) {
                $validator->errors()->add('measurements', 'Enter at least one measurement.');
            }

            if ($kind === AssessmentKind::AtHome) {
                $systolic = $this->input('systolic_bp');
                $diastolic = $this->input('diastolic_bp');

                if (filled($systolic) !== filled($diastolic)) {
                    $validator->errors()->add('systolic_bp', 'Enter both blood pressure numbers, or neither.');
                } elseif (filled($systolic) && (float) $systolic <= (float) $diastolic) {
                    $validator->errors()->add('systolic_bp', 'The systolic (top) number must be higher than the diastolic.');
                }
            }

            if ($this->input('measured_on') > now((string) config('clinic.timezone'))->toDateString()) {
                $validator->errors()->add('measured_on', 'The date cannot be in the future.');
            }
        }];
    }

    public function kind(): ?AssessmentKind
    {
        return AssessmentKind::tryFrom((string) $this->input('kind'));
    }

    /**
     * The values entered, as numbers.
     *
     * @return array<string, int|float>
     */
    public function measurements(): array
    {
        $values = [];

        foreach ($this->kind()?->fields() ?? [] as $field => $spec) {
            if (filled($this->input($field))) {
                $number = (float) $this->input($field);
                $values[$field] = $spec['step'] >= 1 ? (int) round($number) : round($number, 1);
            }
        }

        return $values;
    }

    public function measuredOn(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', (string) $this->input('measured_on'));
    }
}
