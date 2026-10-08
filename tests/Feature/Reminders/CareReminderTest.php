<?php

use App\Enums\AuditEvent;
use App\Enums\CareReminderStatus;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Exceptions\MedicalRecordRetentionException;
use App\Models\AuditLog;
use App\Models\CareReminder;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\CareReminderNotification;
use App\Reminders\NotificationConsent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'UTC'));
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create(['email' => 'ada@example.com', 'phone' => '+14155550123']);
    $this->staff = userWithRole(UserRole::Doctor);
});

function scheduleCare(array $overrides = [])
{
    return test()->actingAs(test()->staff)->post(route('patients.care-reminders.store', test()->patient), [
        'title' => 'Book your follow-up blood test',
        'message' => 'Fasting from midnight before the test.',
        'date' => '2026-11-05',
        'time' => '09:00',
        ...$overrides,
    ]);
}

describe('scheduling', function () {
    test('staff can schedule a care reminder in the clinic timezone', function () {
        config(['clinic.timezone' => 'Asia/Kolkata']);

        scheduleCare()->assertRedirect(route('patients.show', $this->patient))->assertSessionHasNoErrors();

        $reminder = CareReminder::sole();

        expect($reminder->title)->toBe('Book your follow-up blood test')
            ->and($reminder->due_at->toDateTimeString())->toBe('2026-11-05 03:30:00')
            ->and($reminder->status)->toBe(CareReminderStatus::Scheduled)
            ->and($reminder->created_by)->toBe($this->staff->id);
    });

    test('the text is encrypted at rest and never audited', function () {
        scheduleCare();

        $raw = DB::table('care_reminders')->first();

        expect($raw->title)->not->toContain('blood test')
            ->and($raw->message)->not->toContain('Fasting')
            ->and(auditEntries(AuditEvent::CareReminderCreated)->sole()->metadata)->toBe([
                'patient_id' => $this->patient->id,
                'fields' => ['title', 'message'],
            ])
            ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('blood test');
    });

    test('invalid reminders are rejected', function (array $overrides, string $field) {
        scheduleCare($overrides)->assertSessionHasErrors($field);

        expect(CareReminder::count())->toBe(0);
    })->with([
        'no title' => [['title' => ''], 'title'],
        'title too long' => [['title' => str_repeat('a', 121)], 'title'],
        'in the past' => [['date' => '2026-11-01', 'time' => '09:00'], 'time'],
        'bad date' => [['date' => '05/11/2026'], 'date'],
    ]);

    test('patients without a portal account cannot get care reminders', function () {
        $this->patient->user()->dissociate()->save();

        scheduleCare()->assertSessionHasErrors('title');
    });

    test('the super admin cannot write or see care reminders', function () {
        CareReminder::factory()->for($this->patient)->create(['title' => 'Secret plan']);
        $this->staff = userWithRole(UserRole::SuperAdmin);

        scheduleCare()->assertForbidden();
        expect(CareReminder::count())->toBe(1);

        $response = $this->get(route('patients.show', $this->patient));
        $response->assertInertia(fn (Assert $page) => $page->where('careReminders', null)->where('can.manageCareReminders', false));
        expect($response->getContent())->not->toContain('Secret plan');
    });

    test('clinic staff see care reminders on the patient page', function () {
        CareReminder::factory()->for($this->patient)->create(['title' => 'Log your sleep']);

        $this->actingAs(userWithRole(UserRole::ClinicAdmin))
            ->get(route('patients.show', $this->patient))
            ->assertInertia(fn (Assert $page) => $page->where('careReminders.0.title', 'Log your sleep'));
    });

    test('lab staff and patients cannot schedule', function (UserRole $role) {
        $this->staff = userWithRole($role);

        scheduleCare()->assertForbidden();
    })->with(['lab staff' => UserRole::LabStaff, 'patient' => UserRole::Patient]);

    test('a scheduled reminder can be cancelled, a sent one cannot', function () {
        $scheduled = CareReminder::factory()->for($this->patient)->create();
        $sent = CareReminder::factory()->for($this->patient)->create();
        $sent->markSent();
        $this->actingAs($this->staff);

        $this->post(route('care-reminders.cancel', $scheduled))->assertRedirect(route('patients.show', $this->patient));
        $this->post(route('care-reminders.cancel', $sent))->assertSessionHasErrors('status');

        expect($scheduled->fresh()->status)->toBe(CareReminderStatus::Cancelled)
            ->and($scheduled->fresh()->cancelled_by)->toBe($this->staff->id)
            ->and(auditEntries(AuditEvent::CareReminderCancelled)->sole()->auditable_id)->toBe($scheduled->id);
    });

    test('care reminders cannot be deleted', function () {
        $reminder = CareReminder::factory()->create();

        expect(fn () => $reminder->delete())->toThrow(MedicalRecordRetentionException::class);
    });
});

describe('sending', function () {
    beforeEach(function () {
        config([
            'reminders.channels.whatsapp.enabled' => true,
            'reminders.channels.whatsapp.provider' => 'meta',
            'services.meta_whatsapp.token' => 'test-token',
            'services.meta_whatsapp.phone_number_id' => '123456789',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CARE']]])]);
    });

    test('due reminders are sent once on every consented channel, with generic wording outside the portal', function () {
        $consent = app(NotificationConsent::class);
        $consent->grant($this->patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_STAFF, null);
        $consent->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_STAFF, null);
        $reminder = CareReminder::factory()->for($this->patient)->due('2026-11-01 09:55')->create(['title' => 'Book your blood test']);

        $this->artisan('reminders:dispatch')->expectsOutputToContain('Sent 1 care reminders.')->assertSuccessful();
        $this->artisan('reminders:dispatch')->expectsOutputToContain('Sent 0 care reminders.');

        expect($reminder->fresh()->status)->toBe(CareReminderStatus::Sent)
            ->and(ReminderDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(3)
            ->and($this->account->notifications()->sole()->data)->toBe(['care_reminder_id' => $reminder->id]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['template']['name'] === 'care_reminder'
            && ! str_contains($request->body(), 'blood'));
    });

    test('the email only says there is a new message', function () {
        $reminder = CareReminder::factory()->for($this->patient)->create(['title' => 'Book your blood test', 'message' => 'Fasting.']);
        $delivery = new ReminderDelivery;
        $delivery->channel = NotificationChannel::Mail;

        $html = (string) (new CareReminderNotification($reminder, $delivery))->toMail($this->patient)->render();

        expect($html)->toContain('You have a new message from')
            ->toContain(route('notifications.index'))
            ->not->toContain('blood')
            ->not->toContain('Fasting');
    });

    test('reminders that are not due, or cancelled, are not sent', function () {
        CareReminder::factory()->for($this->patient)->due('2026-11-01 10:30')->create();
        CareReminder::factory()->for($this->patient)->due('2026-11-01 09:00')->create()->cancel(null);

        $this->artisan('reminders:dispatch')->expectsOutputToContain('Sent 0 care reminders.');

        expect(ReminderDelivery::count())->toBe(0);
    });

    test('a patient who lost their portal account is skipped on every channel', function () {
        app(NotificationConsent::class)->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_STAFF, null);
        CareReminder::factory()->for($this->patient)->due('2026-11-01 09:00')->create();
        $this->patient->user()->dissociate()->save();

        $this->artisan('reminders:dispatch');

        expect(ReminderDelivery::pluck('reason_code')->unique()->all())->toBe(['no_portal_account']);
        Http::assertNothingSent();
    });
});
