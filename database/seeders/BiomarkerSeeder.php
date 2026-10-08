<?php

namespace Database\Seeders;

use App\Enums\BiomarkerCategory;
use App\Models\Biomarker;
use Illuminate\Database\Seeder;

/**
 * The starter biomarker catalog: reference data, safe to run in any
 * environment and to re-run (matched by code).
 *
 * Units are those labs commonly report; the first is the default. Values are
 * stored exactly as reported and never converted between units.
 */
class BiomarkerSeeder extends Seeder
{
    /**
     * @var array<string, array<int, array{0: string, 1: string, 2: array<int, string>}>>
     */
    private const CATALOG = [
        'lipids' => [
            ['apob', 'Apolipoprotein B (ApoB)', ['mg/dL', 'g/L']],
            ['lpa', 'Lipoprotein(a) [Lp(a)]', ['nmol/L', 'mg/dL']],
            ['ldl_c', 'LDL cholesterol', ['mg/dL', 'mmol/L']],
            ['hdl_c', 'HDL cholesterol', ['mg/dL', 'mmol/L']],
            ['non_hdl_c', 'Non-HDL cholesterol', ['mg/dL', 'mmol/L']],
            ['total_cholesterol', 'Total cholesterol', ['mg/dL', 'mmol/L']],
            ['triglycerides', 'Triglycerides', ['mg/dL', 'mmol/L']],
        ],
        'metabolic' => [
            ['glucose_fasting', 'Fasting glucose', ['mg/dL', 'mmol/L']],
            ['hba1c', 'HbA1c', ['%', 'mmol/mol']],
            ['insulin_fasting', 'Fasting insulin', ['µIU/mL', 'pmol/L']],
            ['uric_acid', 'Uric acid', ['mg/dL', 'µmol/L']],
        ],
        'inflammation' => [
            ['hs_crp', 'High-sensitivity CRP (hs-CRP)', ['mg/L']],
            ['homocysteine', 'Homocysteine', ['µmol/L']],
        ],
        'hormones' => [
            ['testosterone_total', 'Total testosterone', ['ng/dL', 'nmol/L']],
            ['testosterone_free', 'Free testosterone', ['pg/mL', 'pmol/L']],
            ['estradiol', 'Estradiol', ['pg/mL', 'pmol/L']],
            ['shbg', 'Sex hormone-binding globulin (SHBG)', ['nmol/L']],
            ['dhea_s', 'DHEA-S', ['µg/dL', 'µmol/L']],
            ['cortisol_am', 'Morning cortisol', ['µg/dL', 'nmol/L']],
            ['igf_1', 'IGF-1', ['ng/mL', 'nmol/L']],
        ],
        'thyroid' => [
            ['tsh', 'TSH', ['mIU/L']],
            ['free_t4', 'Free T4', ['ng/dL', 'pmol/L']],
            ['free_t3', 'Free T3', ['pg/mL', 'pmol/L']],
        ],
        'nutrients' => [
            ['vitamin_d_25oh', 'Vitamin D (25-OH)', ['ng/mL', 'nmol/L']],
            ['vitamin_b12', 'Vitamin B12', ['pg/mL', 'pmol/L']],
            ['ferritin', 'Ferritin', ['ng/mL', 'µg/L']],
            ['omega3_index', 'Omega-3 index', ['%']],
        ],
        'kidney' => [
            ['creatinine', 'Creatinine', ['mg/dL', 'µmol/L']],
            ['egfr', 'eGFR', ['mL/min/1.73m²']],
            ['cystatin_c', 'Cystatin C', ['mg/L']],
        ],
        'liver' => [
            ['alt', 'ALT', ['U/L']],
            ['ast', 'AST', ['U/L']],
            ['ggt', 'GGT', ['U/L']],
            ['albumin', 'Albumin', ['g/dL', 'g/L']],
        ],
        'blood' => [
            ['hemoglobin', 'Hemoglobin', ['g/dL', 'g/L']],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $category => $markers) {
            foreach ($markers as $order => [$code, $name, $units]) {
                Biomarker::updateOrCreate(['code' => $code], [
                    'name' => $name,
                    'category' => BiomarkerCategory::from($category),
                    'units' => $units,
                    'sort_order' => $order,
                ]);
            }
        }
    }
}
