<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response, including errors and downloads.
 *
 * The Content Security Policy only runs scripts from this site plus the one
 * inline script in app.blade.php, which carries a fresh nonce per request.
 * Styles allow inline code, because Inertia's progress bar and the toast
 * library inject <style> tags at runtime; scripts, the real risk, do not.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before the page renders, so @vite tags and the inline script get it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // PHP adds this outside the response object.
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            // URLs can hold patient ids and search terms: never send them to
            // other sites.
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // Laravel's debug error page runs its own inline scripts. Debug mode
        // is forced off in production, so this only affects local errors.
        if (! (config('app.debug') && $response->getStatusCode() >= 500)) {
            $headers[config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $this->contentSecurityPolicy($request, $nonce);
        }

        $maxAge = (int) config('security.hsts_max_age');

        if ($maxAge > 0 && (app()->isProduction() || $request->isSecure())) {
            $headers['Strict-Transport-Security'] = "max-age={$maxAge}; includeSubDomains";
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request, string $nonce): string
    {
        // While `vite dev` runs, assets and hot reloads come from its server.
        $dev = Vite::isRunningHot() ? $this->devServer() : [];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            'img-src' => ["'self'", 'data:', 'blob:', ...$dev],
            'font-src' => ["'self'", 'data:', ...$dev],
            'connect-src' => ["'self'", ...$dev, ...array_map(fn (string $origin) => preg_replace('#^http#', 'ws', $origin), $dev)],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        $policy = array_map(fn (string $name, array $sources) => $name.' '.implode(' ', array_unique($sources)), array_keys($directives), $directives);

        if (app()->isProduction() || $request->isSecure()) {
            $policy[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $policy);
    }

    /**
     * The Vite dev server's origin, from the hot file it writes.
     *
     * @return array<int, string>
     */
    private function devServer(): array
    {
        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }
}
