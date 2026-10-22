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

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Addresses of the proxies in front of the app (a load balancer, nginx, or
    | a tunnel such as localtunnel), comma-separated. Their X-Forwarded-*
    | headers tell the app the visitor's real IP and that the original request
    | was HTTPS, so links and assets use https://. Empty trusts none. Never use
    | "*" in production: anyone could then fake the IP in the audit log.
    |
    */

    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

];
