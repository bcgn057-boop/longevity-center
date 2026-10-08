<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic Name
    |--------------------------------------------------------------------------
    |
    | Shown in messages sent to patients.
    |
    */

    'name' => env('CLINIC_NAME', env('APP_NAME', 'Longevity Center')),

    /*
    |--------------------------------------------------------------------------
    | Clinic Timezone
    |--------------------------------------------------------------------------
    |
    | All dates are stored in UTC. This timezone is used to display dates and
    | times to staff and patients, and to render reminder messages.
    |
    */

    'timezone' => env('CLINIC_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Medical Record Numbers
    |--------------------------------------------------------------------------
    |
    | Patients are assigned a medical record number of the form PREFIX000123.
    |
    */

    'mrn_prefix' => env('CLINIC_MRN_PREFIX', 'LC-'),

    'mrn_padding' => 6,

];
