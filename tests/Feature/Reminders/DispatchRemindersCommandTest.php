<?php

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Models\Appointment;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Reminders\NotificationConsent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'UTC'));
    Queue::fake();
});

function appointmentAt(string $start, array $attributes = []): Appointment
{
    $patient = Patient::factory()->withAccount()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    return Appointment::factory()->for($patient)->at($start)->create($attributes);
}

function remindedIds(): array
{
    return ReminderDelivery::distinct()->orderBy('remindable_id')->pluck('remindable_id')->all();
}

test('appointments starting within the next 24 hours are reminded', function () {
    $soon = appointmentAt('2026-11-01 12:00');
    $tomorrow = appointmentAt('2026-11-02 10:00');

    $this->artisan('reminders:dispatch')->assertSuccessful();

    expect(remindedIds())->toBe([$soon->id, $tomorrow->id]);
    Queue::assertPushed(SendQueuedNotifications::class, 2); // in-app for each; no consent for the rest
});

test('appointments outside the window are left alone', function (string $start, array $attributes) {
    appointmentAt($start, $attributes);

    $this->artisan('reminders:dispatch')->assertSuccessful();

    expect(ReminderDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'more than 24 hours away' => ['2026-11-02 10:05', []],
    'less than the minimum notice' => ['2026-11-01 10:45', []],
    'already started' => ['2026-11-01 09:30', []],
]);

test('cancelled and completed appointments are not reminded', function (string $state) {
    $patient = Patient::factory()->withAccount()->create();
    Appointment::factory()->for($patient)->{$state}()->at('2026-11-01 15:00')->create();

    $this->artisan('reminders:dispatch')->assertSuccessful();

    expect(ReminderDelivery::count())->toBe(0);
})->with(['cancelled', 'completed']);

test('running again does not send again', function () {
    appointmentAt('2026-11-01 15:00');

    $this->artisan('reminders:dispatch')->assertSuccessful();
    $this->artisan('reminders:dispatch')
        ->expectsOutputToContain('Checked 0 appointments')
        ->assertSuccessful();

    Queue::assertPushed(SendQueuedNotifications::class, 1);
});

test('a missed run is caught up while the appointment is still upcoming', function () {
    appointmentAt('2026-11-01 15:00');

    // The scheduler was down until two hours before the appointment.
    $this->travelTo(Carbon::parse('2026-11-01 13:00:00', 'UTC'));
    $this->artisan('reminders:dispatch')->assertSuccessful();

    expect(ReminderDelivery::count())->toBe(2); // in-app and email; WhatsApp is off by default
});

test('a rescheduled appointment is reminded again for its new time', function () {
    $appointment = appointmentAt('2026-11-01 15:00');
    $this->artisan('reminders:dispatch');

    $appointment->update(['start_time' => '2026-11-01 17:00', 'end_time' => '2026-11-01 17:30']);
    $this->artisan('reminders:dispatch');

    expect(ReminderDelivery::where('scheduled_for', '2026-11-01 17:00:00')->count())->toBe(2);
});

test('every enabled channel is logged, sent or skipped', function () {
    config(['reminders.channels.whatsapp.enabled' => true]);
    $appointment = appointmentAt('2026-11-01 15:00');
    app(NotificationConsent::class)->grant($appointment->patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_STAFF, null);

    $this->artisan('reminders:dispatch')
        ->expectsOutputToContain('Checked 1 appointments: 2 reminders queued, 1 skipped.')
        ->assertSuccessful();

    expect(ReminderDelivery::pluck('status', 'channel')->map->value->all())->toBe([
        'database' => DeliveryStatus::Queued->value,
        'mail' => DeliveryStatus::Skipped->value,
        'whatsapp' => DeliveryStatus::Queued->value,
    ]);
});

test('the output never names patients', function () {
    appointmentAt('2026-11-01 15:00');

    $this->artisan('reminders:dispatch')
        ->doesntExpectOutputToContain('Ada')
        ->doesntExpectOutputToContain('Lovelace')
        ->assertSuccessful();
});

test('the lead time and minimum notice are configurable', function () {
    config(['reminders.appointment_lead_minutes' => 48 * 60, 'reminders.minimum_notice_minutes' => 0]);
    $inTwoDays = appointmentAt('2026-11-03 09:00');
    $inHalfAnHour = appointmentAt('2026-11-01 10:30');

    $this->artisan('reminders:dispatch');

    expect(remindedIds())->toBe([$inTwoDays->id, $inHalfAnHour->id]);
});

test('both reminder commands are scheduled', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (Event $event) => [str($event->command)->afterLast(' ')->value() => $event->expression]);

    expect($events->get('reminders:dispatch'))->toBe('*/5 * * * *')
        ->and($events->get('reminders:clinician-summaries'))->toBe('0 * * * *');
});
