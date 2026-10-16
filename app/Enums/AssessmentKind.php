<?php

namespace App\Enums;

/**
 * The two kinds of functional assessment. At-home measurements can be
 * recorded by the patient or a clinician; at-center physical tests only by
 * a clinician, in the clinic.
 */
enum AssessmentKind: string
{
    case AtHome = 'at_home';
    case AtCenter = 'at_center';

    public function label(): string
    {
        return match ($this) {
            self::AtHome => 'At-home measurements',
            self::AtCenter => 'At-center functional tests',
        };
    }

    /**
     * The values recorded, with their units and accepted ranges.
     *
     * @return array<string, array{label: string, unit: string, min: int|float, max: int|float, step: int|float}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::AtHome => [
                'height_cm' => ['label' => 'Height', 'unit' => 'cm', 'min' => 50, 'max' => 250, 'step' => 0.1],
                'weight_kg' => ['label' => 'Weight', 'unit' => 'kg', 'min' => 2, 'max' => 400, 'step' => 0.1],
                'waist_cm' => ['label' => 'Waist circumference', 'unit' => 'cm', 'min' => 30, 'max' => 250, 'step' => 0.1],
                'systolic_bp' => ['label' => 'Blood pressure, systolic', 'unit' => 'mmHg', 'min' => 60, 'max' => 260, 'step' => 1],
                'diastolic_bp' => ['label' => 'Blood pressure, diastolic', 'unit' => 'mmHg', 'min' => 30, 'max' => 160, 'step' => 1],
            ],
            self::AtCenter => [
                'grip_left_kg' => ['label' => 'Grip strength, left hand', 'unit' => 'kg', 'min' => 0, 'max' => 100, 'step' => 0.1],
                'grip_right_kg' => ['label' => 'Grip strength, right hand', 'unit' => 'kg', 'min' => 0, 'max' => 100, 'step' => 0.1],
                'chair_stand_reps' => ['label' => '30-second chair stand', 'unit' => 'repetitions', 'min' => 0, 'max' => 60, 'step' => 1],
                'walk_6min_m' => ['label' => '6-minute walk distance', 'unit' => 'm', 'min' => 0, 'max' => 1000, 'step' => 1],
            ],
        };
    }
}
