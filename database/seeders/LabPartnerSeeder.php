<?php

namespace Database\Seeders;

use App\Models\LabPartner;
use Illuminate\Database\Seeder;

class LabPartnerSeeder extends Seeder
{
    /**
     * The clinic's external lab partners and the tests each offers.
     *
     * @var array<string, array{name: string, tests: array<string, string>}>
     */
    public const PARTNERS = [
        'tata_1mg' => ['name' => 'TATA 1MG', 'tests' => [
            'blood_tests' => 'Blood tests',
            'food_intolerance' => 'Food intolerance test',
        ]],
        'strand' => ['name' => 'Strand Life Sciences', 'tests' => [
            'circulating_tumour_cells' => 'Circulating tumour cells',
        ]],
        'biocogniz' => ['name' => 'Biocogniz', 'tests' => [
            'nutrigenomics' => 'Nutrigenomics',
        ]],
        'ammogenomics' => ['name' => 'Ammogenomics', 'tests' => [
            'rna_profiling' => 'RNA-based profiling',
            'inflammatory_markers' => 'Inflammatory markers',
            'mitochondrial_assay' => 'Mitochondrial assay',
        ]],
    ];

    /**
     * Seed the lab partners. Safe to run repeatedly.
     */
    public function run(): void
    {
        foreach (self::PARTNERS as $code => $partner) {
            LabPartner::updateOrCreate(['code' => $code], [
                'name' => $partner['name'],
                'tests' => array_map(
                    fn (string $testCode, string $label) => ['code' => $testCode, 'label' => $label],
                    array_keys($partner['tests']),
                    $partner['tests'],
                ),
            ]);
        }
    }
}
