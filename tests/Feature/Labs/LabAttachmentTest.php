<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Labs\LabAttachmentStore;
use App\Labs\LabReportService;
use App\Models\AuditLog;
use App\Models\Biomarker;
use App\Models\LabPartner;
use App\Models\LabReport;
use App\Models\Patient;
use Database\Seeders\BiomarkerSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const SAMPLE_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'UTC'));
    Storage::fake(LabAttachmentStore::DISK);
    $this->seed(BiomarkerSeeder::class);
    $this->apob = Biomarker::where('code', 'apob')->sole();
    $this->patient = Patient::factory()->create();
    $this->partner = LabPartner::factory()->create();
    $this->lab = userWithRole(UserRole::LabStaff, $this->partner);
    $this->requisition = requisitionFor($this->patient, $this->partner);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function reportWithAttachment(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->lab)->post(route('labs.store'), [
        'patient_id' => test()->patient->id,
        'lab_requisition_id' => test()->requisition->id,
        'collected_date' => '2026-10-30',
        'collected_time' => '08:15',
        'results' => [['biomarker_id' => test()->apob->id, 'value' => '98.5', 'unit' => 'mg/dL']],
        'attachment' => UploadedFile::fake()->createWithContent('Ada Lovelace bloods.pdf', SAMPLE_PDF),
        ...$overrides,
    ]);
}

describe('uploading', function () {
    test('the PDF is stored encrypted on the private disk, under a random name', function () {
        reportWithAttachment()->assertRedirect()->assertSessionHasNoErrors();

        $report = LabReport::sole();
        $files = Storage::disk(LabAttachmentStore::DISK)->allFiles();

        expect($report->hasAttachment())->toBeTrue()
            ->and($files)->toBe([$report->attachment_path])
            ->and($report->attachment_path)->toMatch('#^2026/10/[0-9a-f-]{36}\.pdf\.enc$#')
            ->and(Storage::disk(LabAttachmentStore::DISK)->get($report->attachment_path))->not->toContain('%PDF')
            ->and($report->attachment_name)->toBe('Ada Lovelace bloods.pdf')
            ->and(DB::table('lab_reports')->value('attachment_name'))->not->toContain('Lovelace')
            ->and($report->attachment_size)->toBe(strlen(SAMPLE_PDF))
            ->and($report->attachment_sha256)->toBe(hash('sha256', SAMPLE_PDF))
            ->and($report->toArray())->not->toHaveKeys(['attachment_path', 'attachment_name']);
    });

    test('a report can still be saved without a PDF', function () {
        reportWithAttachment(['attachment' => null])->assertSessionHasNoErrors();

        expect(LabReport::sole()->hasAttachment())->toBeFalse()
            ->and(Storage::disk(LabAttachmentStore::DISK)->allFiles())->toBe([]);
    });

    test('only PDFs of up to 10 MB are accepted', function (UploadedFile $file) {
        reportWithAttachment(['attachment' => $file])->assertSessionHasErrors('attachment');

        expect(LabReport::count())->toBe(0)
            ->and(Storage::disk(LabAttachmentStore::DISK)->allFiles())->toBe([]);
    })->with([
        'an image' => fn () => UploadedFile::fake()->create('scan.png', 20, 'image/png'),
        'a text file renamed to .pdf' => fn () => UploadedFile::fake()->createWithContent('report.pdf', 'just text'),
        'over 10 MB' => fn () => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
    ]);

    test('the service removes the stored file when saving fails', function () {
        $service = app(LabReportService::class);
        $file = UploadedFile::fake()->createWithContent('r.pdf', SAMPLE_PDF);

        expect(fn () => $service->create(
            $this->patient, now(), null, null,
            [['biomarker_id' => $this->apob->id, 'value' => '1', 'unit' => 'not-a-unit']],
            $this->lab, $file,
        ))->toThrow(InvalidArgumentException::class);

        expect(LabReport::count())->toBe(0)
            ->and(Storage::disk(LabAttachmentStore::DISK)->allFiles())->toBe([]);
    });

    test('creating the report is audited without the file name', function () {
        reportWithAttachment();

        expect(auditEntries(AuditEvent::LabReportCreated)->sole()->metadata)->toBe(['patient_id' => $this->patient->id, 'attachment' => true])
            ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('Lovelace');
    });
});

