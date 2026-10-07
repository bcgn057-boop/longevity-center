<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Resources\PatientResource;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient's read-only view of their own record.
 */
class MyProfileController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): Response
    {
        $patient = $request->user()?->patient()->with(['emergencyContacts', 'user'])->first();

        if ($patient !== null) {
            $audit->record(
                AuditEvent::PatientViewed,
                auditable: $patient,
                metadata: ['context' => 'self', 'sections' => ['demographics', 'contact', 'emergency_contacts', 'medical']],
            );
        }

        return Inertia::render('profile/MyProfile', [
            'patient' => $patient ? (new PatientResource($patient))->resolve($request) : null,
        ]);
    }
}
