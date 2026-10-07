<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Explicitly link a patient record to a login account, chosen by staff.
 * Never automatic: matching by email alone could expose medical data to
 * whoever registers with that address. Changes are audited by PatientObserver.
 */
class PatientAccountLinkController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if ($patient->user_id !== null) {
            throw ValidationException::withMessages([
                'email' => __('This patient is already linked to an account. Unlink it first.'),
            ]);
        }

        $user = User::where('email', $validated['email'])->first();

        $error = match (true) {
            $user === null => __('No account exists with this email address.'),
            ! $user->hasRole(UserRole::Patient) => __('Only accounts with the Patient role can be linked.'),
            ! $user->hasVerifiedEmail() => __('The account must verify its email address before it can be linked.'),
            $user->patient()->exists() => __('This account is already linked to another patient record.'),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['email' => $error]);
        }

        $patient->user()->associate($user)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account linked.')]);

        return to_route('patients.show', $patient);
    }

    public function destroy(Patient $patient): RedirectResponse
    {
        $patient->user()->dissociate()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account unlinked.')]);

        return to_route('patients.show', $patient);
    }
}