describe('downloading', function () {
    test('staff see the attachment on the report and can download the original', function (UserRole $role) {
        reportWithAttachment();
        $report = LabReport::sole();
        $this->actingAs(userWithRole($role, $this->partner));

        $this->get(route('labs.show', $report))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.attachment.name', 'Ada Lovelace bloods.pdf')
                ->where('report.attachment.size', strlen(SAMPLE_PDF))
                ->where('report.attachment.url', route('labs.attachment', $report)));

        $response = $this->get(route('labs.attachment', $report));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Ada Lovelace bloods.pdf"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->getContent())->toBe(SAMPLE_PDF)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    })->with(['lab staff' => UserRole::LabStaff, 'doctor' => UserRole::Doctor, 'practitioner' => UserRole::Practitioner]);

    test('downloads are audited as PHI access, by report', function () {
        reportWithAttachment();
        $report = LabReport::sole();
        $doctor = userWithRole(UserRole::Doctor);

        $this->actingAs($doctor)->get(route('labs.attachment', $report))->assertOk();

        expect(auditEntries(AuditEvent::LabReportAttachmentDownloaded)->sole())
            ->user_id->toBe($doctor->id)
            ->auditable_id->toBe($report->id)
            ->metadata->toBe(['patient_id' => $this->patient->id])
            ->and(AuditEvent::LabReportAttachmentDownloaded->category()->value)->toBe('phi_access');
    });

    test('the staff download refuses everyone else, including the patient', function (UserRole $role) {
        reportWithAttachment();
        $report = LabReport::sole();
        $report->forceFill(['status' => 'released', 'reviewed_at' => now()])->saveQuietly();
        $user = userWithRole($role);

        if ($role === UserRole::Patient) {
            $this->patient->forceFill(['user_id' => $user->id])->saveQuietly();
        }

        $this->actingAs($user)->get(route('labs.attachment', $report))->assertForbidden();
        expect(auditEntries(AuditEvent::LabReportAttachmentDownloaded))->toHaveCount(0);
    })->with([
        'the patient' => UserRole::Patient,
        'super admin' => UserRole::SuperAdmin,
        'clinic admin' => UserRole::ClinicAdmin,
        'compliance officer' => UserRole::ComplianceOfficer,
    ]);

    test('a report without a PDF has nothing to download', function () {
        reportWithAttachment(['attachment' => null]);

        $this->get(route('labs.attachment', LabReport::sole()))->assertNotFound();
    });

    test('a stored file that no longer matches its checksum is not served', function () {
        reportWithAttachment();
        $report = LabReport::sole();
        Storage::disk(LabAttachmentStore::DISK)->put($report->attachment_path, Crypt::encryptString('%PDF-1.4 tampered'));

        $this->withoutExceptionHandling();

        expect(fn () => $this->get(route('labs.attachment', $report)))->toThrow(RuntimeException::class, 'checksum');
        expect(auditEntries(AuditEvent::LabReportAttachmentDownloaded))->toHaveCount(0);
    });
});

describe('patients', function () {
    beforeEach(function () {
        $this->account = userWithRole(UserRole::Patient);
        $this->patient->forceFill(['user_id' => $this->account->id])->saveQuietly();
    });

    test('can download the PDF of their own released report, and it is audited', function () {
        reportWithAttachment();
        $report = LabReport::sole();
        $report->release(userWithRole(UserRole::Doctor));

        $this->actingAs($this->account)
            ->get(route('my-results.show', $report))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.attachment_url', route('my-results.attachment', $report->id))
                ->missing('report.attachment'));

        $response = $this->get(route('my-results.attachment', $report->id));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->getContent())->toBe(SAMPLE_PDF)
            ->and(auditEntries(AuditEvent::LabReportAttachmentDownloaded)->sole())
            ->user_id->toBe($this->account->id)
            ->metadata->toBe(['context' => 'self', 'patient_id' => $this->patient->id]);
    });

    test('reports under review, other patients\' reports and missing ones all look the same', function () {
        reportWithAttachment();
        $pending = LabReport::sole();
        $other = LabReport::factory()->released()->create();
        $other->forceFill(['attachment_path' => 'x.pdf.enc', 'attachment_name' => 'x.pdf', 'attachment_size' => 1, 'attachment_sha256' => str_repeat('0', 64)])->saveQuietly();
        $this->actingAs($this->account);

        $this->get(route('my-results.attachment', $pending->id))->assertNotFound();
        $this->get(route('my-results.attachment', $other->id))->assertNotFound();
        $this->get(route('my-results.attachment', 999999))->assertNotFound();
        expect(auditEntries(AuditEvent::LabReportAttachmentDownloaded))->toHaveCount(0);
    });

    test('a released report without a PDF offers no download', function () {
        reportWithAttachment(['attachment' => null]);
        $report = LabReport::sole();
        $report->release(userWithRole(UserRole::Doctor));

        $this->actingAs($this->account)
            ->get(route('my-results.show', $report))
            ->assertInertia(fn (Assert $page) => $page->where('report.attachment_url', null));
        $this->get(route('my-results.attachment', $report->id))->assertNotFound();
    });

    test('staff cannot use the patient download', function () {
        reportWithAttachment();
        $report = LabReport::sole();
        $report->release(userWithRole(UserRole::Doctor));

        $this->actingAs(userWithRole(UserRole::Doctor))->get(route('my-results.attachment', $report->id))->assertForbidden();
    });
});

test('download names are reduced to safe characters', function (string $uploaded, string $safe) {
    expect(LabAttachmentStore::safeName($uploaded))->toBe($safe);
})->with([
    ['Ada Lovelace bloods.pdf', 'Ada Lovelace bloods.pdf'],
    ["evil\"\r\nX-Header: 1.pdf", 'evil X-Header 1.pdf'],
    ['../../etc/passwd.pdf', 'passwd.pdf'],
    ['.pdf', 'lab-report.pdf'],
]);
