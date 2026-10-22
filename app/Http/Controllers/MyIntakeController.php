<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Intake\IntakeForms;
use App\Intake\IntakePresenter;
use App\Intake\IntakeSchema;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient answering their own intake questionnaire.
 *
 * The emailed link only opens after the patient signs in, and only for the
 * account linked to that patient. Forms are looked up among the patient's
 * own, so anyone else's is a plain 404.
 */
class MyIntakeController extends Controller
{
    public function __construct(private readonly IntakeForms $forms) {}

    /**
     * The emailed link. A link that is wrong, expired, replaced or meant for
     * someone else all look the same.
     */
    public function open(Request $request, string $token): RedirectResponse
    {
        $form = PatientIntakeForm::findByToken($token);

        if ($form === null || $form->patient->user_id !== $request->user()?->id) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This link has expired or been replaced. Your open questionnaires are listed here.')]);

            return to_route('my-intake.index');
        }

        return to_route('my-intake.show', $form);
    }

    public function index(Request $request): Response
    {
        $patient = $request->user()?->patient;

        return Inertia::render('intake/Mine', [
            'linked' => $patient instanceof Patient,
            'forms' => $patient instanceof Patient
                ? $patient->intakeForms()->get()->map(fn (PatientIntakeForm $form) => [
                    'id' => $form->id,
                    'status' => $form->status->value,
                    'status_label' => $form->status->label(),
                    'sent_at' => $form->sent_at?->copy()->setTimezone((string) config('clinic.timezone'))->format('D j M Y'),
                    'submitted_at' => $form->submitted_at?->copy()->setTimezone((string) config('clinic.timezone'))->format('D j M Y'),
                ])->all()
                : [],
        ]);
    }

    public function show(Request $request, int $form, AuditLogger $audit): Response
    {
        $intake = $this->own($request, $form);

        $audit->record(AuditEvent::IntakeViewed, auditable: $intake, metadata: ['context' => 'self', 'patient_id' => $intake->patient_id]);

        return Inertia::render('intake/Fill', [
            'mode' => 'patient',
            'patient' => null,
            'form' => IntakePresenter::form($intake),
            'schema' => IntakePresenter::schema(),
            'editable' => $intake->isWithPatient(),
        ]);
    }

    public function update(Request $request, int $form): RedirectResponse
    {
        $intake = $this->ownWithPatient($request, $form);
        $this->forms->saveAnswers($intake, $request->validate(IntakeSchema::rules(IntakeSchema::PATIENT_SECTIONS)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your answers are saved. You can come back to finish later.')]);

        return back();
    }

    public function submit(Request $request, int $form): RedirectResponse
    {
        $intake = $this->ownWithPatient($request, $form);
        $this->forms->saveAnswers($intake, $request->validate(IntakeSchema::rules(IntakeSchema::PATIENT_SECTIONS)));

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->forms->complete($intake, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thank you. Your questionnaire has been sent to your clinician.')]);

        return to_route('my-intake.show', $intake);
    }

    private function own(Request $request, int $form): PatientIntakeForm
    {
        $patient = $request->user()?->patient;
        abort_unless($patient instanceof Patient, 404);

        $intake = $patient->intakeForms()->findOrFail($form);
        Gate::authorize('viewIntake', $intake);

        return $intake;
    }

    private function ownWithPatient(Request $request, int $form): PatientIntakeForm
    {
        $intake = $this->own($request, $form);
        abort_unless($intake->isWithPatient(), 409, 'This questionnaire can no longer be changed.');

        return $intake;
    }
}
