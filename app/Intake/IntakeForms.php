<?php

namespace App\Intake;

use App\Enums\AssessmentKind;
use App\Enums\IntakeStatus;
use App\Models\FunctionalAssessment;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use App\Models\User;
use App\Reminders\ReminderDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The intake workflow: a clinician sends the form (or starts it at the
 * clinic), the patient answers and submits it, and the clinician adds the
 * functional assessment and scores, whose measurements also go into the
 * patient's measurement history.
 */
class IntakeForms
{
    public function __construct(private readonly ReminderDispatcher $dispatcher) {}

    /**
     * Send the patient a fresh link to their open form, creating one if
     * needed. Any earlier link stops working.
     */
    public function send(Patient $patient, User $doctor): PatientIntakeForm
    {
        if ($patient->user_id === null) {
            throw ValidationException::withMessages(['intake' => 'The patient needs a portal account to fill the form in themselves. Link one first, or fill it in at the clinic.']);
        }

        $token = Str::random(48);

        $form = DB::transaction(function () use ($patient, $doctor, $token) {
            $form = $this->openForm($patient, $doctor);
            $form->forceFill([
                'status' => IntakeStatus::SentToPatient,
                'doctor_id' => $doctor->id,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addDays(PatientIntakeForm::LINK_DAYS),
                'sent_at' => now(),
            ])->save();

            return $form;
        });

        $this->dispatcher->announceIntakeForm($form, $token);

        return $form;
    }

    /**
     * The patient's open form for filling in at the clinic.
     */
    public function start(Patient $patient, User $doctor): PatientIntakeForm
    {
        return $this->openForm($patient, $doctor);
    }

    /**
     * Save answers to the patient sections given. Unknown fields are dropped.
     *
     * @param  array<string, mixed>  $answers
     */
    public function saveAnswers(PatientIntakeForm $form, array $answers): void
    {
        if ($form->isCompleted()) {
            throw new LogicException('A completed form cannot be changed.');
        }

        $changes = [];

        foreach (IntakeSchema::PATIENT_SECTIONS as $section) {
            if (array_key_exists($section, $answers)) {
                $changes[$section] = IntakeSchema::clean($section, $answers[$section]) ?: null;
            }
        }

        $form->forceFill($changes)->save();
    }

    /**
     * Mark the form complete. The emailed link stops working.
     */
    public function complete(PatientIntakeForm $form, User $by): void
    {
        if ($form->isCompleted()) {
            return;
        }

        $form->forceFill([
            'status' => IntakeStatus::Completed,
            'submitted_at' => now(),
            'submitted_by' => $by->id,
            'token_hash' => null,
            'token_expires_at' => null,
        ])->save();
    }

    /**
     * Save the clinician's functional assessment and scores, and add the
     * measurements to the patient's measurement history.
     *
     * @param  array<string, mixed>  $answer
     */
    public function saveClinical(PatientIntakeForm $form, array $answer, User $by): void
    {
        $clinical = IntakeSchema::clean(IntakeSchema::CLINICAL_SECTION, $answer);

        DB::transaction(function () use ($form, $clinical, $by) {
            $form->forceFill([IntakeSchema::CLINICAL_SECTION => $clinical ?: null])->save();

            foreach (AssessmentKind::cases() as $kind) {
                $measurements = array_intersect_key($clinical, $kind->fields());

                if ($measurements === []) {
                    continue;
                }

                $assessment = new FunctionalAssessment([
                    'kind' => $kind,
                    'measured_on' => now((string) config('clinic.timezone'))->toDateString(),
                    'measurements' => $measurements,
                    'notes' => 'From the intake assessment.',
                ]);
                $assessment->patient_id = $form->patient_id;
                $assessment->recorded_by = $by->id;
                $assessment->recorded_via = FunctionalAssessment::VIA_CLINIC;
                $assessment->save();
            }
        });
    }

    private function openForm(Patient $patient, User $doctor): PatientIntakeForm
    {
        $form = $patient->intakeForms()->open()->first();

        if ($form instanceof PatientIntakeForm) {
            return $form;
        }

        $form = new PatientIntakeForm;
        $form->patient_id = $patient->id;
        $form->doctor_id = $doctor->id;
        $form->save();

        return $form;
    }
}
