<?php

use App\Enums\AssessmentKind;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Models\AuditLog;
use App\Models\FunctionalAssessment;
use App\Models\Patient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'UTC'));
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create();
    $this->doctor = userWithRole(UserRole::Doctor);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function atHome(array $overrides = []): array
{
    return ['kind' => 'at_home', 'measured_on' => '2026-10-15', 'height_cm' => '172.4', 'weight_kg' => '70.25', 'waist_cm' => '84', 'systolic_bp' => '128', 'diastolic_bp' => '82', ...$overrides];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function atCenter(array $overrides = []): array
{
    return ['kind' => 'at_center', 'measured_on' => '2026-10-16', 'grip_left_kg' => '31.5', 'grip_right_kg' => '34', 'chair_stand_reps' => '15', 'walk_6min_m' => '540', ...$overrides];
}

describe('patients', function () {
    test('record their own at-home measurements, stored encrypted', function () {
        $this->actingAs($this->account)
            ->post(route('my-measurements.store'), atHome(['notes' => 'After a run']))
            ->assertRedirect(route('my-measurements.index'))
            ->assertSessionHasNoErrors();

        $entry = FunctionalAssessment::sole();

        expect($entry)
            ->patient_id->toBe($this->patient->id)
            ->kind->toBe(AssessmentKind::AtHome)
            ->recorded_via->toBe('portal')
            ->recorded_by->toBe($this->account->id)
            ->and($entry->measurements)->toEqual(['height_cm' => 172.4, 'weight_kg' => 70.3, 'waist_cm' => 84.0, 'systolic_bp' => 128, 'diastolic_bp' => 82])
            ->and($entry->bmi())->toBe(23.7)
            ->and(DB::table('functional_assessments')->value('measurements'))->not->toContain('172')
            ->and(DB::table('functional_assessments')->value('notes'))->not->toContain('run');
    });

    test('cannot record the at-center functional tests', function () {
        $this->actingAs($this->account)
            ->post(route('my-measurements.store'), atCenter())
            ->assertSessionHasErrors('kind');

        $this->post(route('patients.assessments.store', $this->patient), atCenter())->assertForbidden();

        expect(FunctionalAssessment::count())->toBe(0);
    });

    test('see their measurements and the clinic\'s functional tests, without who recorded them', function () {
        FunctionalAssessment::factory()->for($this->patient)->create(['measured_on' => '2026-10-10']);
        FunctionalAssessment::factory()->for($this->patient)->atCenter()->create(['measured_on' => '2026-10-12', 'recorded_by' => $this->doctor->id]);

        $this->actingAs($this->account)
            ->get(route('my-measurements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('assessments/Mine')
                ->has('forms', 1)
                ->where('forms.0.kind', 'at_home')
                ->has('history', 2)
                ->where('history.0.kind', 'at_center')
                ->where('history.0.recorded_by', null)
                ->where('latest', fn ($latest) => collect($latest)->pluck('field')->contains('walk_6min_m') && collect($latest)->pluck('field')->contains('bmi')));

        expect(auditEntries(AuditEvent::AssessmentsViewed)->sole()->metadata)->toBe(['context' => 'self', 'results' => 2]);
    });

    test('an unlinked account sees a notice and cannot record', function () {
        $this->actingAs(userWithRole(UserRole::Patient));

        $this->get(route('my-measurements.index'))->assertInertia(fn (Assert $page) => $page->where('linked', false));
        $this->post(route('my-measurements.store'), atHome())->assertNotFound();
    });
});

describe('clinicians', function () {
    test('record both kinds from the clinical record', function (UserRole $role) {
        $clinician = userWithRole($role);
        $this->actingAs($clinician);

        $this->post(route('patients.assessments.store', $this->patient), atHome())->assertSessionHasNoErrors();
        $this->post(route('patients.assessments.store', $this->patient), atCenter())->assertSessionHasNoErrors();

        $entries = FunctionalAssessment::orderBy('id')->get();

        expect($entries->pluck('kind')->all())->toBe([AssessmentKind::AtHome, AssessmentKind::AtCenter])
            ->and($entries->pluck('recorded_via')->unique()->all())->toBe(['clinic'])
            ->and($entries[1]->measurements)->toEqual(['grip_left_kg' => 31.5, 'grip_right_kg' => 34.0, 'chair_stand_reps' => 15, 'walk_6min_m' => 540]);

        $this->get(route('patients.assessments.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page
                ->component('assessments/Patient')
                ->has('forms', 2)
                ->where('history.0.recorded_by', $clinician->name));
    })->with(['doctor' => UserRole::Doctor, 'practitioner' => UserRole::Practitioner]);

    test('everyone else is refused the clinical page and the functional tests', function (UserRole $role) {
        $this->actingAs(userWithRole($role));

        $this->get(route('patients.assessments.show', $this->patient))->assertForbidden();
        $this->post(route('patients.assessments.store', $this->patient), atCenter())->assertForbidden();
        $this->post(route('patients.assessments.store', $this->patient), atHome())->assertForbidden();

        expect(FunctionalAssessment::count())->toBe(0);
    })->with([
        'lab staff' => UserRole::LabStaff,
        'clinic admin' => UserRole::ClinicAdmin,
        'super admin' => UserRole::SuperAdmin,
        'compliance officer' => UserRole::ComplianceOfficer,
    ]);

    test('recording is audited by field name, never by value', function () {
        $this->actingAs($this->doctor)->post(route('patients.assessments.store', $this->patient), atCenter(['notes' => 'Knee pain on the walk']));

        expect(auditEntries(AuditEvent::AssessmentRecorded)->sole()->metadata)->toBe([
            'patient_id' => $this->patient->id,
            'kind' => 'at_center',
            'via' => 'clinic',
            'fields' => ['grip_left_kg', 'grip_right_kg', 'chair_stand_reps', 'walk_6min_m'],
        ])
            ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('540')->not->toContain('Knee');
    });
});

describe('validation', function () {
    test('implausible or incomplete entries are rejected', function (array $overrides, string $field) {
        $this->actingAs($this->account)
            ->post(route('my-measurements.store'), atHome($overrides))
            ->assertSessionHasErrors($field);

        expect(FunctionalAssessment::count())->toBe(0);
    })->with([
        'height in metres' => [['height_cm' => '1.72'], 'height_cm'],
        'weight too high' => [['weight_kg' => '600'], 'weight_kg'],
        'only one blood pressure number' => [['diastolic_bp' => ''], 'systolic_bp'],
        'blood pressure upside down' => [['systolic_bp' => '80', 'diastolic_bp' => '120'], 'systolic_bp'],
        'nothing measured' => [['height_cm' => '', 'weight_kg' => '', 'waist_cm' => '', 'systolic_bp' => '', 'diastolic_bp' => ''], 'measurements'],
        'a future date' => [['measured_on' => '2026-10-17'], 'measured_on'],
        'not a number' => [['waist_cm' => 'eighty'], 'waist_cm'],
    ]);

    test('a partial entry is fine', function () {
        $this->actingAs($this->account)
            ->post(route('my-measurements.store'), ['kind' => 'at_home', 'measured_on' => '2026-10-16', 'weight_kg' => '71'])
            ->assertSessionHasNoErrors();

        expect(FunctionalAssessment::sole()->measurements)->toEqual(['weight_kg' => 71]);
    });
});

describe('records', function () {
    test('entries are never changed or deleted', function () {
        $entry = FunctionalAssessment::factory()->for($this->patient)->create();

        expect(fn () => $entry->update(['notes' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(MedicalRecordRetentionException::class);
    });

    test('at-center tests can only be stored as recorded in the clinic', function () {
        FunctionalAssessment::factory()->for($this->patient)->atCenter()->create(['recorded_via' => 'portal']);
    })->throws(LogicException::class);

    test('the patient record links clinicians to measurements', function () {
        $this->actingAs($this->doctor)
            ->get(route('patients.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page->where('can.viewAssessments', true));
    });
});
