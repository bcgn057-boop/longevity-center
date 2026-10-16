<?php

namespace App\Enums;

/**
 * How biomarkers are grouped in the catalog and on reports.
 */
enum BiomarkerCategory: string
{
    case Lipids = 'lipids';
    case Metabolic = 'metabolic';
    case Inflammation = 'inflammation';
    case Hormones = 'hormones';
    case Thyroid = 'thyroid';
    case Nutrients = 'nutrients';
    case Kidney = 'kidney';
    case Liver = 'liver';
    case Blood = 'blood';
    case Cellular = 'cellular';
    case Oncology = 'oncology';

    public function label(): string
    {
        return match ($this) {
            self::Lipids => 'Lipids & cardiovascular',
            self::Metabolic => 'Metabolic',
            self::Inflammation => 'Inflammation',
            self::Hormones => 'Hormones',
            self::Thyroid => 'Thyroid',
            self::Nutrients => 'Vitamins & nutrients',
            self::Kidney => 'Kidney',
            self::Liver => 'Liver',
            self::Blood => 'Blood count',
            self::Cellular => 'Cellular & mitochondrial',
            self::Oncology => 'Cancer screening',
        };
    }
}
