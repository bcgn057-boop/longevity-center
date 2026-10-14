<?php

use App\Enums\AuditEvent;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\AuditLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Global, so errors, downloads and the health check get them too.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // Check roles before resolving route models, so a denied user cannot
        // probe which record ids exist (403 for all, never 404 vs 403).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureUserHasRole::class,
        );

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Audit policy denials. (Role middleware denials are audited by the
        // middleware itself and raise a plain HttpException, so are not repeated.)
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->user() !== null) {
                app(AuditLogger::class)->record(AuditEvent::AccessDenied, metadata: [
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'route' => $request->route()?->getName(),
                    'reason' => 'policy',
                    'user_role' => $request->user()->role?->slug->value,
                ]);
            }

            return null;
        });
    })->create();
