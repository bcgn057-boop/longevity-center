<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | How long browsers should insist on HTTPS for this site, in seconds. Sent
    | on every response in production and on HTTPS responses elsewhere. Set
    | to 0 to stop sending the header.
    |
    */

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Report-only mode sends the policy without enforcing it, so a new policy
    | can be tried against real traffic before it can break anything.
    |
    */

    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

];
