<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\MyProfileController;
use App\Http\Controllers\PatientAccountLinkController;
use App\Http\Controllers\PatientController;
use App\Models\Patient;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::middleware('role:super_admin,clinic_admin')
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {
            Route::inertia('/', 'admin/Dashboard')->name('dashboard');

            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->middleware('role:super_admin')
                ->name('audit-logs.index');
        });

    // Staff patient records. The role check runs before route model binding,
    // so other roles get the same 403 whether or not a patient id exists.
    Route::middleware('role:super_admin,clinic_admin,doctor,practitioner')->group(function () {
        Route::get('patients', [PatientController::class, 'index'])
            ->can('viewAny', Patient::class)
            ->name('patients.index');
        Route::get('patients/create', [PatientController::class, 'create'])
            ->can('create', Patient::class)
            ->name('patients.create');
        Route::post('patients', [PatientController::class, 'store'])
            ->can('create', Patient::class)
            ->name('patients.store');
        Route::get('patients/{patient}', [PatientController::class, 'show'])
            ->can('view', 'patient')
            ->name('patients.show');
        Route::get('patients/{patient}/edit', [PatientController::class, 'edit'])
            ->can('update', 'patient')
            ->name('patients.edit');
        Route::put('patients/{patient}', [PatientController::class, 'update'])
            ->can('update', 'patient')
            ->name('patients.update');
        Route::delete('patients/{patient}', [PatientController::class, 'destroy'])
            ->can('archive', 'patient')
            ->name('patients.destroy');

        Route::post('patients/{patient}/account', [PatientAccountLinkController::class, 'store'])
            ->can('linkAccount', 'patient')
            ->name('patients.account.store');
        Route::delete('patients/{patient}/account', [PatientAccountLinkController::class, 'destroy'])
            ->can('linkAccount', 'patient')
            ->name('patients.account.destroy');
    });

    Route::get('my-profile', MyProfileController::class)
        ->middleware('role:patient')
        ->name('my-profile');
});

require __DIR__.'/settings.php';
