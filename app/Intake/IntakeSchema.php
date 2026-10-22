<?php

namespace App\Intake;

use LogicException;

/**
 * The patient intake questionnaire, defined once. The same definition gives
 * the validation rules, cleans submitted answers down to known fields and
 * types, and is sent to the page that renders the form and the summary.
 *
 * Sections come in three shapes:
 * - "fields": a list of single questions;
 * - "checklist": the same columns asked for each item (e.g. each condition);
 * - "rows": a repeatable table (e.g. medications), up to max_rows.
 *
 * Patients answer the patient sections. The clinical section (measurements,
 * functional tests and scores) is clinician-only.
 */
final class IntakeSchema
{
    /**
     * Sections the patient fills in, in order.
     *
     * @var array<int, string>
     */
    public const PATIENT_SECTIONS = [
        'demographics', 'conditions', 'surgeries', 'medications', 'lifestyle', 'family_history', 'vaccinations', 'womens_health',
    ];

    public const CLINICAL_SECTION = 'clinical';

    private const YES_NO_UNSURE = [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No'], ['value' => 'unsure', 'label' => 'Not sure']];

    private const HIGH_MODERATE_LOW = [['value' => 'high', 'label' => 'High'], ['value' => 'moderate', 'label' => 'Moderate'], ['value' => 'low', 'label' => 'Low']];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function sections(): array
    {
        return [
            'demographics' => [
                'title' => 'About you and your emergency contact',
                'shape' => 'fields',
                'fields' => [
                    self::field('occupation', 'Occupation', 'text'),
                    self::field('ethnicity', 'Ethnicity (optional)', 'text'),
                    self::field('phone', 'Phone', 'text'),
                    self::field('address', 'Address', 'textarea'),
                    self::field('emergency_name', 'Emergency contact name', 'text'),
                    self::field('emergency_relationship', 'Relationship to you', 'text'),
                    self::field('emergency_phone', 'Emergency contact phone', 'text'),
                ],
            ],
            'conditions' => [
                'title' => 'Diagnosed conditions',
                'description' => 'Tick any condition a doctor has diagnosed.',
                'shape' => 'checklist',
                'items' => self::items([
                    'high_blood_pressure' => 'High blood pressure',
                    'high_cholesterol' => 'High cholesterol',
                    'diabetes' => 'Pre-diabetes or diabetes',
                    'heart_disease_stroke' => 'Heart disease or stroke',
                    'kidney_disease' => 'Kidney disease',
                    'liver_disease' => 'Liver disease',
                    'cancer' => 'Cancer',
                    'sleep_apnoea' => 'Sleep apnoea',
                    'depression_anxiety' => 'Depression or anxiety',
                    'autoimmune' => 'Autoimmune condition',
                ]),
                'columns' => [
                    self::field('present', 'Diagnosed', 'boolean'),
                    self::field('age_at_diagnosis', 'Age at diagnosis', 'number', min: 0, max: 120),
                    self::field('years', 'Years with it', 'number', min: 0, max: 120),
                    self::field('medications', 'Medications for it', 'text'),
                ],
                'extra' => [self::field('other', 'Other conditions', 'textarea')],
            ],
            'surgeries' => [
                'title' => 'Surgical history',
                'shape' => 'checklist',
                'items' => self::items([
                    'tonsillectomy' => 'Tonsillectomy',
                    'appendicectomy' => 'Appendicectomy',
                    'splenectomy' => 'Splenectomy',
                    'cancer_surgery' => 'Cancer surgery',
                    'other' => 'Other surgery',
                ]),
                'columns' => [
                    self::field('present', 'Had it', 'boolean'),
                    self::field('age', 'Age at the time', 'number', min: 0, max: 120),
                    self::field('procedure', 'Procedure details', 'text'),
                ],
            ],
            'medications' => [
                'title' => 'Medications, supplements, hormones and other substances',
                'description' => 'One row each. Include anything you take regularly.',
                'shape' => 'rows',
                'max_rows' => 40,
                'columns' => [
                    self::field('category', 'Type', 'select', options: [
                        ['value' => 'medication', 'label' => 'Medication'],
                        ['value' => 'supplement', 'label' => 'Supplement'],
                        ['value' => 'hormone', 'label' => 'Hormone'],
                        ['value' => 'other', 'label' => 'Other substance'],
                    ]),
                    self::field('name', 'Name', 'text'),
                    self::field('dose', 'Dose', 'text'),
                    self::field('frequency', 'How often', 'text'),
                    self::field('duration', 'For how long', 'text'),
                ],
            ],
            'lifestyle' => [
                'title' => 'Lifestyle and habits',
                'shape' => 'fields',
                'fields' => [
                    self::field('activity_minutes', 'Moderate or vigorous exercise per week (minutes)', 'number', min: 0, max: 3000),
                    self::field('activity_type', 'What kind of exercise', 'text'),
                    self::field('sleep_hours', 'Usual sleep per night (hours)', 'number', min: 0, max: 24, step: 0.5),
                    self::field('stress', 'Stress over the past month', 'select', options: [
                        ['value' => 'low', 'label' => 'Low'], ['value' => 'moderate', 'label' => 'Moderate'], ['value' => 'high', 'label' => 'High'],
                    ]),
                    self::field('diet_carbs', 'Carbohydrates in your diet', 'select', options: self::HIGH_MODERATE_LOW),
                    self::field('diet_protein', 'Protein in your diet', 'select', options: self::HIGH_MODERATE_LOW),
                    self::field('diet_fibre', 'Fibre in your diet', 'select', options: self::HIGH_MODERATE_LOW),
                ],
            ],
            'family_history' => [
                'title' => 'Family history',
                'description' => 'Parents, brothers, sisters or children diagnosed with:',
                'shape' => 'checklist',
                'items' => self::items([
                    'high_blood_pressure' => 'High blood pressure',
                    'high_cholesterol' => 'High cholesterol',
                    'diabetes' => 'Diabetes',
                    'vascular_disease' => 'Vascular disease',
                    'kidney_liver_disease' => 'Kidney or liver disease',
                    'cancer' => 'Cancer',
                    'alzheimers_parkinsons' => 'Alzheimer\'s or Parkinson\'s',
                    'premature_death' => 'Early death or heart attack (before 60)',
                ]),
                'columns' => [
                    self::field('present', 'Yes', 'boolean'),
                    self::field('relatives', 'Who', 'text'),
                ],
            ],
            'vaccinations' => [
                'title' => 'Vaccinations',
                'shape' => 'fields',
                'fields' => [
                    self::field('covid', 'Vaccinated against Covid-19', 'select', options: self::YES_NO_UNSURE),
                    self::field('covid_doses', 'Number of Covid-19 doses', 'number', min: 0, max: 10),
                    self::field('covid_last_dose', 'Last Covid-19 dose (month and year)', 'text'),
                    self::field('others', 'Other vaccinations', 'textarea'),
                ],
            ],
            'womens_health' => [
                'title' => 'Women\'s health',
                'description' => 'If this does not apply to you, leave it blank.',
                'shape' => 'fields',
                'fields' => [
                    self::field('menopausal_status', 'Menopausal status', 'select', options: [
                        ['value' => 'pre', 'label' => 'Pre-menopausal'], ['value' => 'peri', 'label' => 'Peri-menopausal'],
                        ['value' => 'post', 'label' => 'Post-menopausal'], ['value' => 'unsure', 'label' => 'Not sure'],
                    ]),
                    self::field('pregnancy', 'Pregnant or breastfeeding', 'select', options: [
                        ['value' => 'no', 'label' => 'Neither'], ['value' => 'pregnant', 'label' => 'Pregnant'], ['value' => 'breastfeeding', 'label' => 'Breastfeeding'],
                    ]),
                    self::field('children', 'Number of children', 'number', min: 0, max: 20),
                    self::field('hysterectomy', 'Had a hysterectomy', 'boolean'),
                    self::field('cycle', 'Menstrual cycle', 'select', options: [
                        ['value' => 'regular', 'label' => 'Regular'], ['value' => 'irregular', 'label' => 'Irregular'], ['value' => 'none', 'label' => 'No cycle'],
                    ]),
                    self::field('flow', 'Flow', 'select', options: [
                        ['value' => 'light', 'label' => 'Light'], ['value' => 'moderate', 'label' => 'Moderate'], ['value' => 'heavy', 'label' => 'Heavy'],
                    ]),
                ],
            ],
            self::CLINICAL_SECTION => [
                'title' => 'Functional assessment and scoring',
                'description' => 'Clinician only. Measurements are also added to the patient\'s measurement history.',
                'shape' => 'fields',
                'fields' => [
                    self::field('height_cm', 'Height (cm)', 'number', min: 50, max: 250, step: 0.1),
                    self::field('weight_kg', 'Weight (kg)', 'number', min: 2, max: 400, step: 0.1),
                    self::field('waist_cm', 'Waist circumference (cm)', 'number', min: 30, max: 250, step: 0.1),
                    self::field('systolic_bp', 'Blood pressure, systolic (mmHg)', 'number', min: 60, max: 260),
                    self::field('diastolic_bp', 'Blood pressure, diastolic (mmHg)', 'number', min: 30, max: 160),
                    self::field('chair_stand_reps', '30-second chair stand (repetitions)', 'number', min: 0, max: 60),
                    self::field('walk_6min_m', '6-minute walk distance (m)', 'number', min: 0, max: 1000),
                    self::field('metabolic_score', 'Metabolic score', 'number', min: 0, max: 100),
                    self::field('inflammatory_score', 'Inflammatory score', 'number', min: 0, max: 100),
                    self::field('recovery_score', 'Recovery score', 'number', min: 0, max: 100),
                    self::field('liver_gut_score', 'Liver-gut axis score', 'number', min: 0, max: 100),
                    self::field('risk_score', 'Risk score', 'number', min: 0, max: 100),
                    self::field('score_notes', 'Scoring notes', 'textarea'),
                ],
            ],
        ];
    }

    /**
     * The patient sections only, for the patient's form.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function patientSections(): array
    {
        return array_intersect_key(self::sections(), array_flip(self::PATIENT_SECTIONS));
    }

    /**
     * Validation rules for the given sections, keyed as submitted:
     * section.field, section.item.column or section.*.column.
     *
     * @param  array<int, string>  $sections
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $sections): array
    {
        $all = self::sections();
        $rules = [];

        foreach ($sections as $key) {
            $section = $all[$key];
            $rules[$key] = ['nullable', 'array'];

            match ($section['shape']) {
                'fields' => array_map(function (array $field) use (&$rules, $key) {
                    $rules["{$key}.{$field['key']}"] = self::fieldRules($field);
                }, $section['fields']),
                'checklist' => (function () use (&$rules, $key, $section) {
                    foreach ($section['items'] as $item) {
                        foreach ($section['columns'] as $column) {
                            $rules["{$key}.{$item['key']}.{$column['key']}"] = self::fieldRules($column);
                        }
                    }

                    foreach ($section['extra'] ?? [] as $field) {
                        $rules["{$key}.{$field['key']}"] = self::fieldRules($field);
                    }
                })(),
                'rows' => (function () use (&$rules, $key, $section) {
                    $rules[$key] = ['nullable', 'array', 'list', 'max:'.$section['max_rows']];

                    foreach ($section['columns'] as $column) {
                        $rules["{$key}.*.{$column['key']}"] = self::fieldRules($column);
                    }
                })(),
                default => throw new LogicException("Unknown section shape for [{$key}]."),
            };
        }

        return $rules;
    }

    /**
     * An answer reduced to known fields and types, with blanks dropped, so
     * only what the schema allows is ever stored.
     *
     * @param  mixed  $answer
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public static function clean(string $key, $answer): array
    {
        $section = self::sections()[$key];
        $answer = is_array($answer) ? $answer : [];

        return match ($section['shape']) {
            'fields' => self::cleanFields($section['fields'], $answer),
            'checklist' => array_filter([
                ...array_filter(array_combine(
                    array_column($section['items'], 'key'),
                    array_map(fn (array $item) => self::cleanFields($section['columns'], (array) ($answer[$item['key']] ?? [])), $section['items']),
                )),
                ...self::cleanFields($section['extra'] ?? [], $answer),
            ]),
            'rows' => array_values(array_filter(array_map(
                fn ($row) => self::cleanFields($section['columns'], is_array($row) ? $row : []),
                array_slice(array_values($answer), 0, $section['max_rows']),
            ))),
            default => throw new LogicException("Unknown section shape for [{$key}]."),
        };
    }

    /**
     * Body mass index and waist-to-height ratio, when measured.
     *
     * @param  array<string, mixed>|null  $clinical
     * @return array{bmi: float|null, waist_to_height: float|null}
     */
    public static function derived(?array $clinical): array
    {
        $height = (float) ($clinical['height_cm'] ?? 0);
        $weight = (float) ($clinical['weight_kg'] ?? 0);
        $waist = (float) ($clinical['waist_cm'] ?? 0);

        return [
            'bmi' => $height > 0 && $weight > 0 ? round($weight / (($height / 100) ** 2), 1) : null,
            'waist_to_height' => $height > 0 && $waist > 0 ? round($waist / $height, 2) : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private static function cleanFields(array $fields, array $answer): array
    {
        $clean = [];

        foreach ($fields as $field) {
            $value = $answer[$field['key']] ?? null;

            if ($value === null || $value === '' || $value === false || $value === 'false' || $value === '0' && $field['type'] === 'boolean') {
                continue;
            }

            $clean[$field['key']] = match ($field['type']) {
                'boolean' => true,
                'number' => ($field['step'] ?? 1) < 1 ? round((float) $value, 1) : (int) round((float) $value),
                default => trim((string) $value),
            };
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private static function fieldRules(array $field): array
    {
        return match ($field['type']) {
            'boolean' => ['nullable', 'boolean'],
            'number' => ['nullable', 'numeric', "between:{$field['min']},{$field['max']}"],
            'select' => ['nullable', 'in:'.implode(',', array_column($field['options'], 'value'))],
            'textarea' => ['nullable', 'string', 'max:2000'],
            default => ['nullable', 'string', 'max:255'],
        };
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     * @return array<string, mixed>
     */
    private static function field(string $key, string $label, string $type, int|float $min = 0, int|float $max = 0, int|float $step = 1, array $options = []): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'min' => $type === 'number' ? $min : null,
            'max' => $type === 'number' ? $max : null,
            'step' => $type === 'number' ? $step : null,
            'options' => $options ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, string>  $items
     * @return array<int, array{key: string, label: string}>
     */
    private static function items(array $items): array
    {
        return array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($items), $items);
    }
}
