<?php

namespace App\Http\Controllers;

use App\Assessments\AssessmentPresenter;
use App\Enums\AuditEvent;
use App\Enums\Sex;
use App\Http\Requests\Patients\StorePatientRequest;
use App\Http\Requests\Patients\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Intake\IntakePresenter;
use App\Labs\LabPresenter;
use App\Labs\LabRequisitionPresenter;
use App\Models\Appointment;
use App\Models\CareReminder;
use App\Models\EmergencyContact;
use App\Models\FunctionalAssessment;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\LabRequisition;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use App\Models\Prescription;
use App\Reminders\NotificationConsent;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

/**
 * Staff management of patient records. Authorization is enforced on the
 * routes (role middleware plus PatientPolicy); every read is audited here,
 * every write by PatientObserver.
 */
class PatientController extends Controller
{
    /**
     * The tabs of the patient workspace.
     *
     * @var array<int, string>
     */
    public const TABS = ['overview', 'care-reminders', 'lab-reports', 'lab-requisitions', 'order-tests', 'measurements'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $patients = Patient::query()
            ->search($filters['search'] ?? null)
            ->with('user:id')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        // Listing names is itself access to patient data.
        $this->audit->record(AuditEvent::PatientSearched, metadata: [
            'search' => $filters['search'] ?? null,
            'page' => $patients->currentPage(),
            'results' => $patients->total(),
        ]);

        return Inertia::render('patients/Index', [
            'patients' => $patients->through(fn (Patient $patient) => [
                'id' => $patient->id,
                'medical_record_number' => $patient->medical_record_number,
                'full_name' => $patient->full_name,
                'sex_label' => $patient->sex->label(),
                'has_account' => $patient->user_id !== null,
            ]),
            'filters' => ['search' => $filters['search'] ?? ''],
            'can' => ['create' => $request->user()?->can('create', Patient::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('patients/Create', [
            'sexes' => $this->sexOptions(),
            'canEditMedical' => (bool) $request->user()?->can('updateMedical', Patient::class),
        ]);
    }

    public function store(StorePatientRequest $request): RedirectResponse
    {
        $patient = DB::transaction(function () use ($request) {
            $patient = new Patient([...$request->demographics(), ...$request->medical()]);
            $patient->created_by = $request->user()?->id;
            $patient->save();

            $patient->emergencyContacts()->createMany($request->emergencyContacts() ?? []);

            return $patient;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient created.')]);

        return to_route('patients.show', $patient);
    }

    public function show(Request $request, Patient $patient, NotificationConsent $consent): Response
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(self::TABS)]])['tab'] ?? 'overview';

        // Opening the measurements tab reloads only that tab's data, which
        // is audited on its own; it discloses nothing else from the record.
        $onlyMeasurements = $request->header(Header::PARTIAL_COMPONENT) === 'patients/Show'
            && $request->header(Header::PARTIAL_ONLY) === 'measurements';

        if (! $onlyMeasurements) {
            $this->recordView($request, $patient, 'show');
        }

        $user = $request->user();
        $measurements = fn () => $this->measurements($request, $patient);
        $canManageConsent = (bool) $user?->can('manageConsent', $patient);
        $canManageCareReminders = (bool) $user?->can('manageCareReminders', $patient);

        return Inertia::render('patients/Show', [
            'patient' => $this->present($request, $patient),
            'consent' => $canManageConsent ? $consent->summary($patient) : null,
            'careReminders' => $canManageCareReminders ? $this->careReminders($patient) : null,
            'labReports' => $user?->can('viewLabReports', LabReport::class) ? $this->labReports($patient) : null,
            'tab' => $tab,
            // Loaded when the tab is opened.
            'measurements' => ! $user?->can('viewAssessments', FunctionalAssessment::class)
                ? null
                : ($tab === 'measurements' ? $measurements : Inertia::optional($measurements)),
            'labRequisitions' => $user?->can('orderLabTests', LabRequisition::class) ? $this->labRequisitions($patient) : null,
            'labPartners' => $user?->can('orderLabTests', LabRequisition::class)
                ? LabPartner::query()->active()->orderBy('name')->get()->map->toOption()->all()
                : [],
            'can' => [
                'update' => $request->user()?->can('update', $patient),
                'archive' => $request->user()?->can('archive', $patient),
                'linkAccount' => $request->user()?->can('linkAccount', $patient),
                'viewMedical' => $request->user()?->can('viewMedical', $patient),
                'bookAppointment' => ! $patient->trashed() && $request->user()?->can('create', Appointment::class),
                'manageConsent' => $canManageConsent,
                'manageCareReminders' => $canManageCareReminders,
                'addLabReport' => ! $patient->trashed() && (bool) $user?->can('enterLabResults', LabReport::class),
                'manageIntake' => ! $patient->trashed() && (bool) $user?->can('manageIntake', PatientIntakeForm::class),
                'viewAssessments' => (bool) $user?->can('viewAssessments', FunctionalAssessment::class),
                'orderLabTests' => ! $patient->trashed() && (bool) $user?->can('orderLabTests', LabRequisition::class),
                'viewPrescriptions' => ! $patient->trashed() && (bool) $user?->can('viewPrescriptions', Prescription::class),
            ],
        ]);
    }

    public function edit(Request $request, Patient $patient): Response
    {
        $this->recordView($request, $patient, 'edit');

        return Inertia::render('patients/Edit', [
            'patient' => $this->present($request, $patient),
            'sexes' => $this->sexOptions(),
            'canEditMedical' => (bool) $request->user()?->can('updateMedical', $patient),
        ]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        DB::transaction(function () use ($request, $patient) {
            $patient->fill([...$request->demographics(), ...$request->medical()])->save();

            $contacts = $request->emergencyContacts();

            if ($contacts !== null && $contacts !== $this->currentContacts($patient)) {
                $patient->emergencyContacts()->delete();
                $patient->emergencyContacts()->createMany($contacts);

                $this->audit->record(
                    AuditEvent::PatientUpdated,
                    auditable: $patient,
                    metadata: ['fields' => ['emergency_contacts']],
                );
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient updated.')]);

        return to_route('patients.show', $patient);
    }

    /**
     * Archive the patient. Records are retained, never permanently deleted.
     */
    public function destroy(Patient $patient): RedirectResponse
    {
        $patient->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient archived.')]);

        return to_route('patients.index');
    }

    private function recordView(Request $request, Patient $patient, string $context): void
    {
        $sections = ['demographics', 'contact', 'emergency_contacts'];

        if ($request->user()?->can('viewMedical', $patient)) {
            $sections[] = 'medical';
        }

        if ($context === 'show' && $request->user()?->can('manageCareReminders', $patient)) {
            $sections[] = 'care_reminders';
        }

        if ($context === 'show' && $request->user()?->can('viewLabReports', LabReport::class)) {
            $sections[] = 'lab_reports';
        }

        $this->audit->record(
            AuditEvent::PatientViewed,
            auditable: $patient,
            metadata: ['context' => $context, 'sections' => $sections],
        );
    }

    /**
     * The patient's lab reports, newest first, without values.
     *
     * @return array<int, array<string, mixed>>
     */
    private function labReports(Patient $patient): array
    {
        return $patient->labReports()
            ->with(['patient', 'enteredBy:id,name'])
            ->withCount(['results' => fn ($query) => $query->whereDoesntHave('successor')])
            ->orderByDesc('collected_at')
            ->limit(50)
            ->get()
            ->map(fn (LabReport $report) => LabPresenter::summary($report))
            ->all();
    }

    /**
     * The measurements tab: measurements and functional tests, and the
     * intake questionnaire. Audited when loaded.
     *
     * @return array<string, mixed>
     */
    private function measurements(Request $request, Patient $patient): array
    {
        $assessments = $patient->assessments()->with('recorder:id,name')->get();
        $forms = $patient->intakeForms()->with('doctor:id,name')->get();
        $current = $forms->first();

        $this->audit->record(AuditEvent::AssessmentsViewed, auditable: $patient, metadata: ['context' => 'patient_tab', 'results' => $assessments->count()]);

        if ($current instanceof PatientIntakeForm) {
            $this->audit->record(AuditEvent::IntakeViewed, auditable: $current, metadata: ['patient_id' => $patient->id]);
        }

        return [
            'forms' => AssessmentPresenter::forms(),
            'latest' => AssessmentPresenter::latest($assessments),
            'history' => AssessmentPresenter::history($assessments, showRecorder: true),
            'today' => now((string) config('clinic.timezone'))->toDateString(),
            'intake' => [
                'current' => $current ? IntakePresenter::form($current) : null,
                'previous' => $forms->skip(1)->map(fn (PatientIntakeForm $form) => [
                    'id' => $form->id,
                    'status_label' => $form->status->label(),
                    'submitted_at' => $form->submitted_at?->copy()->setTimezone((string) config('clinic.timezone'))->format('D j M Y'),
                ])->values()->all(),
                'schema' => IntakePresenter::schema(),
                'hasPortalAccount' => $patient->user_id !== null,
            ],
        ];
    }

    /**
     * The patient's lab requisitions, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function labRequisitions(Patient $patient): array
    {
        return LabRequisition::query()
            ->where('patient_id', $patient->id)
            ->with(['patient', 'labPartner', 'orderer:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (LabRequisition $requisition) => LabRequisitionPresenter::row($requisition))
            ->all();
    }

    /**
     * The patient's care reminders, newest first, with times in the clinic's timezone.
     *
     * @return array<int, array<string, mixed>>
     */
    private function careReminders(Patient $patient): array
    {
        $timezone = (string) config('clinic.timezone');

        return $patient->careReminders()
            ->with('creator:id,name')
            ->orderByDesc('due_at')
            ->limit(50)
            ->get()
            ->map(fn (CareReminder $reminder) => [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'message' => $reminder->message,
                'due' => $reminder->due_at->copy()->setTimezone($timezone)->format('D j M Y H:i'),
                'status' => $reminder->status->value,
                'status_label' => $reminder->status->label(),
                'created_by' => $reminder->creator?->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, Patient $patient): array
    {
        $patient->load(['emergencyContacts', 'user']);

        return (new PatientResource($patient))->resolve($request);
    }

    /**
     * @return array<int, array{name: string, relationship: string, phone: string, email: string|null}>
     */
    private function currentContacts(Patient $patient): array
    {
        return $patient->emergencyContacts()->get()->map(fn (EmergencyContact $contact) => [
            'name' => $contact->name,
            'relationship' => $contact->relationship,
            'phone' => $contact->phone,
            'email' => $contact->email,
        ])->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function sexOptions(): array
    {
        return array_map(
            fn (Sex $sex) => ['value' => $sex->value, 'label' => $sex->label()],
            Sex::cases(),
        );
    }
}
