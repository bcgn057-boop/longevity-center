<?php

namespace App\Http\Middleware;

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Allow the request only if the user has one of the given roles.
     *
     * Usage: ->middleware('role:super_admin,clinic_admin')
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role): UserRole => UserRole::from($role), $roles);
        $user = $request->user();

        if (! $user?->hasAnyRole(...$allowed)) {
            $this->audit->record(AuditEvent::AccessDenied, metadata: [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'required_roles' => $roles,
                'user_role' => $user?->role?->slug->value,
            ]);

            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
