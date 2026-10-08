<?php

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 10:15:00', 'UTC'));
    $this->staff = userWithRole(UserRole::Doctor);
});

test('a started appointment can be marked completed', function () {
    $appointment = Appointment::factory()->at('2026-11-02 10:00')->create();

    $this->actingAs($this->staff)
        ->post(route('appointments.complete', $appointment))
        ->assertRedirect(route('appointments.show', $appointment))
        ->assertSessionHasNoErrors();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
});

test('a future appointment cannot be completed yet', function () {
    $appointment = Appointment::factory()->at('2026-11-02 11:00')->create();

    $this->actingAs($this->staff)
        ->post(route('appointments.complete', $appointment))
        ->assertSessionHasErrors(['status' => 'An appointment cannot be completed before it starts.']);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

test('staff can cancel with a reason', function () {
    $appointment = Appointment::factory()->at('2026-11-03 10:00')->create();

    $this->actingAs($this->staff)
        ->post(route('appointments.cancel', $appointment), ['cancellation_reason' => 'Clinician unwell'])
        ->assertRedirect(route('appointments.show', $appointment));

    $appointment = $appointment->fresh();

    expect($appointment->status)->toBe(AppointmentStatus::Cancelled)
        ->and($appointment->cancelled_by)->toBe($this->staff->id)
        ->and($appointment->cancellation_reason)->toBe('Clinician unwell');
});

test('the super admin can cancel but not give a reason', function () {
    $appointment = Appointment::factory()->at('2026-11-03 10:00')->create();
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $this->post(route('appointments.cancel', $appointment), ['cancellation_reason' => 'Anything'])
        ->assertSessionHasErrors('cancellation_reason');

    $this->post(route('appointments.cancel', $appointment))->assertSessionHasNoErrors();

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

test('final appointments cannot change status again', function (string $state, string $route) {
    $appointment = Appointment::factory()->{$state}()->at('2026-11-02 09:00')->create();

    $this->actingAs($this->staff)
        ->post(route($route, $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->fresh()->status->value)->toBe($state);
})->with([
    'complete a cancelled one' => ['cancelled', 'appointments.complete'],
    'cancel a completed one' => ['completed', 'appointments.cancel'],
    'cancel a cancelled one' => ['cancelled', 'appointments.cancel'],
]);

test('the page offers only the actions that apply', function (string $start, string $state, array $can) {
    $factory = Appointment::factory()->at($start);
    $appointment = ($state === 'scheduled' ? $factory : $factory->{$state}())->create();

    $this->actingAs($this->staff)
        ->get(route('appointments.show', $appointment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.update', $can[0])
            ->where('can.complete', $can[1])
            ->where('can.cancel', $can[2]));
})->with([
    'upcoming' => ['2026-11-03 10:00', 'scheduled', [true, false, true]],
    'in progress' => ['2026-11-02 10:00', 'scheduled', [true, true, true]],
    'completed' => ['2026-11-02 09:00', 'completed', [false, false, false]],
    'cancelled' => ['2026-11-03 10:00', 'cancelled', [false, false, false]],
]);
