<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LabRequisitionStatus;
use App\Enums\UserRole;
use App\Labs\LabRequisitionPresenter;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\LabRequisition;
use App\Models\Patient;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clinicians ordering tests from lab partners, and each partner's lab users
 * seeing the requisitions sent to them. Authorized on the routes; lists are
 * scoped to the user's lab and audited.
 */
class LabRequisitionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...array_column(LabRequisitionStatus::cases(), 'value')])],
        ]);
        $status = $filters['status'] ?? LabRequisitionStatus::Ordered->value;
        $user = $this->user($request);

        $requisitions = LabRequisition::query()
            ->visibleTo($user)
            ->with(['patient', 'labPartner', 'orderer:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->limit(200)
            ->get();

        $this->audit->record(AuditEvent::LabRequisitionListed, metadata: [
            'status' => $status,
            'lab_partner_id' => $user->hasRole(UserRole::LabStaff) ? $user->lab_partner_id : null,
            'results' => $requisitions->count(),
        ]);

        return Inertia::render('labs/Requisitions', [
            'requisitions' => $requisitions->map(fn (LabRequisition $requisition) => LabRequisitionPresenter::row($requisition))->all(),
            'filters' => ['status' => $status],
            'statuses' => array_map(fn (LabRequisitionStatus $s) => ['value' => $s->value, 'label' => $s->label()], LabRequisitionStatus::cases()),
            'labPartner' => $user->labPartner?->name,
            'can' => [
                'enterResults' => $user->can('enterLabResults', LabReport::class),
                'cancel' => $user->can('orderLabTests', LabRequisition::class),
            ],
        ]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'lab_partner_id' => ['required', 'integer', Rule::exists('lab_partners', 'id')->where('active', true)],
            'tests' => ['required', 'array', 'list', 'min:1'],
            'tests.*' => ['string', 'distinct'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ], [
            'tests.required' => 'Choose at least one test.',
            'tests.min' => 'Choose at least one test.',
        ]);

        $partner = LabPartner::query()->findOrFail((int) $validated['lab_partner_id']);

        if (array_diff($validated['tests'], $partner->testCodes()) !== []) {
            throw ValidationException::withMessages(['tests' => "Choose tests that {$partner->name} offers."]);
        }

        $requisition = new LabRequisition([
            'tests' => array_values($validated['tests']),
            'instructions' => filled($validated['instructions'] ?? null) ? trim((string) $validated['instructions']) : null,
        ]);
        $requisition->patient_id = $patient->id;
        $requisition->lab_partner_id = $partner->id;
        $requisition->ordered_by = $this->user($request)->id;
        $requisition->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tests ordered from :lab.', ['lab' => $partner->name])]);

        return to_route('patients.show', $patient);
    }

    public function cancel(Request $request, LabRequisition $labRequisition): RedirectResponse
    {
        $labRequisition->cancel($this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requisition cancelled.')]);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
