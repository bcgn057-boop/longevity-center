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

    // Audit log access
    case AuditLogViewed = 'audit.viewed';
}
