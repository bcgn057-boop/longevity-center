<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out deactivated accounts on their next request, however they signed
 * in (password, passkey or remember-me cookie) and even mid-session.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDeactivated()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'This account has been deactivated.'], 403)
                : redirect()->route('login')->with('status', 'This account has been deactivated. Contact the clinic if you think this is a mistake.');
        }

        return $next($request);
    }
}
