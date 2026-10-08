<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            BiomarkerSeeder::class,
            DevAdminSeeder::class,
            DevPatientSeeder::class,
        ]);

        $testUser = User::where('email', 'test@example.com')->first()
            ?? User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        if ($testUser->role_id === null) {
            $testUser->assignRole(UserRole::Patient);
        }
    }
}
