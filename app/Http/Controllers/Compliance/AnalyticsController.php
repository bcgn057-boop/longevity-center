<?php

namespace App\Http\Controllers\Compliance;

use App\Compliance\PracticeMetrics;
use App\Compliance\ReportPeriod;
use App\Compliance\StaffActivity;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aggregate reports for the clinic: practice figures and staff activity.
 * Counts and rates only, never a patient's details. Authorized on the
 * routes; every view is audited.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function practice(Request $request): Response
    {
        $period = $this->period($request, 'practice');

        return Inertia::render('compliance/Analytics', [
            'period' => $period->toArray(),
            'metrics' => PracticeMetrics::for($period),
            'minGroupSize' => PracticeMetrics::MIN_GROUP_SIZE,
            'can' => ['viewStaffActivity' => (bool) $request->user()?->can('viewStaffActivity')],
        ]);
    }

    public function staff(Request $request): Response
    {
        $period = $this->period($request, 'staff_activity');

        return Inertia::render('compliance/StaffActivity', [
            'period' => $period->toArray(),
            'columns' => array_map(fn (array $column) => $column['label'], StaffActivity::columns()),
            'staff' => StaffActivity::for($period),
            'can' => ['viewAuditLogs' => (bool) $request->user()?->can('viewAuditLogs')],
        ]);
    }

    private function period(Request $request, string $report): ReportPeriod
    {
        $validator = Validator::make($request->query(), ReportPeriod::rules());
        $validator->after(fn ($validator) => ReportPeriod::checkLength($validator));
        $period = ReportPeriod::fromValidated($validator->validate());

        $this->audit->record(AuditEvent::AnalyticsViewed, metadata: [
            'report' => $report,
            'from' => $period->from,
            'to' => $period->to,
        ]);

        return $period;
    }
}
