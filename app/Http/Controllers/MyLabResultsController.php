<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LabReportStatus;
use App\Labs\PatientResultsPresenter;
use App\Models\LabReport;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient's own released lab results and trends.
 *
 * Reports are looked up only among the patient's own released reports, so
 * another patient's report, or one still under review, is a plain 404.
 */
class MyLabResultsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $patient = $request->user()?->patient;

        if (! $patient instanceof Patient) {
            return Inertia::render('labs/MyResults', ['linked' => false, 'groups' => [], 'reports' => []]);
        }

        $reports = $patient->labReports()
            ->where('status', LabReportStatus::Released)
            ->with('results.biomarker')
            ->orderBy('collected_at')
            ->get();

        $this->audit->record(AuditEvent::LabReportListed, auditable: $patient, metadata: [
            'context' => 'self',
            'results' => $reports->count(),
        ]);

        return Inertia::render('labs/MyResults', [
            'linked' => true,
            'groups' => PatientResultsPresenter::trends($reports),
            'reports' => $reports->sortByDesc('collected_at')
                ->map(fn (LabReport $report) => PatientResultsPresenter::summary($report))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, int $report): Response
    {
        $patient = $request->user()?->patient;
        abort_unless($patient instanceof Patient, 404);

        $labReport = $patient->labReports()
            ->where('status', LabReportStatus::Released)
            ->with('results.biomarker')
            ->findOrFail($report);

        Gate::authorize('viewLabReport', $labReport);

        $this->audit->record(AuditEvent::LabReportViewed, auditable: $labReport, metadata: ['context' => 'self']);

        return Inertia::render('labs/MyReport', [
            'report' => PatientResultsPresenter::report($labReport),
        ]);
    }
}
