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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clinicians sending the intake questionnaire, filling it in at the clinic,
 * and recording the functional assessment and scores. Authorized on the
 * routes; reads audited here, writes by the observer.
 */
class IntakeController extends Controller
{
    public function __construct(private readonly IntakeForms $forms) {}

    public function send(Request $request, Patient $patient): RedirectResponse
    {
        $this->forms->send($patient, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Questionnaire sent to :name.', ['name' => $patient->full_name])]);

        return to_route('patients.show', ['patient' => $patient, 'tab' => 'measurements']);
    }

    public function start(Request $request, Patient $patient): RedirectResponse
    {
        $form = $this->forms->start($patient, $this->user($request));

        return to_route('intake-forms.edit', $form);
    }

    public function edit(PatientIntakeForm $intakeForm, AuditLogger $audit): Response
    {
        $audit->record(AuditEvent::IntakeViewed, auditable: $intakeForm, metadata: ['patient_id' => $intakeForm->patient_id]);

        return Inertia::render('intake/Fill', [
            'mode' => 'clinician',
            'patient' => ['id' => $intakeForm->patient->id, 'full_name' => $intakeForm->patient->full_name],
            'form' => IntakePresenter::form($intakeForm),
            'schema' => IntakePresenter::schema(),
            'editable' => ! $intakeForm->isCompleted(),
        ]);
    }

    public function update(Request $request, PatientIntakeForm $intakeForm): RedirectResponse
    {
        $this->ensureOpen($intakeForm);
        $this->forms->saveAnswers($intakeForm, $request->validate(IntakeSchema::rules(IntakeSchema::PATIENT_SECTIONS)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Answers saved.')]);

        return back();
    }

    public function complete(Request $request, PatientIntakeForm $intakeForm): RedirectResponse
    {
        $this->ensureOpen($intakeForm);
        $this->forms->saveAnswers($intakeForm, $request->validate(IntakeSchema::rules(IntakeSchema::PATIENT_SECTIONS)));
        $this->forms->complete($intakeForm, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Questionnaire completed.')]);

        return to_route('patients.show', ['patient' => $intakeForm->patient_id, 'tab' => 'measurements']);
    }

    public function clinical(Request $request, PatientIntakeForm $intakeForm): RedirectResponse
    {
        $validated = $request->validate(IntakeSchema::rules([IntakeSchema::CLINICAL_SECTION]));
        $clinical = (array) ($validated[IntakeSchema::CLINICAL_SECTION] ?? []);

        if (filled($clinical['systolic_bp'] ?? null) !== filled($clinical['diastolic_bp'] ?? null)
            || (filled($clinical['systolic_bp'] ?? null) && (float) $clinical['systolic_bp'] <= (float) $clinical['diastolic_bp'])) {
            throw ValidationException::withMessages(['clinical.systolic_bp' => 'Enter both blood pressure numbers, with the systolic higher.']);
        }

        $this->forms->saveClinical($intakeForm, $clinical, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Assessment saved and added to the measurement history.')]);

        return back();
    }

    private function ensureOpen(PatientIntakeForm $form): void
    {
        if ($form->isCompleted()) {
            throw ValidationException::withMessages(['intake' => 'This questionnaire is complete and can no longer be changed.']);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
