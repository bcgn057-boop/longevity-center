<?php

namespace App\Enums;

enum AuditEvent: string
{
    // Authentication
    case Login = 'auth.login';
    case Logout = 'auth.logout';
    case LoginFailed = 'auth.login_failed';
    case Lockout = 'auth.lockout';
    case Registered = 'auth.registered';
    case EmailVerified = 'auth.email_verified';
    case PasswordReset = 'auth.password_reset';

    // Two-factor authentication and passkeys
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorConfirmed = 'auth.two_factor_confirmed';
    case TwoFactorDisabled = 'auth.two_factor_disabled';
    case TwoFactorFailed = 'auth.two_factor_failed';
    case RecoveryCodesRegenerated = 'auth.recovery_codes_regenerated';
    case PasskeyRegistered = 'auth.passkey_registered';
    case PasskeyDeleted = 'auth.passkey_deleted';

    // Account changes
    case PasswordChanged = 'user.password_changed';
    case EmailChanged = 'user.email_changed';
    case RoleChanged = 'user.role_changed';
    case UserDeleted = 'user.deleted';

    // Authorization
    case AccessDenied = 'access.denied';

    // Patient records
    case PatientCreated = 'patient.created';
    case PatientViewed = 'patient.viewed';
    case PatientUpdated = 'patient.updated';
    case PatientArchived = 'patient.archived';
    case PatientRestored = 'patient.restored';
    case PatientSearched = 'patient.searched';
    case PatientAccountLinked = 'patient.account_linked';
    case PatientAccountUnlinked = 'patient.account_unlinked';

    // Appointments
    case AppointmentCreated = 'appointment.created';
    case AppointmentViewed = 'appointment.viewed';
    case AppointmentListed = 'appointment.listed';
    case AppointmentUpdated = 'appointment.updated';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentCompleted = 'appointment.completed';
    case AppointmentCancelled = 'appointment.cancelled';

    // Lab results
    case LabReportCreated = 'lab_report.created';
    case LabReportListed = 'lab_report.listed';
    case LabReportViewed = 'lab_report.viewed';
    case LabReportUpdated = 'lab_report.updated';
    case LabReportReleased = 'lab_report.released';
    case LabResultRecorded = 'lab_result.recorded';
    case LabResultCorrected = 'lab_result.corrected';

    // Care reminders
    case CareReminderCreated = 'care_reminder.created';
    case CareReminderCancelled = 'care_reminder.cancelled';

    // Notification consent
    case NotificationConsentGranted = 'notification.consent_granted';
    case NotificationConsentWithdrawn = 'notification.consent_withdrawn';

    // Audit log access
    case AuditLogViewed = 'audit.viewed';
}
