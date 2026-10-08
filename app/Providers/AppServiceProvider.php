<?php

namespace App\Providers;

use App\Audit\AuthenticationEventSubscriber;
use App\Enums\UserRole;
use App\Models\User;
use App\Reminders\RecordReminderSent;
use App\WhatsApp\LogWhatsAppGateway;
use App\WhatsApp\MetaCloudWhatsAppGateway;
use App\WhatsApp\WhatsAppGateway;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Abilities the Super Admin bypass does not grant.
     *
     * @var array<int, string>
     */
    private const CLINICAL_ABILITIES = ['access-clinical', 'viewMedical', 'updateMedical', 'viewReason'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, fn (): WhatsAppGateway => match (config('reminders.channels.whatsapp.provider')) {
            'log' => new LogWhatsAppGateway,
            'meta' => MetaCloudWhatsAppGateway::fromConfig(),
            default => throw new InvalidArgumentException('Unknown WhatsApp provider. Use "log" or "meta".'),
        });
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

        Event::listen(NotificationSent::class, RecordReminderSent::class);
    }

    /**
     * Define role-based authorization gates.
     */
    protected function configureAuthorization(): void
    {
        // Super Admins pass every gate and policy check except clinical ones:
        // they manage the system but only read medical data with a clinical role.
        Gate::before(fn (User $user, string $ability): ?bool => $user->hasRole(UserRole::SuperAdmin)
            && ! in_array($ability, self::CLINICAL_ABILITIES, true) ? true : null);

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
