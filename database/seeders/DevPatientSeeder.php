<?php

namespace Database\Seeders;

use App\Models\Patient;
use Illuminate\Database\Seeder;

class DevPatientSeeder extends Seeder
{
    /**
     * Seed fake demo patients. Only runs in the local environment, and only
     * when there are no patients yet, so real records are never mixed with fakes.
     */
    public function run(): void
    {
        if (! app()->environment('local') || Patient::withTrashed()->exists()) {
            return;
        }

        Patient::factory()->count(5)->withEmergencyContacts()->create();
    }
}
