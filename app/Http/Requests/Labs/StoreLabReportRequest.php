<?php

namespace App\Http\Requests\Labs;

use App\Enums\UserRole;
use App\Labs\LabResultRules;
use App\Models\LabRequisition;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
            // Partner labs only report against requisitions sent to them.
            'lab_requisition_id' => [$this->user()?->hasRole(UserRole::LabStaff) ? 'required' : 'nullable', 'integer'],
            'collected_date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01'],
            'collected_time' => ['required', 'date_format:H:i'],
            'lab_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // The lab's original report: a PDF of up to 10 MB.
            'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
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
            'lab_requisition_id.required' => 'Choose the requisition this report is for.',
            'attachment.mimes' => 'Attach the report as a PDF.',
            'attachment.max' => 'The PDF must be 10 MB or smaller.',
            'attachment.uploaded' => 'The PDF could not be uploaded. It may be larger than the server accepts.',
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

            if ($this->filled('lab_requisition_id')) {
                $requisition = $this->requisition();

                if ($requisition === null) {
                    $validator->errors()->add('lab_requisition_id', 'Choose one of your lab\'s open requisitions.');
                } elseif ($requisition->patient_id !== $this->integer('patient_id')) {
                    $validator->errors()->add('patient_id', 'The report must be for the patient on the requisition.');
                }
            }

            // The type check trusts what PHP detects; also insist the file
            // really starts like a PDF.
            $attachment = $this->file('attachment');

            if ($attachment instanceof UploadedFile && ! str_starts_with((string) file_get_contents($attachment->getRealPath(), length: 5), '%PDF-')) {
                $validator->errors()->add('attachment', 'Attach the report as a PDF.');
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

    /**
     * The requisition the report answers, if the user may report against it.
     */
    public function requisition(): ?LabRequisition
    {
        $user = $this->user();

        if (! $user instanceof User || ! $this->filled('lab_requisition_id')) {
            return null;
        }

        return LabRequisition::query()
            ->visibleTo($user)
            ->open()
            ->with('labPartner')
            ->find($this->integer('lab_requisition_id'));
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
