<?php

use App\Enums\DeliveryStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['clinic.timezone' => 'Asia/Kolkata', 'reminders.clinician_summary_hour' => 18]);
    // 18:30 in the clinic's timezone.
    $this->travelTo(Carbon::parse('2026-11-01 18:30:00', 'Asia/Kolkata'));

    $this->doctor = userWithRole(UserRole::Doctor);
    $this->practitioner = userWithRole(UserRole::Practitioner);
});

function bookFor($clinician, string $localStart, array $attributes = []): Appointment
{
    return Appointment::factory()
        ->for($clinician, 'doctor')
        ->for(Patient::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']))
        ->at(Carbon::parse($localStart, 'Asia/Kolkata'))
        ->create($attributes);
}

test('each clinician with appointments tomorrow gets one summary with the date and a count', function () {
    bookFor($this->doctor, '2026-11-02 09:00');
    bookFor($this->doctor, '2026-11-02 23:30');
    bookFor($this->practitioner, '2026-11-02 10:00');

    $this->artisan('reminders:clinician-summaries')
        ->expectsOutputToContain('Queued 2 clinician summaries for 2026-11-02.')
        ->assertSuccessful();

    $summary = $this->doctor->notifications()->sole();

    expect($summary->type)->toBe('clinician.daily_summary')
        ->and($summary->data)->toBe(['date' => '2026-11-02', 'appointments' => 2])
        ->and($this->practitioner->notifications()->sole()->data['appointments'])->toBe(1)
        ->and(ReminderDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(2);
});

test('only tomorrow in the clinic timezone is counted, without cancelled appointments', function () {
    bookFor($this->doctor, '2026-11-02 09:00');
    bookFor($this->doctor, '2026-11-02 11:00')->cancel(null);
    bookFor($this->doctor, '2026-11-03 00:30');
    bookFor($this->doctor, '2026-11-01 23:00');

    $this->artisan('reminders:clinician-summaries');

    expect($this->doctor->notifications()->sole()->data['appointments'])->toBe(1);
});

test('clinicians with nothing tomorrow get no summary', function () {
    bookFor($this->doctor, '2026-11-02 09:00');

    $this->artisan('reminders:clinician-summaries');

    expect($this->practitioner->notifications()->count())->toBe(0);
});

test('nothing is sent before the summary hour', function () {
    $this->travelTo(Carbon::parse('2026-11-01 17:59:00', 'Asia/Kolkata'));
    bookFor($this->doctor, '2026-11-02 09:00');

    $this->artisan('reminders:clinician-summaries')->assertSuccessful();

    expect(ReminderDelivery::count())->toBe(0);
});

test('hourly runs send one summary per clinician per day', function () {
    bookFor($this->doctor, '2026-11-02 09:00');

    $this->artisan('reminders:clinician-summaries');
    $this->travel(1)->hours();
    $this->artisan('reminders:clinician-summaries');

    expect($this->doctor->notifications()->count())->toBe(1);
});

test('summaries never hold patient names or reasons', function () {
    bookFor($this->doctor, '2026-11-02 09:00', ['reason' => 'Discuss thyroid results']);

    $this->artisan('reminders:clinician-summaries')
        ->doesntExpectOutputToContain('Lovelace')
        ->assertSuccessful();

    expect(json_encode($this->doctor->notifications()->toBase()->get()))
        ->not->toContain('Lovelace')
        ->not->toContain('thyroid');
});

test('nothing is sent when in-app notifications are disabled', function () {
    config(['reminders.channels.database.enabled' => false]);
    bookFor($this->doctor, '2026-11-02 09:00');

    $this->artisan('reminders:clinician-summaries')->assertSuccessful();

    expect(ReminderDelivery::count())->toBe(0);
});
