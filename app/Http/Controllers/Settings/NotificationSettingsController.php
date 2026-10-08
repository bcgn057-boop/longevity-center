<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Reminders\NotificationConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient's own consent to email and WhatsApp reminders.
 */
class NotificationSettingsController extends Controller
{
    public function __construct(private readonly NotificationConsent $consent) {}

    public function edit(Request $request): Response
    {
        $patient = $request->user()?->patient;

        return Inertia::render('settings/Notifications', [
            'linked' => $patient !== null,
            'channels' => $patient ? $this->consent->summary($patient) : [],
        ]);
    }

    public function grant(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $patient = $this->patient($request, $channel);

        $request->validate(['agree' => ['accepted']], [
            'agree.accepted' => 'Please confirm that you agree to the wording above.',
        ]);

        if ($patient->reminderDestination($channel) === null) {
            throw ValidationException::withMessages([
                'agree' => "Your record has no {$this->addressName($channel)}. Please contact the clinic to add one.",
            ]);
        }

        $this->consent->grant($patient, $channel, NotificationPreference::SOURCE_SELF, $request->user());

        return back();
    }

    public function withdraw(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $this->consent->withdraw($this->patient($request, $channel), $channel, NotificationPreference::SOURCE_SELF, $request->user());

        return back();
    }

    private function patient(Request $request, NotificationChannel $channel): Patient
    {
        abort_unless($channel->requiresConsent() && $channel->enabled(), 404);

        $patient = $request->user()?->patient;
        abort_if($patient === null, 404);

        return $patient;
    }

    private function addressName(NotificationChannel $channel): string
    {
        return $channel === NotificationChannel::Mail ? 'email address' : 'phone number';
    }
}
