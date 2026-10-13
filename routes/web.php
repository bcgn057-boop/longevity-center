<?php

use App\Http\Controllers\AdherenceController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\CareReminderController;
use App\Http\Controllers\Compliance\AnalyticsController;
use App\Http\Controllers\Compliance\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LabReportController;
use App\Http\Controllers\MyAppointmentController;
use App\Http\Controllers\MyLabResultsController;
use App\Http\Controllers\MyProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PatientAccountLinkController;
use App\Http\Controllers\PatientConsentController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Models\Appointment;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->name('notifications.read');

    Route::middleware('role:super_admin,clinic_admin')
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {
            Route::inertia('/', 'admin/Dashboard')->name('dashboard');
        });

    // Compliance reporting. The role check runs first and is audited; each
    // page then checks its own ability.
    Route::middleware('role:super_admin,clinic_admin,compliance_officer')
        ->prefix('compliance')
        ->name('compliance.')
        ->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->can('viewAuditLogs')
                ->name('audit-logs.index');
            Route::get('audit-logs/export', [AuditLogController::class, 'export'])
                ->can('exportAuditLogs')
                ->name('audit-logs.export');
            Route::get('analytics', [AnalyticsController::class, 'practice'])
                ->can('viewPracticeAnalytics')
                ->name('analytics');
            Route::get('staff-activity', [AnalyticsController::class, 'staff'])
                ->can('viewStaffActivity')
                ->name('staff-activity');
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

        Route::post('patients/{patient}/consent/{channel}', [PatientConsentController::class, 'store'])
            ->can('manageConsent', 'patient')
            ->name('patients.consent.store');
        Route::delete('patients/{patient}/consent/{channel}', [PatientConsentController::class, 'destroy'])
            ->can('manageConsent', 'patient')
            ->name('patients.consent.destroy');

        // Care reminders may hold clinical text: clinic staff only, not the Super Admin.
        Route::middleware('role:clinic_admin,doctor,practitioner')->group(function () {
            Route::post('patients/{patient}/care-reminders', [CareReminderController::class, 'store'])
                ->can('manageCareReminders', 'patient')
                ->name('patients.care-reminders.store');
            Route::post('care-reminders/{careReminder}/cancel', [CareReminderController::class, 'cancel'])
                ->name('care-reminders.cancel');
        });

        Route::get('appointments', [AppointmentController::class, 'index'])
            ->can('viewAny', Appointment::class)
            ->name('appointments.index');
        Route::get('appointments/create', [AppointmentController::class, 'create'])
            ->can('create', Appointment::class)
            ->name('appointments.create');
        Route::post('appointments', [AppointmentController::class, 'store'])
            ->can('create', Appointment::class)
            ->name('appointments.store');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])
            ->can('view', 'appointment')
            ->name('appointments.show');
        Route::get('appointments/{appointment}/edit', [AppointmentController::class, 'edit'])
            ->can('update', 'appointment')
            ->name('appointments.edit');
        Route::put('appointments/{appointment}', [AppointmentController::class, 'update'])
            ->can('update', 'appointment')
            ->name('appointments.update');
        Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete'])
            ->can('complete', 'appointment')
            ->name('appointments.complete');
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->can('cancel', 'appointment')
            ->name('appointments.cancel');
    });

    // Lab results: clinical data, so no Super Admin or Clinic Admin. The role
    // check runs before route model binding, so others get the same 403
    // whether or not a report id exists.
    Route::middleware('role:lab_staff,doctor,practitioner')->group(function () {
        Route::get('labs', [LabReportController::class, 'index'])
            ->can('viewLabReports', LabReport::class)
            ->name('labs.index');
        Route::get('labs/create', [LabReportController::class, 'create'])
            ->can('enterLabResults', LabReport::class)
            ->name('labs.create');
        Route::post('labs', [LabReportController::class, 'store'])
            ->can('enterLabResults', LabReport::class)
            ->name('labs.store');
        Route::get('labs/{labReport}', [LabReportController::class, 'show'])
            ->can('viewLabReport', 'labReport')
            ->name('labs.show');
        Route::post('labs/{labReport}/results', [LabReportController::class, 'addResults'])
            ->can('enterLabResults', 'labReport')
            ->name('labs.results.store');
        Route::post('labs/{labReport}/release', [LabReportController::class, 'release'])
            ->can('releaseLabReport', 'labReport')
            ->name('labs.release');
        // Authorized in the controller against the result's report.
        Route::post('lab-results/{labResult}/corrections', [LabReportController::class, 'correct'])
            ->name('lab-results.corrections.store');
    });

    // Prescriptions: clinical data, so no Super Admin or Clinic Admin.
    // Practitioners read; only doctors issue, adjust and stop. The role check
    // runs before route model binding, so others get the same 403 whether or
    // not an id exists.
    Route::middleware('role:doctor,practitioner')->group(function () {
        Route::get('patients/{patient}/prescriptions', [PrescriptionController::class, 'index'])
            ->can('viewPrescriptions', Prescription::class)
            ->name('patients.prescriptions.index');
        Route::get('patients/{patient}/protocols', [AdherenceController::class, 'show'])
            ->can('viewPrescriptions', Prescription::class)
            ->name('patients.protocols.show');
        Route::post('patients/{patient}/prescriptions', [PrescriptionController::class, 'store'])
            ->can('managePrescriptions', Prescription::class)
            ->name('patients.prescriptions.store');
        Route::put('prescriptions/{prescription}', [PrescriptionController::class, 'update'])
            ->can('managePrescriptions', 'prescription')
            ->name('prescriptions.update');
        Route::post('prescriptions/{prescription}/discontinue', [PrescriptionController::class, 'discontinue'])
            ->can('managePrescriptions', 'prescription')
            ->name('prescriptions.discontinue');
        Route::post('prescriptions/{prescription}/items', [PrescriptionController::class, 'addItem'])
            ->can('managePrescriptions', 'prescription')
            ->name('prescriptions.items.store');
        // Authorized against the item's prescription in the request or controller.
        Route::post('protocol-items/{protocolItem}/adjust', [PrescriptionController::class, 'adjustItem'])
            ->name('protocol-items.adjust');
        Route::post('protocol-items/{protocolItem}/discontinue', [PrescriptionController::class, 'discontinueItem'])
            ->name('protocol-items.discontinue');
    });

    // The patient portal. Appointments are looked up among the patient's own
    // (not by route model binding), so other ids are indistinguishable 404s.
    Route::middleware('role:patient')->group(function () {
        Route::get('my-profile', MyProfileController::class)->name('my-profile');

        Route::get('my-appointments', [MyAppointmentController::class, 'index'])
            ->name('my-appointments.index');
        Route::post('my-appointments/{appointment}/cancel', [MyAppointmentController::class, 'cancel'])
            ->whereNumber('appointment')
            ->name('my-appointments.cancel');

        Route::get('my-results', [MyLabResultsController::class, 'index'])->name('my-results.index');
        Route::get('my-results/{report}', [MyLabResultsController::class, 'show'])
            ->whereNumber('report')
            ->name('my-results.show');

        Route::get('my-protocols', [AdherenceController::class, 'index'])->name('my-protocols.index');
        Route::put('my-protocols/items/{item}/adherence', [AdherenceController::class, 'update'])
            ->whereNumber('item')
            ->name('my-protocols.adherence.update');

        Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])
            ->name('notification-settings.edit');
        Route::post('settings/notifications/{channel}', [NotificationSettingsController::class, 'grant'])
            ->name('notification-settings.grant');
        Route::delete('settings/notifications/{channel}', [NotificationSettingsController::class, 'withdraw'])
            ->name('notification-settings.withdraw');
    });
});

require __DIR__.'/settings.php';
