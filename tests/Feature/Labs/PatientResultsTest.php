<?php

use App\Enums\AuditEvent;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Biomarker;
use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\LabResultsReadyNotification;
use App\Reminders\NotificationConsent;
use App\Reminders\ReminderDispatcher;
use Database\Seeders\BiomarkerSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'UTC'));
    $this->seed(BiomarkerSeeder::class);
    $this->apob = Biomarker::where('code', 'apob')->sole();
    $this->crp = Biomarker::where('code', 'hs_crp')->sole();
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create(['email' => 'ada@example.com', 'phone' => '+14155550123']);
    $this->doctor = userWithRole(UserRole::Doctor);
});

/**
 * A report for the patient with the given results, released unless told otherwise.
 *
 * @param  array<int, array{0: Biomarker, 1: string, 2?: string, 3?: string|null}>  $results  [biomarker, value, unit, high]
 */
function reportWith(string $collected, array $results, bool $release = true, ?Patient $patient = null): LabReport
{
    $report = LabReport::factory()->for($patient ?? test()->patient)->create([
        'collected_at' => $collected,
        'notes' => 'Staff note: haemolysed',
    ]);

    foreach ($results as $row) {
        LabResult::factory()->for($report, 'report')->for($row[0])->create([
            'value' => $row[1],
            'unit' => $row[2] ?? $row[0]->units[0],
            'reference_high' => $row[3] ?? null,
            'comment' => 'Internal comment',
        ]);
    }

    if ($release) {
        $report->release(test()->doctor);
    }

    return $report;
}

describe('the results page', function () {
    test('shows the latest value and a trend per biomarker from released reports', function () {
        reportWith('2026-06-01 08:00', [[$this->apob, '110', 'mg/dL', '90']]);
        reportWith('2026-10-01 08:00', [[$this->apob, '85', 'mg/dL', '90'], [$this->crp, '0.8']]);
        reportWith('2026-10-20 08:00', [[$this->apob, '200', 'mg/dL', '90']], release: false);

        $this->actingAs($this->account)
            ->get(route('my-results.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('labs/MyResults')
                ->where('groups.0.label', 'Lipids & cardiovascular')
                ->where('groups.0.markers.0.name', 'Apolipoprotein B (ApoB)')
                ->where('groups.0.markers.0.latest.value', '85')
                ->where('groups.0.markers.0.latest.flag', 'normal')
                ->has('groups.0.markers.0.series', 1)
                ->where('groups.0.markers.0.series.0.points.0.value', '110')
                ->where('groups.0.markers.0.series.0.points.0.flag', 'high')
                ->where('groups.0.markers.0.series.0.points.1.number', 85)
                ->has('groups.0.markers.0.series.0.points', 2)
                ->where('groups.1.label', 'Inflammation')
                ->has('reports', 2));
    });

    test('results in different units are separate series, never converted', function () {
        reportWith('2026-06-01 08:00', [[$this->apob, '110', 'mg/dL']]);
        reportWith('2026-10-01 08:00', [[$this->apob, '0.85', 'g/L']]);

        $this->actingAs($this->account)
            ->get(route('my-results.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups.0.markers.0.series', 2)
                ->where('groups.0.markers.0.series.0.unit', 'mg/dL')
                ->where('groups.0.markers.0.series.1.unit', 'g/L')
                ->where('groups.0.markers.0.series.1.points.0.number', 0.85));
    });

    test('values below what the lab can measure are listed but not plotted', function () {
        reportWith('2026-10-01 08:00', [[$this->crp, '<0.2']]);

        $this->actingAs($this->account)
            ->get(route('my-results.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.markers.0.latest.value', '<0.2')
                ->where('groups.0.markers.0.latest.number', null));
    });

    test('a correction made after release is shown as updated, without the old value or reason', function () {
        $report = reportWith('2026-10-01 08:00', [[$this->apob, '111.5', 'mg/dL']]);
        $this->travel(1)->hour();
        $report->results()->sole()->correct(['value' => '88.5', 'unit' => 'mg/dL'], 'Transcription error', $this->doctor);

        $response = $this->actingAs($this->account)->get(route('my-results.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.markers.0.latest.value', '88.5')
            ->where('groups.0.markers.0.latest.updated', true)
            ->has('groups.0.markers.0.series.0.points', 1));

        expect($response->getContent())
            ->not->toContain('111.5')
            ->not->toContain('Transcription')
            ->not->toContain('Internal comment')
            ->not->toContain('haemolysed')
            ->not->toContain($this->doctor->name);
    });

    test('a correction made before release is not marked as updated', function () {
        $report = reportWith('2026-10-01 08:00', [[$this->apob, '111.5', 'mg/dL']], release: false);
        $report->results()->sole()->correct(['value' => '88.5', 'unit' => 'mg/dL'], 'Typo', $this->doctor);
        $report->release($this->doctor);

        $this->actingAs($this->account)
            ->get(route('my-results.index'))
            ->assertInertia(fn (Assert $page) => $page->where('groups.0.markers.0.latest.updated', false));
    });

    test("another patient's results never appear", function () {
        reportWith('2026-10-01 08:00', [[$this->apob, '123.4', 'mg/dL']], patient: Patient::factory()->create());

        $response = $this->actingAs($this->account)->get(route('my-results.index'));

        $response->assertInertia(fn (Assert $page) => $page->has('groups', 0)->has('reports', 0));
        expect($response->getContent())->not->toContain('123.4');
    });

    test('an unlinked account sees a notice', function () {
        $this->actingAs(userWithRole(UserRole::Patient))
            ->get(route('my-results.index'))
            ->assertInertia(fn (Assert $page) => $page->where('linked', false));
    });

    test('staff cannot use the patient results pages', function (UserRole $role) {
        $this->actingAs(userWithRole($role))->get(route('my-results.index'))->assertForbidden();
    })->with(['doctor' => UserRole::Doctor, 'super admin' => UserRole::SuperAdmin, 'lab staff' => UserRole::LabStaff]);
});

describe('a single report', function () {
    test('shows the released report without staff-only details', function () {
        $report = reportWith('2026-10-01 08:00', [[$this->apob, '85', 'mg/dL', '90']]);

        $response = $this->actingAs($this->account)->get(route('my-results.show', $report));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('labs/MyReport')
            ->where('report.groups.0.results.0.biomarker', 'Apolipoprotein B (ApoB)')
            ->where('report.groups.0.results.0.value', '85'));
        expect($response->getContent())
            ->not->toContain('Internal comment')
            ->not->toContain('haemolysed');
    });

    test('unreleased, other patients and missing reports look the same', function () {
        $pending = reportWith('2026-10-01 08:00', [[$this->apob, '85']], release: false);
        $other = reportWith('2026-10-01 08:00', [[$this->apob, '85']], patient: Patient::factory()->create());
        $this->actingAs($this->account);

        $this->get(route('my-results.show', $pending))->assertNotFound();
        $this->get(route('my-results.show', $other))->assertNotFound();
        $this->get(route('my-results.show', 999999))->assertNotFound();
    });

    test('viewing is audited, and the log never holds values', function () {
        $report = reportWith('2026-10-01 08:00', [[$this->apob, '85.25', 'mg/dL']]);
        $this->actingAs($this->account)->get(route('my-results.index'));
        $this->get(route('my-results.show', $report));

        expect(auditEntries(AuditEvent::LabReportListed)->sole()->metadata)->toBe(['context' => 'self', 'results' => 1])
            ->and(auditEntries(AuditEvent::LabReportViewed)->sole())
            ->auditable_id->toBe($report->id)
            ->user_id->toBe($this->account->id)
            ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('85.25');
    });
});

