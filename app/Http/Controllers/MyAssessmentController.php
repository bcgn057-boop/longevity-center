<?php

namespace App\Http\Controllers;

use App\Assessments\AssessmentPresenter;
use App\Enums\AssessmentKind;
use App\Enums\AuditEvent;
use App\Http\Requests\Assessments\RecordAssessmentRequest;
use App\Models\FunctionalAssessment;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A patient's own measurements. They record at-home measurements; the
 * at-center functional tests are shown but only clinicians record them.
 */
class MyAssessmentController extends Controller
{
    public function index(Request $request, AuditLogger $audit): Response
    {
        $patient = $request->user()?->patient;
        $forms = array_values(array_filter(AssessmentPresenter::forms(), fn (array $form) => $form['kind'] === AssessmentKind::AtHome->value));

        if (! $patient instanceof Patient) {
            return Inertia::render('assessments/Mine', ['linked' => false, 'forms' => $forms, 'latest' => [], 'history' => [], 'today' => now((string) config('clinic.timezone'))->toDateString()]);
        }

        $assessments = $patient->assessments()->get();

        $audit->record(AuditEvent::AssessmentsViewed, auditable: $patient, metadata: ['context' => 'self', 'results' => $assessments->count()]);

        return Inertia::render('assessments/Mine', [
            'linked' => true,
            'forms' => $forms,
            'latest' => AssessmentPresenter::latest($assessments),
            'history' => AssessmentPresenter::history($assessments, showRecorder: false),
            'today' => now((string) config('clinic.timezone'))->toDateString(),
        ]);
    }

    public function store(RecordAssessmentRequest $request): RedirectResponse
    {
        $patient = $request->user()?->patient;
        abort_unless($patient instanceof Patient, 404);

        if ($request->kind() !== AssessmentKind::AtHome) {
            throw ValidationException::withMessages(['kind' => 'Functional tests are recorded by your clinician at the clinic.']);
        }

        $assessment = new FunctionalAssessment([
            'kind' => AssessmentKind::AtHome,
            'measured_on' => $request->measuredOn(),
            'measurements' => $request->measurements(),
            'notes' => filled($request->validated('notes')) ? trim((string) $request->validated('notes')) : null,
        ]);
        $assessment->patient_id = $patient->id;
        $assessment->recorded_by = $request->user()->id;
        $assessment->recorded_via = FunctionalAssessment::VIA_PORTAL;
        $assessment->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Measurements saved.')]);

        return to_route('my-measurements.index');
    }
}
