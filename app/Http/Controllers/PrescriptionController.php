<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\PrescriptionStatus;
use App\Http\Requests\Prescriptions\AdjustProtocolItemRequest;
use App\Http\Requests\Prescriptions\StorePrescriptionRequest;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use App\Models\User;
use App\Prescriptions\PrescriptionPresenter;
use App\Prescriptions\ProtocolItemRules;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A doctor issuing, adjusting and stopping a patient's protocols.
 * Authorized on the routes (role middleware plus PrescriptionPolicy); item
 * routes are checked against the item's prescription, in the form request or
 * here. Reads are audited
 * here, writes by the prescription observers.
 */
class PrescriptionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Patient $patient): Response
    {
        $since = Carbon::parse(Prescription::today())->subDays(PrescriptionPresenter::ADHERENCE_WINDOW - 1)->toDateString();

        $prescriptions = $patient->prescriptions()
            ->with([
                'prescriber:id,name',
                'items.prescription',
                'items.adherences' => fn ($query) => $query->whereDate('scheduled_date', '>=', $since),
            ])
            ->orderByRaw('status = ? desc', [PrescriptionStatus::Active->value])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $this->audit->record(AuditEvent::PrescriptionListed, auditable: $patient, metadata: [
            'results' => $prescriptions->count(),
        ]);

        return Inertia::render('prescriptions/Index', [
            'patient' => [
                'id' => $patient->id,
                'full_name' => $patient->full_name,
                'medical_record_number' => $patient->medical_record_number,
            ],
            'prescriptions' => $prescriptions->map(fn (Prescription $prescription) => PrescriptionPresenter::forStaff($prescription))->all(),
            'options' => PrescriptionPresenter::options(),
            'today' => Prescription::today(),
            'can' => ['manage' => $this->user($request)->can('managePrescriptions', Prescription::class)],
        ]);
    }

    public function store(StorePrescriptionRequest $request, Patient $patient): RedirectResponse
    {
        $doctor = $this->user($request);

        DB::transaction(function () use ($request, $patient, $doctor) {
            $prescription = new Prescription($request->prescription());
            $prescription->patient_id = $patient->id;
            $prescription->prescribed_by = $doctor->id;
            $prescription->save();

            foreach ($request->items() as $item) {
                $prescription->addItem($item, $doctor);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prescription issued.')]);

        return to_route('patients.prescriptions.index', $patient);
    }

    public function update(Request $request, Prescription $prescription): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$prescription->starts_on->toDateString()],
        ], [
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
        ]);

        $prescription->update([
            'title' => trim($validated['title']),
            'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            'ends_on' => $validated['ends_on'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prescription updated.')]);

        return to_route('patients.prescriptions.index', $prescription->patient_id);
    }

    public function discontinue(Request $request, Prescription $prescription): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']], [
            'reason.required' => 'Give the reason for stopping the prescription.',
        ]);

        $prescription->discontinue($validated['reason'], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prescription discontinued.')]);

        return to_route('patients.prescriptions.index', $prescription->patient_id);
    }

    public function addItem(Request $request, Prescription $prescription): RedirectResponse
    {
        $validated = $request->validate(ProtocolItemRules::row());

        $prescription->addItem([
            ...ProtocolItemRules::attributes($validated),
            'sort_order' => (int) $prescription->items()->max('sort_order') + 1,
        ], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item added.')]);

        return to_route('patients.prescriptions.index', $prescription->patient_id);
    }

    public function adjustItem(AdjustProtocolItemRequest $request, ProtocolItem $protocolItem): RedirectResponse
    {
        $protocolItem->adjust($request->changes(), $request->reason(), $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item adjusted.')]);

        return to_route('patients.prescriptions.index', $protocolItem->prescription->patient_id);
    }

    public function discontinueItem(Request $request, ProtocolItem $protocolItem): RedirectResponse
    {
        Gate::authorize('managePrescriptions', $protocolItem->prescription);

        if ($protocolItem->isDiscontinued()) {
            throw ValidationException::withMessages(['item' => 'This item has already been stopped.']);
        }

        $protocolItem->discontinue($this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item stopped.')]);

        return to_route('patients.prescriptions.index', $protocolItem->prescription->patient_id);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
