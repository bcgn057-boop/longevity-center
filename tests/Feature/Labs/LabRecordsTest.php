<?php

use App\Enums\AuditEvent;
use App\Enums\LabReportStatus;
use App\Enums\ResultFlag;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Labs\LabValue;
use App\Models\AuditLog;
use App\Models\Biomarker;
use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\Patient;
use Database\Seeders\BiomarkerSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(BiomarkerSeeder::class);
    $this->apob = Biomarker::where('code', 'apob')->sole();
    $this->report = LabReport::factory()->create();
});

function result(array $attributes = []): LabResult
{
    return LabResult::factory()->for(test()->report, 'report')->for(test()->apob)->create($attributes);
}

describe('catalog', function () {
    test('the starter catalog is seeded and can be re-run safely', function () {
        $count = Biomarker::count();
        $this->seed(BiomarkerSeeder::class);

        expect($count)->toBeGreaterThanOrEqual(30)
            ->and(Biomarker::count())->toBe($count)
            ->and($this->apob->units)->toBe(['mg/dL', 'g/L'])
            ->and(Biomarker::where('code', 'hba1c')->sole()->category->label())->toBe('Metabolic');
    });
});

describe('storage', function () {
    test('values, comments and notes are encrypted at rest', function () {
        $report = LabReport::factory()->create(['notes' => 'Patient was not fasting']);
        $result = LabResult::factory()->for($report, 'report')->for($this->apob)->create(['value' => '142', 'comment' => 'Haemolysed sample']);

        $rawResult = DB::table('lab_results')->where('id', $result->id)->first();
        $rawReport = DB::table('lab_reports')->where('id', $report->id)->first();

        expect($rawResult->value)->not->toContain('142')
            ->and(decrypt($rawResult->value, false))->toBe('142')
            ->and($rawResult->comment)->not->toContain('Haemolysed')
            ->and($rawReport->notes)->not->toContain('fasting')
            ->and($result->toArray())->not->toHaveKeys(['value', 'comment']);
    });

    test('no flag is stored in plain text', function () {
        result(['value' => '300'])->fresh();

        expect(array_keys((array) DB::table('lab_results')->first()))->not->toContain('flag');
    });

    test('values are stored as reported, including comparators', function (string $input, string $stored, ?string $comparator) {
        $result = result(['value' => $input]);

        expect($result->fresh()->value)->toBe($stored)
            ->and($result->labValue()->comparator)->toBe($comparator);
    })->with([
        'plain number' => ['142', '142', null],
        'decimal' => ['0.85', '0.85', null],
        'below detection' => ['<0.5', '<0.5', '<'],
        'spaces removed' => ['>= 10', '>=10', '>='],
    ]);

    test('values that are not numbers are rejected', function (string $value) {
        result(['value' => $value]);
    })->with(['high', '12,5', '1.2.3', ''])->throws(InvalidArgumentException::class);

    test('only the units allowed for the biomarker are accepted', function () {
        expect(result(['unit' => 'g/L'])->unit)->toBe('g/L');

        result(['unit' => 'mmol/L']);
    })->throws(InvalidArgumentException::class, 'The unit [mmol/L] is not allowed for this biomarker.');

    test('a reference range cannot be upside down', function () {
        result(['reference_low' => '100', 'reference_high' => '50']);
    })->throws(InvalidArgumentException::class);
});

describe('flags against the lab range', function () {
    test('values are flagged against the printed range', function (?string $low, ?string $high, string $value, ?ResultFlag $flag) {
        expect(LabValue::parse($value)->flag($low, $high))->toBe($flag);
    })->with([
        'below' => ['40', '100', '39.9', ResultFlag::Low],
        'at the low bound' => ['40', '100', '40', ResultFlag::Normal],
        'inside' => ['40', '100', '70', ResultFlag::Normal],
        'at the high bound' => ['40', '100', '100', ResultFlag::Normal],
        'above' => ['40', '100', '100.1', ResultFlag::High],
        'upper limit only' => [null, '5', '6', ResultFlag::High],
        'lower limit only' => ['30', null, '20', ResultFlag::Low],
        'no range' => [null, null, '70', null],
        'with a comparator' => ['1', '5', '<0.5', null],
    ]);

    test('a stored result is flagged when read', function () {
        expect(result(['value' => '130', 'reference_low' => null, 'reference_high' => '90'])->fresh()->flag())->toBe(ResultFlag::High);
    });
});

describe('corrections and retention', function () {
    test('a correction is a new result and both versions are kept', function () {
        $doctor = userWithRole(UserRole::Doctor);
        $original = result(['value' => '142']);

        $correction = $original->correct(['value' => '124', 'unit' => 'mg/dL'], $doctor);

        expect($correction->supersedes_id)->toBe($original->id)
            ->and($correction->entered_by)->toBe($doctor->id)
            ->and($original->fresh()->value)->toBe('142')
            ->and($this->report->currentResults()->pluck('id')->all())->toBe([$correction->id])
            ->and($this->report->results()->count())->toBe(2);
    });

    test('only the latest version can be corrected', function () {
        $original = result();
        $original->correct(['value' => '1', 'unit' => 'mg/dL'], null);

        $original->correct(['value' => '2', 'unit' => 'mg/dL'], null);
    })->throws(LogicException::class);

    test('results cannot be edited', function () {
        result()->update(['value' => '999']);
    })->throws(LogicException::class);

    test('results and reports cannot be deleted', function () {
        $result = result();

        expect(fn () => $result->delete())->toThrow(MedicalRecordRetentionException::class)
            ->and(fn () => $this->report->delete())->toThrow(MedicalRecordRetentionException::class);
    });

    test('a patient with lab reports cannot be removed at the database level', function () {
        expect(fn () => DB::table('patients')->where('id', $this->report->patient_id)->delete())
            ->toThrow(QueryException::class);
    });
});