describe('release notification', function () {
    beforeEach(function () {
        config([
            'reminders.channels.whatsapp.enabled' => true,
            'reminders.channels.whatsapp.provider' => 'meta',
            'services.meta_whatsapp.token' => 'test-token',
            'services.meta_whatsapp.phone_number_id' => '123456789',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.LAB']]])]);
    });

    function releaseThroughPage(LabReport $report): void
    {
        test()->actingAs(test()->doctor)->post(route('labs.release', $report))->assertSessionHasNoErrors();
    }

    test('releasing tells the patient on every channel they agreed to, with generic wording', function () {
        $consent = app(NotificationConsent::class);
        $consent->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_SELF, null);
        $consent->grant($this->patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_SELF, null);
        $report = reportWith('2026-10-30 08:00', [[$this->apob, '85.75', 'mg/dL']], release: false);

        releaseThroughPage($report);

        expect(ReminderDelivery::where('kind', 'lab_results_released')->pluck('status', 'channel')->map->value->all())->toBe([
            'database' => 'sent',
            'mail' => 'sent',
            'whatsapp' => 'sent',
        ])
            ->and($this->account->notifications()->sole()->data)->toBe(['lab_report_id' => $report->id]);

        Http::assertSent(fn (Request $request) => $request['template']['name'] === 'lab_results_ready'
            && ! str_contains($request->body(), '85.75')
            && ! str_contains($request->body(), 'ApoB'));
    });

    test('without consent only the in-app notice is sent', function () {
        $report = reportWith('2026-10-30 08:00', [[$this->apob, '85']], release: false);

        releaseThroughPage($report);

        expect(ReminderDelivery::pluck('reason_code', 'channel')->all())->toBe([
            'database' => null,
            'mail' => 'no_consent',
            'whatsapp' => 'no_consent',
        ]);
        Http::assertNothingSent();
    });

    test('patients without a portal account are not notified', function () {
        app(NotificationConsent::class)->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_STAFF, null);
        $this->patient->user()->dissociate()->save();
        $report = reportWith('2026-10-30 08:00', [[$this->apob, '85']], release: false);

        releaseThroughPage($report);

        expect(ReminderDelivery::pluck('reason_code')->unique()->all())->toBe(['no_portal_account']);
    });

    test('a release is only announced once', function () {
        $report = reportWith('2026-10-30 08:00', [[$this->apob, '85']]);
        $dispatcher = app(ReminderDispatcher::class);

        expect($dispatcher->announceLabResults($report))->toHaveCount(3)
            ->and($dispatcher->announceLabResults($report))->toHaveCount(0);
    });

    test('the email says only that new results are available', function () {
        $report = reportWith('2026-10-30 08:00', [[$this->apob, '85.75', 'mg/dL']]);
        $delivery = new ReminderDelivery;
        $delivery->channel = NotificationChannel::Mail;

        $html = (string) (new LabResultsReadyNotification($report, $delivery))->toMail($this->patient)->render();

        expect($html)->toContain('New lab results from')
            ->toContain(route('my-results.index'))
            ->not->toContain('85.75')
            ->not->toContain('ApoB')
            ->not->toContain('30 Oct');
    });

    test('the inbox links to the report', function () {
        $report = reportWith('2026-10-30 08:00', [[$this->apob, '85']], release: false);
        releaseThroughPage($report);

        $this->actingAs($this->account)
            ->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'New lab results')
                ->where('notifications.data.0.body', 'Your results from Fri 30 Oct 2026 are ready to view.')
                ->where('notifications.data.0.url', route('my-results.show', $report)));
    });
});
