<?php

use App\Http\Controllers\Admin\AuditLogController;
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
});

require __DIR__.'/settings.php';
