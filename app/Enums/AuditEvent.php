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
    case UserCreated = 'user.created';
    case LabPartnerChanged = 'user.lab_partner_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case InvitationSent = 'user.invitation_sent';
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
    case LabReportAttachmentDownloaded = 'lab_report.attachment_downloaded';
    case LabResultRecorded = 'lab_result.recorded';
    case LabResultCorrected = 'lab_result.corrected';
    case LabRequisitionOrdered = 'lab_requisition.ordered';
    case LabRequisitionCancelled = 'lab_requisition.cancelled';
    case LabRequisitionListed = 'lab_requisition.listed';

    // Prescriptions and protocols
    case PrescriptionIssued = 'prescription.issued';
    case PrescriptionListed = 'prescription.listed';
    case PrescriptionUpdated = 'prescription.updated';
    case PrescriptionDiscontinued = 'prescription.discontinued';
    case ProtocolItemAdded = 'protocol_item.added';
    case ProtocolItemAdjusted = 'protocol_item.adjusted';
    case ProtocolItemDiscontinued = 'protocol_item.discontinued';
    case AdherenceRecorded = 'protocol_adherence.recorded';

    // Functional assessments
    case AssessmentRecorded = 'assessment.recorded';
    case AssessmentsViewed = 'assessment.viewed';

    // Care reminders
    case CareReminderCreated = 'care_reminder.created';
    case CareReminderCancelled = 'care_reminder.cancelled';

    // Notification consent
    case NotificationConsentGranted = 'notification.consent_granted';
    case NotificationConsentWithdrawn = 'notification.consent_withdrawn';

    // Audit log access and compliance reporting
    case AuditLogViewed = 'audit.viewed';
    case AuditLogExported = 'audit.exported';
    case AnalyticsViewed = 'analytics.viewed';

    /**
     * The compliance-report group the event belongs to. Every read of a
     * patient's records is PHI access, whatever the record type.
     */
    public function category(): AuditCategory
    {
        if (in_array($this, [
            self::PatientViewed, self::PatientSearched, self::AppointmentViewed, self::AppointmentListed,
            self::LabReportListed, self::LabReportViewed, self::LabReportAttachmentDownloaded, self::PrescriptionListed,
            self::LabRequisitionListed, self::AssessmentsViewed,
        ], true)) {
            return AuditCategory::PhiAccess;
        }

        return match (true) {
            $this === self::AccessDenied => AuditCategory::Security,
            str_starts_with($this->value, 'auth.') => AuditCategory::Authentication,
            str_starts_with($this->value, 'user.') => AuditCategory::Account,
            str_starts_with($this->value, 'patient.'),
            str_starts_with($this->value, 'assessment.') => AuditCategory::PatientRecords,
            str_starts_with($this->value, 'appointment.') => AuditCategory::Appointments,
            str_starts_with($this->value, 'lab_') => AuditCategory::Labs,
            str_starts_with($this->value, 'prescription.'),
            str_starts_with($this->value, 'protocol_') => AuditCategory::Prescriptions,
            str_starts_with($this->value, 'care_reminder.') => AuditCategory::CareReminders,
            str_starts_with($this->value, 'notification.') => AuditCategory::Consent,
            default => AuditCategory::Compliance,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function inCategory(AuditCategory $category): array
    {
        return array_values(array_filter(self::cases(), fn (self $event) => $event->category() === $category));
    }
}
