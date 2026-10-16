<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Labs\LabComparison;
use App\Models\Patient;
use App\Services\AuditLogger;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A clinician's cumulative view of one patient's lab work from every source,
 * including reports still under review. Clinicians only: partner labs never
 * see other labs' results. Authorized on the route; audited here.
 */
class LabHistoryController extends Controller
{
    public function __invoke(Patient $patient, AuditLogger $audit): Response
    {
        $reports = $patient->labReports()
            ->with(['results.biomarker', 'labPartner', 'requisition.labPartner'])
            ->get();

        $audit->record(AuditEvent::LabReportListed, auditable: $patient, metadata: [
            'context' => 'history',
            'results' => $reports->count(),
        ]);

        return Inertia::render('labs/History', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'medical_record_number' => $patient->medical_record_number,
            ],
            'history' => LabComparison::build($reports),
        ]);
    }
}
