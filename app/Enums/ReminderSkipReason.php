<?php

namespace App\Enums;

/**
 * Why a reminder was not sent on a channel. Stored as a short code in the
 * delivery log, which never holds addresses or message text.
 */
enum ReminderSkipReason: string
{
    case NoConsent = 'no_consent';
    case DestinationChanged = 'destination_changed';
    case NoDestination = 'no_destination';
    case NoPortalAccount = 'no_portal_account';
    case ChannelDisabled = 'channel_disabled';
    case PatientArchived = 'patient_archived';
    case AppointmentNotScheduled = 'appointment_not_scheduled';
    case AppointmentMoved = 'appointment_moved';
}
