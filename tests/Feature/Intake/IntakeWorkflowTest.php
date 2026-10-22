<?php

use App\Enums\AssessmentKind;
use App\Enums\AuditEvent;
use App\Enums\IntakeStatus;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Intake\IntakeSchema;
use App\Models\AuditLog;
use App\Models\FunctionalAssessment;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use App\Notifications\IntakeFormRequestedNotification;
use App\Reminders\NotificationPresenter;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-22 10:00:00', 'UTC'));
    Notification::fake();
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create();
    $this->doctor = userWithRole(UserRole::Doctor);
});

/**
 * Send the form as the doctor and return the token from the emailed link.
 */
function sendIntake(): string
{
    test()->actingAs(test()->doctor)->post(route('patients.intake.send', test()->patient))->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo(test()->patient, IntakeFormRequestedNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return (string) $token;
}

/**
 * @return array<string, mixed>
 */
function intakeAnswers(): array
{
    return [
        'demographics' => ['occupation' => 'Teacher', 'emergency_name' => 'Sam Lovelace', 'secret' => 'dropped'],
        'conditions' => [
            'high_blood_pressure' => ['present' => true, 'age_at_diagnosis' => '48', 'medications' => 'Amlodipine'],
            'cancer' => ['present' => false],
            'other' => 'Migraine',
        ],
        'medications' => [
            ['category' => 'supplement', 'name' => 'Vitamin D3', 'dose' => '2000 IU', 'frequency' => 'Daily'],
            [],
        ],
        'lifestyle' => ['sleep_hours' => '7.5', 'stress' => 'moderate', 'diet_fibre' => 'low'],
        'family_history' => ['alzheimers_parkinsons' => ['present' => true, 'relatives' => 'Mother']],
        'womens_health' => ['menopausal_status' => 'peri', 'children' => '0'],
    ];
}

describe('sending', function () {
    test('a doctor sends the form, which emails a link whose token is stored only as a hash', function () {
        $token = sendIntake();
        $form = PatientIntakeForm::sole();

        expect($form->status)->toBe(IntakeStatus::SentToPatient)
            ->and($form->doctor_id)->toBe($this->doctor->id)
            ->and($form->token_hash)->toBe(hash('sha256', $token))
            ->and($form->token_expires_at->toDateString())->toBe('2026-11-05')
            ->and(strlen($token))->toBe(48)
            ->and(json_encode(DB::table('patient_intake_forms')->get()))->not->toContain($token)
            ->and(auditEntries(AuditEvent::IntakeSent)->sole()->user_id)->toBe($this->doctor->id);
    });

    test('a patient without a portal account cannot be sent the form', function () {
        $patient = Patient::factory()->create();

        $this->actingAs($this->doctor)->post(route('patients.intake.send', $patient))->assertSessionHasErrors('intake');
        expect(PatientIntakeForm::count())->toBe(0);
    });

    test('resending gives a new link and the old one stops working', function () {
        $old = sendIntake();
        Notification::fake();
        // The delivery log ignores a repeat within the same moment.
        $this->travel(1)->minutes();
        $new = sendIntake();

        expect(PatientIntakeForm::count())->toBe(1)
            ->and($new)->not->toBe($old);

        $this->actingAs($this->account)->get(route('intake.open', $old))->assertRedirect(route('my-intake.index'));
        $this->get(route('intake.open', $new))->assertRedirect(route('my-intake.show', PatientIntakeForm::sole()));
    });

    test('only doctors and practitioners send, start or fill in forms', function ($role) {
        $form = PatientIntakeForm::factory()->for($this->patient)->create();
        $this->actingAs(userWithRole($role));

        $this->post(route('patients.intake.send', $this->patient))->assertForbidden();
        $this->post(route('patients.intake.start', $this->patient))->assertForbidden();
        $this->get(route('intake-forms.edit', $form))->assertForbidden();
        $this->put(route('intake-forms.clinical', $form), ['clinical' => ['risk_score' => 10]])->assertForbidden();
    })->with([
        'clinic admin' => UserRole::ClinicAdmin,
        'super admin' => UserRole::SuperAdmin,
        'lab staff' => UserRole::LabStaff,
        'compliance officer' => UserRole::ComplianceOfficer,
        'patient' => UserRole::Patient,
    ]);
});

describe('the patient', function () {
    test('must sign in before the link opens', function () {
        $token = sendIntake();
        auth()->logout();

        $this->get(route('intake.open', $token))->assertRedirect(route('login'));
    });

    test('a link opened by someone else, or out of date, opens nothing', function () {
        $token = sendIntake();

        $this->actingAs(userWithRole(UserRole::Patient))->get(route('intake.open', $token))->assertRedirect(route('my-intake.index'));

        $this->travel(15)->days();
        $this->actingAs($this->account)->get(route('intake.open', $token))->assertRedirect(route('my-intake.index'));
    });

    test('saves progress, with answers cleaned and encrypted', function () {
        sendIntake();
        $form = PatientIntakeForm::sole();

        $this->actingAs($this->account)->put(route('my-intake.update', $form->id), intakeAnswers())->assertSessionHasNoErrors();

        $form->refresh();

        expect($form->status)->toBe(IntakeStatus::SentToPatient)
            ->and($form->demographics)->toBe(['occupation' => 'Teacher', 'emergency_name' => 'Sam Lovelace'])
            ->and($form->conditions)->toBe(['high_blood_pressure' => ['present' => true, 'age_at_diagnosis' => 48, 'medications' => 'Amlodipine'], 'other' => 'Migraine'])
            ->and($form->medications)->toBe([['category' => 'supplement', 'name' => 'Vitamin D3', 'dose' => '2000 IU', 'frequency' => 'Daily']])
            ->and($form->lifestyle)->toBe(['sleep_hours' => 7.5, 'stress' => 'moderate', 'diet_fibre' => 'low'])
            ->and($form->womens_health)->toBe(['menopausal_status' => 'peri', 'children' => 0])
            ->and(json_encode(DB::table('patient_intake_forms')->get()))->not->toContain('Amlodipine')->not->toContain('Teacher');
    });

    test('submits the form, after which it is read-only and the link is spent', function () {
        $token = sendIntake();
        $form = PatientIntakeForm::sole();
        $this->actingAs($this->account);

        $this->post(route('my-intake.submit', $form->id), intakeAnswers())->assertRedirect(route('my-intake.show', $form->id));

        expect($form->fresh())
            ->status->toBe(IntakeStatus::Completed)
            ->submitted_by->toBe($this->account->id)
            ->token_hash->toBeNull();

        $this->put(route('my-intake.update', $form->id), intakeAnswers())->assertStatus(409);
        $this->get(route('intake.open', $token))->assertRedirect(route('my-intake.index'));
        $this->get(route('my-intake.show', $form->id))
            ->assertInertia(fn (Assert $page) => $page->component('intake/Fill')->where('editable', false)->where('form.answers.lifestyle.stress', 'moderate'));
    });

    test('invalid answers are rejected', function (array $answers, string $field) {
        sendIntake();

        $this->actingAs($this->account)
            ->put(route('my-intake.update', PatientIntakeForm::sole()->id), $answers)
            ->assertSessionHasErrors($field);
    })->with([
        'unknown choice' => [['lifestyle' => ['stress' => 'extreme']], 'lifestyle.stress'],
        'impossible sleep' => [['lifestyle' => ['sleep_hours' => '30']], 'lifestyle.sleep_hours'],
        'impossible age' => [['conditions' => ['cancer' => ['age_at_diagnosis' => '200']]], 'conditions.cancer.age_at_diagnosis'],
    ]);

    test('cannot see or change another patient\'s form', function () {
        $other = PatientIntakeForm::factory()->for(Patient::factory()->withAccount())->create(['status' => IntakeStatus::SentToPatient]);
        $this->actingAs($this->account);

        $this->get(route('my-intake.show', $other->id))->assertNotFound();
        $this->put(route('my-intake.update', $other->id), intakeAnswers())->assertNotFound();
        $this->post(route('my-intake.submit', $other->id), intakeAnswers())->assertNotFound();
    });

    test('lists their questionnaires and is told in the inbox', function () {
        sendIntake();

        $this->actingAs($this->account)
            ->get(route('my-intake.index'))
            ->assertInertia(fn (Assert $page) => $page->component('intake/Mine')->where('forms.0.status', 'sent_to_patient'));

        $notification = new DatabaseNotification([
            'id' => (string) Str::uuid(),
            'type' => 'intake.requested',
            'data' => ['intake_form_id' => PatientIntakeForm::sole()->id],
        ]);

        expect(app(NotificationPresenter::class)->present($notification, $this->account)['url'])
            ->toBe(route('my-intake.show', PatientIntakeForm::sole()));
    });
});

describe('the clinic', function () {
    test('fills the form in with the patient and completes it', function () {
        $this->actingAs($this->doctor)->post(route('patients.intake.start', $this->patient))
            ->assertRedirect(route('intake-forms.edit', PatientIntakeForm::sole()));

        $form = PatientIntakeForm::sole();
        expect($form->status)->toBe(IntakeStatus::Draft);

        $this->get(route('intake-forms.edit', $form))->assertInertia(fn (Assert $page) => $page->where('mode', 'clinician')->where('editable', true));
        $this->post(route('intake-forms.complete', $form), intakeAnswers())->assertRedirect(route('patients.show', ['patient' => $this->patient, 'tab' => 'measurements']));

        expect($form->fresh()->status)->toBe(IntakeStatus::Completed)
            ->and($form->fresh()->submitted_by)->toBe($this->doctor->id);
    });

    test('records the functional assessment and scores, which also go into the measurement history', function () {
        $form = PatientIntakeForm::factory()->for($this->patient)->completed()->create();

        $this->actingAs($this->doctor)
            ->put(route('intake-forms.clinical', $form), ['clinical' => [
                'height_cm' => '170', 'weight_kg' => '72.25', 'waist_cm' => '85',
                'systolic_bp' => '124', 'diastolic_bp' => '80',
                'chair_stand_reps' => '14', 'walk_6min_m' => '520',
                'metabolic_score' => '62', 'risk_score' => '30', 'score_notes' => 'Borderline metabolic',
            ]])
            ->assertSessionHasNoErrors();

        $assessments = FunctionalAssessment::orderBy('id')->get();

        expect($form->fresh()->clinical)->toMatchArray(['height_cm' => 170.0, 'metabolic_score' => 62, 'score_notes' => 'Borderline metabolic'])
            ->and(IntakeSchema::derived($form->fresh()->clinical))->toBe(['bmi' => 25.0, 'waist_to_height' => 0.5])
            ->and($assessments->pluck('kind')->all())->toBe([AssessmentKind::AtHome, AssessmentKind::AtCenter])
            ->and($assessments->pluck('recorded_via')->unique()->all())->toBe(['clinic'])
            ->and(array_keys($assessments[1]->measurements))->toBe(['chair_stand_reps', 'walk_6min_m']);
    });

    test('blood pressure must be a pair with the systolic higher', function () {
        $form = PatientIntakeForm::factory()->for($this->patient)->create();

        $this->actingAs($this->doctor)
            ->put(route('intake-forms.clinical', $form), ['clinical' => ['systolic_bp' => '80', 'diastolic_bp' => '90']])
            ->assertSessionHasErrors('clinical.systolic_bp');

        expect(FunctionalAssessment::count())->toBe(0);
    });
});

describe('the patient workspace', function () {
    test('shows the tabs the user can use, with measurements loaded only when that tab opens', function () {
        PatientIntakeForm::factory()->for($this->patient)->completed()->create();
        $this->actingAs($this->doctor);

        $this->get(route('patients.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'overview')
                ->missing('measurements')
                ->where('can.manageIntake', true));

        $this->get(route('patients.show', ['patient' => $this->patient, 'tab' => 'measurements']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'measurements')
                ->where('measurements.intake.current.status', 'completed')
                ->where('measurements.intake.current.answers.lifestyle.stress', 'moderate')
                ->has('measurements.intake.schema.clinical')
                ->where('measurements.intake.hasPortalAccount', true));
    });

    test('opening the measurements tab is audited on its own, without recording another record view', function () {
        PatientIntakeForm::factory()->for($this->patient)->completed()->create();
        $this->actingAs($this->doctor);

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'patients/Show',
            'X-Inertia-Partial-Data' => 'measurements',
        ])->get(route('patients.show', ['patient' => $this->patient, 'tab' => 'measurements']))
            ->assertOk()
            ->assertJsonPath('props.measurements.intake.current.status', 'completed')
            ->assertJsonMissingPath('props.patient');

        expect(auditEntries(AuditEvent::PatientViewed))->toHaveCount(0)
            ->and(auditEntries(AuditEvent::IntakeViewed))->toHaveCount(1)
            ->and(auditEntries(AuditEvent::AssessmentsViewed))->toHaveCount(1);
    });

    test('non-clinical staff get no measurements tab', function () {
        $this->actingAs(userWithRole(UserRole::ClinicAdmin))
            ->get(route('patients.show', ['patient' => $this->patient, 'tab' => 'measurements']))
            ->assertInertia(fn (Assert $page) => $page->where('measurements', null)->where('can.manageIntake', false));
    });

    test('the audit log never holds an answer', function () {
        sendIntake();
        $form = PatientIntakeForm::sole();
        $this->actingAs($this->account)->post(route('my-intake.submit', $form->id), intakeAnswers());
        $this->actingAs($this->doctor)->put(route('intake-forms.clinical', $form), ['clinical' => ['risk_score' => '30', 'score_notes' => 'Borderline metabolic']]);

        expect(auditEntries(AuditEvent::IntakeAnswered)->first()->metadata['sections'])->toContain('conditions', 'lifestyle')
            ->and(json_encode(AuditLog::query()->toBase()->get()))
            ->not->toContain('Amlodipine')->not->toContain('Borderline')->not->toContain('Teacher');
    });
});

test('intake forms are never deleted', function () {
    PatientIntakeForm::factory()->create()->delete();
})->throws(MedicalRecordRetentionException::class);
