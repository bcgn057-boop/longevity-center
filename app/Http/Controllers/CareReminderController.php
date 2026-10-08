<?php

namespace App\Http\Controllers;

use App\Enums\CareReminderStatus;
use App\Models\CareReminder;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Staff-written care reminders. Authorized on the routes: clinic staff only,
 * not the Super Admin, because the text may be clinical.
 */
class CareReminderController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        if ($patient->user_id === null) {
            throw ValidationException::withMessages([
                'title' => 'Care reminders are read in the patient portal. Link a portal account first.',
            ]);
        }

        $dueAt = Carbon::createFromFormat('!Y-m-d H:i', $validated['date'].' '.$validated['time'], (string) config('clinic.timezone'));

        if (! $dueAt->isFuture()) {
            throw ValidationException::withMessages(['time' => 'Choose a time in the future.']);
        }

        $reminder = new CareReminder([
            'title' => trim($validated['title']),
            'message' => filled($validated['message'] ?? null) ? trim((string) $validated['message']) : null,
            'due_at' => $dueAt,
        ]);
        $reminder->patient_id = $patient->id;
        $reminder->created_by = $request->user()?->id;
        $reminder->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Care reminder scheduled.')]);

        return to_route('patients.show', $patient);
    }

    public function cancel(Request $request, CareReminder $careReminder): RedirectResponse
    {
        if ($careReminder->status !== CareReminderStatus::Scheduled) {
            throw ValidationException::withMessages(['status' => 'Only scheduled reminders can be cancelled.']);
        }

        $careReminder->cancel($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Care reminder cancelled.')]);

        return to_route('patients.show', $careReminder->patient_id);
    }
}
