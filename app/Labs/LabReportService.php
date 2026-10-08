<?php

namespace App\Labs;

use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\User;
use App\Reminders\ReminderDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records lab reports and their results. Each write runs in a transaction,
 * so a failure part-way through leaves nothing half-saved.
 */
class LabReportService
{
    public function __construct(private readonly ReminderDispatcher $dispatcher) {}

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    public function create(Patient $patient, CarbonInterface $collectedAt, ?string $labName, ?string $notes, array $results, User $by): LabReport
    {
        return DB::transaction(function () use ($patient, $collectedAt, $labName, $notes, $results, $by) {
            $report = new LabReport([
                'collected_at' => $collectedAt,
                'lab_name' => $this->blankToNull($labName),
                'notes' => $this->blankToNull($notes),
            ]);
            $report->patient_id = $patient->id;
            $report->entered_by = $by->id;
            $report->save();

            $this->insertResults($report, $results, $by);

            return $report;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    public function addResults(LabReport $report, array $results, User $by): void
    {
        DB::transaction(fn () => $this->insertResults($report, $results, $by));
    }

    /**
     * @param  array{value: string, unit: string, reference_low?: string|null, reference_high?: string|null, comment?: string|null, biomarker_id?: int}  $attributes
     */
    public function correct(LabResult $result, array $attributes, string $reason, User $by): LabResult
    {
        return DB::transaction(fn () => $result->correct($attributes, $reason, $by));
    }

    /**
     * @throws ValidationException when the report has no results
     */
    public function release(LabReport $report, User $reviewer): void
    {
        if (! $report->currentResults()->exists()) {
            throw ValidationException::withMessages(['release' => 'A report with no results cannot be released.']);
        }

        $report->release($reviewer);

        // Generic "new results" notice, only on channels the patient agreed to.
        $this->dispatcher->announceLabResults($report);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function insertResults(LabReport $report, array $results, User $by): void
    {
        foreach ($results as $attributes) {
            $result = new LabResult($attributes);
            $result->lab_report_id = $report->id;
            $result->entered_by = $by->id;
            $result->save();
        }
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
