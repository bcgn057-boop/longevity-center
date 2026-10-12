<?php

namespace Database\Factories;

use App\Models\ProtocolAdherence;
use App\Models\ProtocolItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProtocolAdherence>
 */
class ProtocolAdherenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'protocol_item_id' => ProtocolItem::factory(),
            'patient_id' => fn (array $attributes) => ProtocolItem::query()->whereKey((int) $attributes['protocol_item_id'])->firstOrFail()->prescription->patient_id,
            'scheduled_date' => now()->toDateString(),
            'taken' => true,
            'taken_at' => now(),
            'notes' => null,
        ];
    }
}
