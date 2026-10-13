<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolAdherence;
use App\Models\ProtocolItem;
use App\Prescriptions\PrescriptionPresenter;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient's own protocols and daily adherence, and a read-only copy of
 * that page for clinicians.
 *
 * In the portal, items are looked up only among the patient's own
 * prescriptions, so another patient's item is a plain 404.
 */
class AdherenceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $patient = $request->user()?->patient;

        if (! $patient instanceof Patient) {
            return Inertia::render('prescriptions/MyProtocols', [
                'linked' => false,
                'prescriptions' => [],
                'days' => PrescriptionPresenter::trackedDays(),
                'today' => Prescription::today(),
                'readOnly' => false,
                'patient' => null,
            ]);
        }

        return $this->protocols($patient, readOnly: false);
    }

    /**
     * A clinician seeing a patient's dashboard as the patient sees it, with
     * their ticks and notes. Read-only: staff never tick on a patient's
     * behalf. Authorized on the route.
     */
    public function show(Patient $patient): Response
    {
        return $this->protocols($patient, readOnly: true);
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $patient = $request->user()?->patient;
        abort_unless($patient instanceof Patient, 404);

        $protocolItem = ProtocolItem::query()
            ->whereHas('prescription', fn (Builder $query) => $query->where('patient_id', $patient->id))
            ->findOrFail($item);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::in(PrescriptionPresenter::trackedDays())],
            'taken' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'date.in' => 'You can only record the last '.ProtocolAdherence::DAYS_TRACKED.' days.',
        ]);

        if (! $protocolItem->isScheduledOn($validated['date'])) {
            throw ValidationException::withMessages(['date' => 'This item was not part of your protocol on that day.']);
        }

        $protocolItem->recordAdherence($validated['date'], $request->boolean('taken'), $validated['notes'] ?? null);

        return to_route('my-protocols.index');
    }

    private function protocols(Patient $patient, bool $readOnly): Response
    {
        $days = PrescriptionPresenter::trackedDays();

        $prescriptions = $patient->prescriptions()
            ->inEffect()
            ->with(['currentItems.adherences' => fn ($query) => $query->whereDate('scheduled_date', '>=', $days[0])])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $this->audit->record(AuditEvent::PrescriptionListed, auditable: $patient, metadata: [
            'context' => $readOnly ? 'patient_view' : 'self',
            'results' => $prescriptions->count(),
        ]);

        return Inertia::render('prescriptions/MyProtocols', [
            'linked' => true,
            'prescriptions' => PrescriptionPresenter::forPatient($prescriptions),
            'days' => $days,
            'today' => Prescription::today(),
            'readOnly' => $readOnly,
            'patient' => $readOnly ? ['id' => $patient->id, 'full_name' => $patient->full_name] : null,
        ]);
    }
}
