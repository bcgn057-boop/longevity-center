<?php

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\CareReminder;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\AppointmentReminderNotification;
use App\Notifications\CareReminderNotification;
use App\Notifications\ClinicianDailySummaryNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'UTC'));
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create();
});

/**
 * Store an in-app notification directly, as the database channel would.
 */
function deliverInApp($notifiable, object $notification): void
{
    $notification->id = (string) Str::uuid();

    (new DatabaseChannel)->send($notifiable, $notification);
}

function inAppDelivery(): ReminderDelivery
{
    $delivery = new ReminderDelivery;
    $delivery->channel = NotificationChannel::Database;

    return $delivery;
}

test('guests cannot open the inbox', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

test('an appointment reminder is worded when shown', function () {
    $doctor = userWithRole(UserRole::Doctor);
    $doctor->update(['name' => 'Dr Grace Hopper']);
    $appointment = Appointment::factory()->for($this->patient)->for($doctor, 'doctor')->at('2026-11-02 09:30')->create();
    deliverInApp($this->patient, new AppointmentReminderNotification($appointment, inAppDelivery()));

    $this->actingAs($this->account)
        ->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/Index')
            ->where('notifications.data.0.title', 'Appointment reminder')
            ->where('notifications.data.0.body', 'Your appointment on Mon 2 Nov 2026 at 09:30 with Dr Grace Hopper.')
            ->where('notifications.data.0.url', route('my-appointments.index'))
            ->where('notifications.data.0.read', false)
            ->where('unreadNotifications', 1));
});

test('a cancelled appointment says so', function () {
    $appointment = Appointment::factory()->for($this->patient)->at('2026-11-02 09:30')->create();
    deliverInApp($this->patient, new AppointmentReminderNotification($appointment, inAppDelivery()));
    $appointment->cancel(null);

    $this->actingAs($this->account)
        ->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.body', fn (string $body) => str_ends_with($body, 'It has since been cancelled.')));
});

test('details the user may no longer see are not shown', function () {
    $appointment = Appointment::factory()->for($this->patient)->at('2026-11-02 09:30')->create(['reason' => 'Thyroid']);
    deliverInApp($this->patient, new AppointmentReminderNotification($appointment, inAppDelivery()));

    // The account is unlinked from the record after the reminder was sent.
    $this->patient->user()->dissociate()->save();

    $response = $this->actingAs($this->account)->get(route('notifications.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('notifications.data.0.body', 'This appointment is no longer available.')
        ->where('notifications.data.0.url', null));
    expect($response->getContent())->not->toContain('09:30');
});

test('a clinician summary links to their schedule for that day', function () {
    $doctor = userWithRole(UserRole::Doctor);
    $delivery = inAppDelivery();
    deliverInApp($doctor, new ClinicianDailySummaryNotification($delivery, '2026-11-02', 3));

    $this->actingAs($doctor)
        ->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.title', "Tomorrow's appointments")
            ->where('notifications.data.0.body', 'You have 3 appointments on Mon 2 Nov 2026.')
            ->where('notifications.data.0.url', route('appointments.index', ['from' => '2026-11-02', 'to' => '2026-11-02', 'doctor_id' => $doctor->id])));
});

test('a care reminder shows its text only to the patient it is for', function () {
    $reminder = CareReminder::factory()->for($this->patient)->create(['title' => 'Book your blood test', 'message' => 'Fasting from midnight.']);
    deliverInApp($this->patient, new CareReminderNotification($reminder, inAppDelivery()));

    $this->actingAs($this->account)
        ->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.title', 'Book your blood test')
            ->where('notifications.data.0.body', 'Fasting from midnight.'));

    expect(json_encode($this->account->notifications()->toBase()->get()))
        ->not->toContain('blood test')
        ->not->toContain('Fasting');
});

test('notifications can be marked as read, one or all', function () {
    $appointment = Appointment::factory()->for($this->patient)->at('2026-11-02 09:30')->create();
    deliverInApp($this->patient, new AppointmentReminderNotification($appointment, inAppDelivery()));
    deliverInApp($this->patient, new AppointmentReminderNotification($appointment, inAppDelivery()));
    [$first] = $this->account->notifications()->pluck('id');

    $this->actingAs($this->account)->post(route('notifications.read', $first))->assertRedirect();
    expect($this->account->unreadNotifications()->count())->toBe(1);

    $this->post(route('notifications.read-all'))->assertRedirect();
    expect($this->account->unreadNotifications()->count())->toBe(0);
});

test("another user's notification cannot be touched", function () {
    $doctor = userWithRole(UserRole::Doctor);
    deliverInApp($doctor, new ClinicianDailySummaryNotification(inAppDelivery(), '2026-11-02', 1));
    $id = $doctor->notifications()->value('id');

    $this->actingAs($this->account)->post(route('notifications.read', $id))->assertNotFound();
    $this->post(route('notifications.read-all'));

    expect($doctor->unreadNotifications()->count())->toBe(1);
});
