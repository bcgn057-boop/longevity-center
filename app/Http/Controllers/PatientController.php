<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\Sex;
use App\Http\Requests\Patients\StorePatientRequest;
use App\Http\Requests\Patients\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\EmergencyContact;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff management of patient records. Authorization is enforced on the
 * routes (role middleware plus PatientPolicy); every read is audited here,
 * every write by PatientObserver.
 */
class PatientController extends Controller
{
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

    public function show(Request $request, Patient $patient): Response
    {
        $this->recordView($request, $patient, 'show');

        return Inertia::render('patients/Show', [
            'patient' => $this->present($request, $patient),
            'can' => [
                'update' => $request->user()?->can('update', $patient),
                'archive' => $request->user()?->can('archive', $patient),
                'linkAccount' => $request->user()?->can('linkAccount', $patient),
                'viewMedical' => $request->user()?->can('viewMedical', $patient),
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

        $this->audit->record(
            AuditEvent::PatientViewed,
            auditable: $patient,
            metadata: ['context' => $context, 'sections' => $sections],
        );
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
