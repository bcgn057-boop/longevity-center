<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LabReportStatus;
use App\Enums\UserRole;
use App\Http\Requests\Labs\AddLabResultsRequest;
use App\Http\Requests\Labs\CorrectLabResultRequest;
use App\Http\Requests\Labs\StoreLabReportRequest;
use App\Labs\LabAttachmentStore;
use App\Labs\LabPresenter;
use App\Labs\LabReportService;
use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff entry, review and correction of lab results. Authorized on the routes
 * (role middleware plus LabReportPolicy); reads are audited here, writes by
 * the lab observers.
 */
class LabReportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LabReportService $labs,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...array_column(LabReportStatus::cases(), 'value')])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // Clinicians start on their review queue; lab staff on everything.
        $status = $filters['status'] ?? ($this->isClinician($request->user()) ? LabReportStatus::PendingReview->value : 'all');

        $reports = LabReport::query()
            ->with(['patient:id,first_name,last_name,medical_record_number,deleted_at', 'enteredBy:id,name'])
            ->withCount(['results' => fn (Builder $query) => $query->whereDoesntHave('successor')])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when(
                $status === LabReportStatus::PendingReview->value,
                fn (Builder $query) => $query->orderBy('collected_at'),
                fn (Builder $query) => $query->orderByDesc('collected_at'),
            )
            ->paginate(25)
            ->withQueryString();

        $this->audit->record(AuditEvent::LabReportListed, metadata: [
            'status' => $status,
            'page' => $reports->currentPage(),
            'results' => $reports->total(),
        ]);

        return Inertia::render('labs/Index', [
            'reports' => $reports->through(fn (LabReport $report) => LabPresenter::summary($report)),
            'filters' => ['status' => $status],
            'statuses' => array_map(
                fn (LabReportStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                LabReportStatus::cases(),
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer'],
            'patient_search' => ['nullable', 'string', 'max:100'],
        ]);

        $patient = isset($validated['patient_id']) ? Patient::query()->whereKey((int) $validated['patient_id'])->first() : null;
        $timezone = (string) config('clinic.timezone');

        return Inertia::render('labs/Create', [
            'patient' => $patient ? $this->patientOption($patient) : null,
            'patientOptions' => fn () => $this->searchPatients($validated['patient_search'] ?? null),
            'catalog' => LabPresenter::catalog(),
            'timezone' => $timezone,
            'now' => ['date' => Carbon::now($timezone)->format('Y-m-d'), 'time' => Carbon::now($timezone)->format('H:i')],
        ]);
    }

    public function store(StoreLabReportRequest $request): RedirectResponse
    {
        $report = $this->labs->create(
            Patient::query()->whereKey($request->integer('patient_id'))->firstOrFail(),
            $request->collectedAt(),
            $request->validated('lab_name'),
            $request->validated('notes'),
            $request->results(),
            $this->user($request),
            $request->file('attachment'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lab report saved and sent for review.')]);

        return to_route('labs.show', $report);
    }

    public function show(Request $request, LabReport $labReport): Response
    {
        $this->audit->record(AuditEvent::LabReportViewed, auditable: $labReport);

        $user = $this->user($request);

        return Inertia::render('labs/Show', [
            'report' => LabPresenter::report($labReport),
            'catalog' => LabPresenter::catalog(),
            'can' => [
                'addResults' => $user->can('enterLabResults', $labReport),
                'correct' => $user->can('correctLabResults', $labReport),
                'release' => $user->can('releaseLabReport', $labReport),
                'viewPatient' => $user->can('view', $labReport->patient),
            ],
        ]);
    }

    /**
     * Download the lab's original PDF. Authorized on the route; audited
     * here, by report id only.
     */
    public function attachment(LabReport $labReport, LabAttachmentStore $attachments): HttpResponse
    {
        abort_unless($labReport->hasAttachment(), 404);

        $response = $attachments->download($labReport);

        $this->audit->record(AuditEvent::LabReportAttachmentDownloaded, auditable: $labReport, metadata: [
            'patient_id' => $labReport->patient_id,
        ]);

        return $response;
    }

    public function addResults(AddLabResultsRequest $request, LabReport $labReport): RedirectResponse
    {
        $this->labs->addResults($labReport, $request->results(), $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results added.')]);

        return to_route('labs.show', $labReport);
    }

    public function correct(CorrectLabResultRequest $request, LabResult $labResult): RedirectResponse
    {
        Gate::authorize('correctLabResults', $labResult->report);

        $this->labs->correct($labResult, $request->correction(), $request->reason(), $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Correction recorded.')]);

        return to_route('labs.show', $labResult->lab_report_id);
    }

    public function release(Request $request, LabReport $labReport): RedirectResponse
    {
        $this->labs->release($labReport, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report released to the patient.')]);

        return to_route('labs.show', $labReport);
    }

    /**
     * Patients matching a search while entering a report, audited like any
     * other patient search. Lab staff see names and record numbers only.
     *
     * @return array<int, array{id: int, full_name: string, medical_record_number: string|null}>
     */
    private function searchPatients(?string $term): array
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $patients = Patient::query()->search($term)->orderBy('last_name')->orderBy('first_name')->limit(10)->get();

        $this->audit->record(AuditEvent::PatientSearched, metadata: [
            'context' => 'lab_entry',
            'search' => $term,
            'results' => $patients->count(),
        ]);

        return $patients->map(fn (Patient $patient) => $this->patientOption($patient))->all();
    }

    /**
     * @return array{id: int, full_name: string, medical_record_number: string|null}
     */
    private function patientOption(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'full_name' => $patient->full_name,
            'medical_record_number' => $patient->medical_record_number,
        ];
    }

    private function isClinician(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(UserRole::Doctor, UserRole::Practitioner);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
