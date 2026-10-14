<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LabReportStatus;
use App\Labs\LabAttachmentStore;
use App\Labs\PatientResultsPresenter;
use App\Models\LabReport;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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

    /**
     * The lab's original PDF for one of the patient's own released reports.
     * Looked up like show(), so anything else is a plain 404.
     */
    public function attachment(Request $request, int $report, LabAttachmentStore $attachments): HttpResponse
    {
        $labReport = $this->ownReleased($request, $report);
        abort_unless($labReport->hasAttachment(), 404);

        Gate::authorize('viewLabReport', $labReport);

        $response = $attachments->download($labReport);

        $this->audit->record(AuditEvent::LabReportAttachmentDownloaded, auditable: $labReport, metadata: [
            'context' => 'self',
            'patient_id' => $labReport->patient_id,
        ]);

        return $response;
    }

    public function show(Request $request, int $report): Response
    {
        $labReport = $this->ownReleased($request, $report);

        Gate::authorize('viewLabReport', $labReport);

        $this->audit->record(AuditEvent::LabReportViewed, auditable: $labReport, metadata: ['context' => 'self']);

        return Inertia::render('labs/MyReport', [
            'report' => PatientResultsPresenter::report($labReport),
        ]);
    }

    /**
     * One of the signed-in patient's own released reports, or a 404.
     */
    private function ownReleased(Request $request, int $report): LabReport
    {
        $patient = $request->user()?->patient;
        abort_unless($patient instanceof Patient, 404);

        return $patient->labReports()
            ->where('status', LabReportStatus::Released)
            ->with('results.biomarker')
            ->findOrFail($report);
    }
}
