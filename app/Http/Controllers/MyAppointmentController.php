<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient's own appointments: they can see them and cancel upcoming ones.
 *
 * Appointments are looked up only among the patient's own, so another
 * patient's appointment id gives the same 404 as one that does not exist.
 */
class MyAppointmentController extends Controller
{
    /**
     * How many past appointments to show.
     */
    private const PAST_LIMIT = 20;

    public function index(Request $request, AuditLogger $audit): Response
    {
        $patient = $request->user()?->patient;

        if ($patient === null) {
            return Inertia::render('appointments/Mine', ['upcoming' => [], 'past' => [], 'linked' => false]);
        }

        $query = fn () => $patient->appointments()->with(['patient', 'doctor:id,name', 'canceller:id,name']);

        $upcoming = $query()->where('end_time', '>', now()->utc()->format('Y-m-d H:i:s'))->orderBy('start_time')->get();
        $past = $query()->where('end_time', '<=', now()->utc()->format('Y-m-d H:i:s'))->orderByDesc('start_time')->limit(self::PAST_LIMIT)->get();

        $audit->record(AuditEvent::AppointmentListed, auditable: $patient, metadata: [
            'context' => 'self',
            'results' => $upcoming->count() + $past->count(),
        ]);

        $present = fn (Appointment $appointment) => (new AppointmentResource($appointment))->resolve($request);

        return Inertia::render('appointments/Mine', [
            'upcoming' => $upcoming->map($present)->all(),
            'past' => $past->map($present)->all(),
            'linked' => true,
        ]);
    }

    public function cancel(Request $request, int $appointment): RedirectResponse
    {
        $patient = $request->user()?->patient;
        abort_if(! $patient instanceof Patient, 404);

        $appointment = $patient->appointments()->findOrFail($appointment);
        Gate::authorize('cancel', $appointment);

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($appointment->status->isFinal() || $appointment->hasStarted()) {
            throw ValidationException::withMessages([
                'status' => 'Only upcoming appointments can be cancelled. Please contact the clinic.',
            ]);
        }

        $reason = trim((string) ($validated['cancellation_reason'] ?? ''));
        $appointment->cancel($request->user(), $reason === '' ? null : $reason);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your appointment has been cancelled.')]);

        return to_route('my-appointments.index');
    }
}
