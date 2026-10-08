<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Http\Requests\Appointments\CancelAppointmentRequest;
use App\Http\Requests\Appointments\StoreAppointmentRequest;
use App\Http\Requests\Appointments\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff scheduling. Authorization is enforced on the routes (role middleware
 * plus AppointmentPolicy); reads are audited here, writes by AppointmentObserver.
 */
class AppointmentController extends Controller
{
    /**
     * The widest date range the schedule can show at once.
     */
    private const MAX_RANGE_DAYS = 31;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AppointmentScheduler $scheduler,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'doctor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $timezone = (string) config('clinic.timezone');
        $from = Carbon::createFromFormat('!Y-m-d', $filters['from'] ?? Carbon::today($timezone)->format('Y-m-d'), $timezone);
        $to = isset($filters['to']) ? Carbon::createFromFormat('!Y-m-d', $filters['to'], $timezone) : $from->copy()->addDays(6);

        if ($from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a range of at most '.self::MAX_RANGE_DAYS.' days.']);
        }

        $appointments = Appointment::query()
            ->with(['patient:id,first_name,last_name,medical_record_number', 'doctor:id,name'])
            ->where('start_time', '>=', $from->copy()->utc()->format('Y-m-d H:i:s'))
            ->where('start_time', '<', $to->copy()->addDay()->utc()->format('Y-m-d H:i:s'))
            ->when($filters['doctor_id'] ?? null, fn ($query, $doctor) => $query->forDoctor((int) $doctor))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('start_time')
            ->paginate(50)
            ->withQueryString();

        // The schedule lists patient names, so viewing it is access to patient data.
        $this->audit->record(AuditEvent::AppointmentListed, metadata: [
            'context' => 'schedule',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'doctor_id' => isset($filters['doctor_id']) ? (int) $filters['doctor_id'] : null,
            'status' => $filters['status'] ?? null,
            'page' => $appointments->currentPage(),
            'results' => $appointments->total(),
        ]);

        return Inertia::render('appointments/Index', [
            'appointments' => $appointments->through(
                fn (Appointment $appointment) => (new AppointmentResource($appointment))->withoutReasons()->resolve($request),
            ),
            'filters' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'doctor_id' => isset($filters['doctor_id']) ? (string) $filters['doctor_id'] : '',
                'status' => $filters['status'] ?? '',
            ],
            'doctors' => $this->doctorOptions(),
            'statuses' => array_map(
                fn (AppointmentStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                AppointmentStatus::cases(),
            ),
            'timezone' => $timezone,
            'can' => ['create' => $request->user()?->can('create', Appointment::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer'],
            'patient_search' => ['nullable', 'string', 'max:100'],
        ]);

        $patient = isset($validated['patient_id']) ? Patient::query()->whereKey((int) $validated['patient_id'])->first() : null;

        return Inertia::render('appointments/Create', [
            'patient' => $patient ? $this->patientOption($patient) : null,
            // Only filled when the page asks for it while staff search for a patient.
            'patientOptions' => fn () => $this->searchPatients($validated['patient_search'] ?? null),
            'doctors' => $this->doctorOptions(),
            'canEditReason' => (bool) $request->user()?->can('viewReason', Appointment::class),
            'timezone' => config('clinic.timezone'),
            'today' => Carbon::today((string) config('clinic.timezone'))->format('Y-m-d'),
        ]);
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $appointment = $this->scheduler->book(
            (int) $request->patientId(),
            $request->appointmentAttributes(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment booked.')]);

        return to_route('appointments.show', $appointment);
    }

    public function show(Request $request, Appointment $appointment): Response
    {
        $this->recordView($request, $appointment, 'show');

        $scheduled = $appointment->status === AppointmentStatus::Scheduled;
        $user = $request->user();

        return Inertia::render('appointments/Show', [
            'appointment' => $this->present($request, $appointment),
            'can' => [
                'update' => $scheduled && (bool) $user?->can('update', $appointment),
                'complete' => $scheduled && $appointment->hasStarted() && (bool) $user?->can('complete', $appointment),
                'cancel' => $scheduled && (bool) $user?->can('cancel', $appointment),
                'viewReason' => (bool) $user?->can('viewReason', $appointment),
            ],
        ]);
    }

    public function edit(Request $request, Appointment $appointment): Response|RedirectResponse
    {
        if ($appointment->status->isFinal()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This appointment can no longer be changed.')]);

            return to_route('appointments.show', $appointment);
        }

        $this->recordView($request, $appointment, 'edit');

        return Inertia::render('appointments/Edit', [
            'appointment' => $this->present($request, $appointment),
            'doctors' => $this->doctorOptions(),
            'canEditReason' => (bool) $request->user()?->can('viewReason', $appointment),
            'timezone' => config('clinic.timezone'),
            'today' => Carbon::today((string) config('clinic.timezone'))->format('Y-m-d'),
        ]);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->ensureScheduled($appointment);

        $this->scheduler->reschedule($appointment, $request->appointmentAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment updated.')]);

        return to_route('appointments.show', $appointment);
    }

    public function complete(Appointment $appointment): RedirectResponse
    {
        $this->ensureScheduled($appointment);

        if (! $appointment->hasStarted()) {
            throw ValidationException::withMessages(['status' => 'An appointment cannot be completed before it starts.']);
        }

        $appointment->complete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment marked as completed.')]);

        return to_route('appointments.show', $appointment);
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->ensureScheduled($appointment);

        $appointment->cancel($request->user(), $request->cancellationReason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment cancelled.')]);

        return to_route('appointments.show', $appointment);
    }

    /**
     * Completed and cancelled appointments are final.
     *
     * @throws ValidationException
     */
    private function ensureScheduled(Appointment $appointment): void
    {
        if ($appointment->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => "This appointment is {$appointment->status->value} and can no longer be changed.",
            ]);
        }
    }

    private function recordView(Request $request, Appointment $appointment, string $context): void
    {
        $this->audit->record(
            AuditEvent::AppointmentViewed,
            auditable: $appointment,
            metadata: [
                'context' => $context,
                'reason_shown' => (bool) $request->user()?->can('viewReason', $appointment),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, Appointment $appointment): array
    {
        $appointment->load(['patient', 'doctor', 'canceller']);

        return (new AppointmentResource($appointment))->resolve($request);
    }

    /**
     * Patients matching a booking search, audited like any other patient search.
     *
     * @return array<int, array{id: int, full_name: string, medical_record_number: string|null}>
     */
    private function searchPatients(?string $term): array
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $patients = Patient::query()
            ->search($term)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        $this->audit->record(AuditEvent::PatientSearched, metadata: [
            'context' => 'appointment_booking',
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

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function doctorOptions(): array
    {
        return User::clinicians()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
