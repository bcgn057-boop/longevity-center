<?php

namespace Database\Factories;

use App\Enums\ProtocolFrequency;
use App\Enums\ProtocolItemType;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProtocolItem>
 */
class ProtocolItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$type, $name, $dosage] = fake()->randomElement([
            [ProtocolItemType::Supplement, 'Vitamin D3', '2000 IU'],
            [ProtocolItemType::Supplement, 'Omega-3', '1 g'],
            [ProtocolItemType::Medication, 'Metformin', '500 mg'],
            [ProtocolItemType::Exercise, 'Zone 2 cardio', '45 minutes'],
            [ProtocolItemType::Sleep, 'Consistent bedtime', null],
        ]);

        return [
            'prescription_id' => Prescription::factory(),
            'type' => $type,
            'name' => $name,
            'dosage' => $dosage,
            'instructions' => fake()->optional()->sentence(6),
            'frequency' => ProtocolFrequency::Daily,
            'sort_order' => 0,
        ];
    }
}
