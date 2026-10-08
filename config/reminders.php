<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reminder Channels
    |--------------------------------------------------------------------------
    |
    | The channels reminders may be sent through. Each is a Laravel
    | notification channel; adding SMS later means writing a channel class
    | and adding an entry here.
    |
    | Patients only receive email or WhatsApp after giving consent for that
    | channel and that exact address. In-app (database) notifications need a
    | linked portal account but no separate consent.
    |
    | WhatsApp is off by default. Its "log" provider sends nothing and only
    | records the template name and a masked number, for development.
    |
    */

    'channels' => [

        'database' => [
            'enabled' => (bool) env('REMINDERS_DATABASE_ENABLED', true),
        ],

        'mail' => [
            'enabled' => (bool) env('REMINDERS_MAIL_ENABLED', true),
        ],

        'whatsapp' => [
            'enabled' => (bool) env('REMINDERS_WHATSAPP_ENABLED', false),

            // log | meta
            'provider' => env('WHATSAPP_PROVIDER', 'log'),

            // An approved message template with NO variables, e.g.:
            // "You have an upcoming appointment with Longevity Center.
            //  Please sign in to view the details: https://portal.example.com/my-appointments"
            'template' => env('WHATSAPP_REMINDER_TEMPLATE', 'appointment_reminder'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment Reminder Timing
    |--------------------------------------------------------------------------
    |
    | reminders:dispatch (every 5 minutes) reminds patients of scheduled
    | appointments starting within the lead time. Appointments starting
    | sooner than the minimum notice are not reminded: the reminder would
    | arrive too late to help. If the scheduler was down, missed reminders
    | are sent on the next run while the appointment is still in the window.
    |
    */

    'appointment_lead_minutes' => (int) env('REMINDERS_APPOINTMENT_LEAD_MINUTES', 24 * 60),

    'minimum_notice_minutes' => (int) env('REMINDERS_MINIMUM_NOTICE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Clinician Daily Summary
    |--------------------------------------------------------------------------
    |
    | From this hour (clinic timezone), each clinician with appointments the
    | next day gets one in-app summary: the date and how many appointments,
    | never patient names. reminders:clinician-summaries runs hourly, so a
    | missed run is caught up the next hour.
    |
    */

    'clinician_summary_hour' => (int) env('REMINDERS_CLINICIAN_SUMMARY_HOUR', 18),

    /*
    |--------------------------------------------------------------------------
    | Consent Wording Version
    |--------------------------------------------------------------------------
    |
    | Increase this whenever the consent wording shown to patients changes, so
    | each recorded consent shows which wording the patient agreed to.
    |
    */

    'consent_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | Delivery Retries
    |--------------------------------------------------------------------------
    |
    | A failed send (e.g. a provider outage or rate limit) is retried after
    | these delays in seconds, then recorded as failed.
    |
    */

    'backoff' => [60, 300, 900],

];
