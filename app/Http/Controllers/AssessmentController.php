<?php

namespace App\Http\Controllers;

use App\Assessments\AssessmentPresenter;
use App\Enums\AuditEvent;
use App\Http\Requests\Assessments\RecordAssessmentRequest;
use App\Models\FunctionalAssessment;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clinicians recording and reviewing a patient's measurements, both at-home
 * and the at-center functional tests only they may record. Authorized on
 * the routes and per kind here; reads audited here, writes by the observer.
 */
class AssessmentController extends Controller
{
    public function show(Patient $patient, AuditLogger $audit): Response
    {
        $assessments = $patient->assessments()->with('recorder:id,name')->get();

        $audit->record(AuditEvent::AssessmentsViewed, auditable: $patient, metadata: ['results' => $assessments->count()]);

        return Inertia::render('assessments/Patient', [
            'patient' => ['id' => $patient->id, 'full_name' => $patient->full_name, 'medical_record_number' => $patient->medical_record_number],
            'forms' => AssessmentPresenter::forms(),
            'latest' => AssessmentPresenter::latest($assessments),
            'history' => AssessmentPresenter::history($assessments, showRecorder: true),
            'today' => now((string) config('clinic.timezone'))->toDateString(),
        ]);
    }

    public function store(RecordAssessmentRequest $request, Patient $patient): RedirectResponse
    {
        $kind = $request->kind();
        abort_if($kind === null, 422);
        Gate::authorize('recordAssessment', [FunctionalAssessment::class, $kind]);

        $assessment = new FunctionalAssessment([
            'kind' => $kind,
            'measured_on' => $request->measuredOn(),
            'measurements' => $request->measurements(),
            'notes' => filled($request->validated('notes')) ? trim((string) $request->validated('notes')) : null,
        ]);
        $assessment->patient_id = $patient->id;
        $assessment->recorded_by = $request->user()?->id;
        $assessment->recorded_via = FunctionalAssessment::VIA_CLINIC;
        $assessment->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':kind recorded.', ['kind' => $kind->label()])]);

        // Back to the measurements page or the patient's measurements tab.
        return back(fallback: route('patients.assessments.show', $patient));
    }
}
