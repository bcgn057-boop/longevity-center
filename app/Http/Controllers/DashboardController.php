<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardData;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home dashboard: summary counts and shortcuts for the user's role.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Dashboard', [
            'greeting' => $user->name,
            'role' => $user->role?->slug->label(),
            'today' => now((string) config('clinic.timezone'))->toDateString(),
            ...DashboardData::for($user),
        ]);
    }
}
