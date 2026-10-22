<?php

namespace App\Intake;

use App\Models\PatientIntakeForm;

/**
 * An intake form for the page: its status and dates, the answers by
 * section, and (for clinicians and the patient) the clinician's section
 * with BMI and waist-to-height ratio worked out.
 */
final class IntakePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function form(PatientIntakeForm $form): array
    {
        $timezone = (string) config('clinic.timezone');
        $date = fn ($value) => $value?->copy()->setTimezone($timezone)->format('D j M Y');

        return [
            'id' => $form->id,
            'status' => $form->status->value,
            'status_label' => $form->status->label(),
            'doctor' => $form->doctor?->name,
            'sent_at' => $date($form->sent_at),
            'link_expires' => $form->isWithPatient() ? $date($form->token_expires_at) : null,
            'submitted_at' => $date($form->submitted_at),
            'created_at' => $date($form->created_at),
            'answers' => array_combine(
                IntakeSchema::PATIENT_SECTIONS,
                array_map(fn (string $section) => $form->{$section} ?? (object) [], IntakeSchema::PATIENT_SECTIONS),
            ),
            'clinical' => $form->clinical ?? (object) [],
            'derived' => IntakeSchema::derived($form->clinical),
        ];
    }

    /**
     * The questionnaire's sections, for rendering.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return IntakeSchema::sections();
    }
}
