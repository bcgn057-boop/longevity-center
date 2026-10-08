<?php

namespace App\Http\Controllers;

use App\Reminders\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's in-app notifications. Only their own are ever
 * looked up, so another user's notification id is a plain 404.
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationPresenter $presenter): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $notifications = $user->notifications()->latest()->paginate(20);

        return Inertia::render('notifications/Index', [
            'notifications' => $notifications->through(
                fn (DatabaseNotification $notification) => $presenter->present($notification, $user),
            ),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()?->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
