<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Reminders\NotificationConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Staff recording a patient's consent to email and WhatsApp reminders, after
 * reading them the consent wording.
 */
class PatientConsentController extends Controller
{
    public function __construct(private readonly NotificationConsent $consent) {}

    public function store(Request $request, Patient $patient, NotificationChannel $channel): RedirectResponse
    {
        abort_unless($channel->requiresConsent() && $channel->enabled(), 404);

        $request->validate(['confirmed' => ['accepted']], [
            'confirmed.accepted' => 'Confirm that the patient has agreed to the wording.',
        ]);

        if ($patient->reminderDestination($channel) === null) {
            throw ValidationException::withMessages([
                'confirmed' => 'Add the patient\'s '.($channel === NotificationChannel::Mail ? 'email address' : 'phone number').' first.',
            ]);
        }

        $this->consent->grant($patient, $channel, NotificationPreference::SOURCE_STAFF, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':channel consent recorded.', ['channel' => $channel->label()])]);

        return to_route('patients.show', $patient);
    }

    public function destroy(Request $request, Patient $patient, NotificationChannel $channel): RedirectResponse
    {
        abort_unless($channel->requiresConsent() && $channel->enabled(), 404);

        $this->consent->withdraw($patient, $channel, NotificationPreference::SOURCE_STAFF, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':channel consent withdrawn.', ['channel' => $channel->label()])]);

        return to_route('patients.show', $patient);
    }
}
