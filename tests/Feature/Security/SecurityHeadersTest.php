<?php

use App\Enums\UserRole;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

/**
 * The Content-Security-Policy header as directive => sources.
 *
 * @return array<string, array<int, string>>
 */
function cspOf(TestResponse $response, string $header = 'Content-Security-Policy'): array
{
    $policy = [];

    foreach (explode(';', (string) $response->headers->get($header)) as $directive) {
        $parts = preg_split('/\s+/', trim($directive));

        if ($parts && $parts[0] !== '') {
            $policy[array_shift($parts)] = $parts;
        }
    }

    return $policy;
}

describe('headers', function () {
    test('every response carries the baseline security headers', function (string $uri, int $status) {
        $response = $this->get($uri)->assertStatus($status);

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeaderMissing('X-Powered-By');

        expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')
            ->and(cspOf($response))->toHaveKeys(['default-src', 'script-src', 'frame-ancestors', 'object-src']);
    })->with([
        'a page' => ['/login', 200],
        'the health check' => ['/up', 200],
        'a missing page' => ['/no-such-page', 404],
        'a redirect' => ['/dashboard', 302],
    ]);

    test('the policy only runs this site\'s scripts and the nonced inline script', function () {
        $response = $this->get('/login');
        $csp = cspOf($response);

        $nonce = collect($csp['script-src'])->first(fn (string $source) => str_starts_with($source, "'nonce-"));
        $nonce = substr((string) $nonce, 7, -1);

        expect($csp['default-src'])->toBe(["'self'"])
            ->and($csp['script-src'])->toBe(["'self'", "'nonce-{$nonce}'"])
            ->and($csp['object-src'])->toBe(["'none'"])
            ->and($csp['frame-ancestors'])->toBe(["'self'"])
            ->and($csp['base-uri'])->toBe(["'self'"])
            ->and($csp['form-action'])->toBe(["'self'"])
            ->and(implode(' ', $csp['script-src']))->not->toContain('unsafe')
            ->and(strlen($nonce))->toBeGreaterThanOrEqual(16)
            // The only inline script carries this request's nonce.
            ->and($response->getContent())->toContain("<script nonce=\"{$nonce}\">");
    });

    test('each request gets a fresh nonce', function () {
        $first = cspOf($this->get('/login'))['script-src'][1];
        $second = cspOf($this->get('/login'))['script-src'][1];

        expect($first)->not->toBe($second);
    });

    test('HSTS is sent over HTTPS and always in production, but not over plain HTTP elsewhere', function () {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $this->app['env'] = 'production';
        $response = $this->get('/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        expect($response->headers->get('Content-Security-Policy'))->toContain('upgrade-insecure-requests');
    });

    test('the local debug error page is left without a policy, so its scripts run', function () {
        config(['app.debug' => true]);
        Route::get('/boom', fn () => throw new RuntimeException('boom'));

        $this->get('/boom')->assertStatus(500)
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        config(['app.debug' => false]);
        $this->get('/boom')->assertStatus(500)->assertHeader('Content-Security-Policy');
    });

    test('HSTS can be switched off', function () {
        config(['security.hsts_max_age' => 0]);

        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    });

    test('the policy can be tried in report-only mode', function () {
        config(['security.csp_report_only' => true]);

        $response = $this->get('/login')->assertHeaderMissing('Content-Security-Policy');

        expect(cspOf($response, 'Content-Security-Policy-Report-Only'))->toHaveKey('script-src');
    });

    test('while the Vite dev server runs, it is allowed too', function () {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, 'http://localhost:5173');
        // The test base swaps in a Vite that never reports a dev server.
        $this->withVite();
        Vite::useHotFile($hot);

        $csp = cspOf($this->get('/login'));
        unlink($hot);

        expect($csp['script-src'])->toContain('http://localhost:5173')
            ->and($csp['connect-src'])->toContain('ws://localhost:5173');
    });

    test('downloads and exports get the headers too', function () {
        $this->actingAs(userWithRole(UserRole::ComplianceOfficer))
            ->get(route('compliance.audit-logs.export', ['format' => 'csv']))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    });
});

describe('sessions and debugging', function () {
    test('session cookies are HTTP-only, same-site and encrypted at rest by default', function () {
        // Read the shipped defaults, whatever the local .env overrides.
        $saved = getenv('SESSION_ENCRYPT');
        putenv('SESSION_ENCRYPT');
        unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);

        try {
            $config = require config_path('session.php');
        } finally {
            if ($saved !== false) {
                putenv("SESSION_ENCRYPT={$saved}");
                $_ENV['SESSION_ENCRYPT'] = $_SERVER['SESSION_ENCRYPT'] = $saved;
            }
        }

        expect($config['http_only'])->toBeTrue()
            ->and($config['same_site'])->toBe('lax')
            ->and($config['encrypt'])->toBeTrue();
    });

    test('session cookies are HTTPS-only by default in production, and not locally', function (string $env, bool $secure) {
        $original = getenv('APP_ENV');
        putenv("APP_ENV={$env}");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;

        try {
            $config = require config_path('session.php');
        } finally {
            putenv("APP_ENV={$original}");
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $original;
        }

        expect($config['secure'])->toBe($secure);
    })->with([['production', true], ['local', false]]);

    test('debug mode is forced off in production', function () {
        Log::spy();
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $configure = new ReflectionMethod(AppServiceProvider::class, 'configureDefaults');
        $configure->invoke(new AppServiceProvider($this->app));

        expect(config('app.debug'))->toBeFalse();
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'APP_DEBUG'));
    });

    test('debug mode is left alone outside production', function () {
        config(['app.debug' => true]);

        $configure = new ReflectionMethod(AppServiceProvider::class, 'configureDefaults');
        $configure->invoke(new AppServiceProvider($this->app));

        expect(config('app.debug'))->toBeTrue();
    });
});
