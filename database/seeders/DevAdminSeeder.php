<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevAdminSeeder extends Seeder
{
    /**
     * Seed the development Super Admin. Only runs in the local environment.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $admin = User::firstOrNew(['email' => config('app.dev_admin.email')]);

        $admin->fill([
            'name' => config('app.dev_admin.name'),
            'password' => config('app.dev_admin.password'),
        ]);

        $admin->assignRole(UserRole::SuperAdmin);

        if (! $admin->hasVerifiedEmail()) {
            $admin->markEmailAsVerified();
        }
    }
}
