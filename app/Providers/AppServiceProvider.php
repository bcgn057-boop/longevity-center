<?php

namespace App\Providers;

use App\Audit\AuthenticationEventSubscriber;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuditing();
    }

    /**
     * Record security-relevant events in the audit log.
     */
    protected function configureAuditing(): void
    {
        Event::subscribe(AuthenticationEventSubscriber::class);
    }

    /**
     * Define role-based authorization gates.
     */
    protected function configureAuthorization(): void
    {
        // Super Admins pass every gate and policy check.
        Gate::before(fn (User $user): ?bool => $user->hasRole(UserRole::SuperAdmin) ? true : null);

        Gate::define('access-admin', fn (User $user): bool => $user->hasRole(UserRole::ClinicAdmin));

        Gate::define('access-clinical', fn (User $user): bool => $user->hasAnyRole(
            UserRole::Doctor,
            UserRole::Practitioner,
        ));

        Gate::define('access-lab', fn (User $user): bool => $user->hasRole(UserRole::LabStaff));

        Gate::define('access-patient-portal', fn (User $user): bool => $user->hasRole(UserRole::Patient));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