describe('release', function () {
    test('releasing records the reviewer and time', function () {
        $this->freezeSecond();
        $doctor = userWithRole(UserRole::Doctor);

        $this->report->release($doctor);

        expect($this->report->fresh())
            ->status->toBe(LabReportStatus::Released)
            ->reviewed_by->toBe($doctor->id)
            ->reviewed_at->equalTo(now())->toBeTrue();
    });

    test('a released report cannot be released again or edited', function () {
        $this->report->release(userWithRole(UserRole::Doctor));

        expect(fn () => $this->report->release(userWithRole(UserRole::Doctor)))->toThrow(LogicException::class)
            ->and(fn () => $this->report->fresh()->update(['lab_name' => 'Changed']))->toThrow(LogicException::class);
    });

    test('new results cannot be added to a released report, but corrections can', function () {
        $original = result();
        $this->report->release(userWithRole(UserRole::Doctor));

        expect(fn () => result())->toThrow(LogicException::class);

        $correction = $original->correct(['value' => '50', 'unit' => 'mg/dL'], null);

        expect($correction->exists)->toBeTrue();
    });
});

describe('auditing', function () {
    test('entering, correcting and releasing are audited without values', function () {
        $report = LabReport::factory()->create(['notes' => 'Not fasting']);
        $result = LabResult::factory()->for($report, 'report')->for($this->apob)->create(['value' => '142.7', 'comment' => 'Recheck']);
        $result->correct(['value' => '124.3', 'unit' => 'mg/dL', 'comment' => 'Typo fixed'], null);
        $report->update(['lab_name' => 'Other lab']);
        $report->release(userWithRole(UserRole::Doctor));

        expect(auditEntries(AuditEvent::LabReportCreated)->last()->metadata)->toBe(['patient_id' => $report->patient_id])
            ->and(auditEntries(AuditEvent::LabResultRecorded)->last()->metadata)->toBe(['lab_report_id' => $report->id, 'biomarker_id' => $this->apob->id])
            ->and(auditEntries(AuditEvent::LabResultCorrected)->sole()->metadata['supersedes_id'])->toBe($result->id)
            ->and(auditEntries(AuditEvent::LabReportUpdated)->sole()->metadata)->toBe(['fields' => ['lab_name']])
            ->and(auditEntries(AuditEvent::LabReportReleased)->sole()->auditable_id)->toBe($report->id);

        expect(json_encode(AuditLog::query()->toBase()->get()))
            ->not->toContain('142.7')
            ->not->toContain('124.3')
            ->not->toContain('Recheck')
            ->not->toContain('Typo')
            ->not->toContain('fasting')
            ->not->toContain('mg/dL');

        $this->artisan('audit:verify')->assertSuccessful();
    });
});

describe('access', function () {
    test('who may see and enter results', function (UserRole $role, bool $list, bool $enter, bool $release) {
        $user = userWithRole($role);

        expect($user->can('viewLabReports', LabReport::class))->toBe($list)
            ->and($user->can('viewLabReport', $this->report))->toBe($list)
            ->and($user->can('enterLabResults', LabReport::class))->toBe($enter)
            ->and($user->can('enterLabResults', $this->report))->toBe($enter)
            ->and($user->can('releaseLabReport', $this->report))->toBe($release);
    })->with([
        'lab staff' => [UserRole::LabStaff, true, true, false],
        'doctor' => [UserRole::Doctor, true, true, true],
        'practitioner' => [UserRole::Practitioner, true, true, true],
        'clinic admin' => [UserRole::ClinicAdmin, false, false, false],
        'super admin' => [UserRole::SuperAdmin, false, false, false],
        'patient' => [UserRole::Patient, false, false, false],
    ]);

    test('after release only clinicians may correct, and nobody may add results', function () {
        $this->report->release(userWithRole(UserRole::Doctor));

        expect(userWithRole(UserRole::LabStaff)->can('correctLabResults', $this->report))->toBeFalse()
            ->and(userWithRole(UserRole::Doctor)->can('correctLabResults', $this->report))->toBeTrue()
            ->and(userWithRole(UserRole::Doctor)->can('enterLabResults', $this->report))->toBeFalse()
            ->and(userWithRole(UserRole::Doctor)->can('releaseLabReport', $this->report))->toBeFalse();
    });

    test('before release lab staff may correct their entries', function () {
        expect(userWithRole(UserRole::LabStaff)->can('correctLabResults', $this->report))->toBeTrue();
    });

    test('a patient sees their own reports only once released', function () {
        $account = userWithRole(UserRole::Patient);
        $own = LabReport::factory()->for(Patient::factory()->withAccount($account))->create();
        $other = LabReport::factory()->released()->create();

        expect($account->can('viewLabReport', $own))->toBeFalse();

        $own->release(userWithRole(UserRole::Doctor));

        expect($account->can('viewLabReport', $own->fresh()))->toBeTrue()
            ->and($account->can('viewLabReport', $other))->toBeFalse();
    });
});
