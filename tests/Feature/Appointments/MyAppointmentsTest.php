<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'UTC'));
    $this->user = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->user)->create();
});

test('a patient sees their own upcoming and past appointments with reasons', function () {
    $upcoming = Appointment::factory()->for($this->patient)->at('2026-11-05 10:00')->create(['reason' => 'My check-up']);
    $past = Appointment::factory()->for($this->patient)->completed()->at('2026-10-01 10:00')->create();

    $this->actingAs($this->user)
        ->get(route('my-appointments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('appointments/Mine')
            ->where('linked', true)
            ->has('upcoming', 1)
            ->where('upcoming.0.id', $upcoming->id)
            ->where('upcoming.0.reason', 'My check-up')
            ->has('past', 1)
            ->where('past.0.id', $past->id));

    expect(auditEntries(AuditEvent::AppointmentListed)->sole()->metadata)
        ->toBe(['context' => 'self', 'results' => 2]);
});

test('a patient never sees another patient appointments', function () {
    Appointment::factory()->at('2026-11-05 10:00')->create(['reason' => 'Someone else']);

    $response = $this->actingAs($this->user)->get(route('my-appointments.index'));

    $response->assertInertia(fn (Assert $page) => $page->has('upcoming', 0)->has('past', 0));
    expect($response->getContent())->not->toContain('Someone else');
});

test('a patient without a linked record sees a notice', function () {
    $this->actingAs(userWithRole(UserRole::Patient))
        ->get(route('my-appointments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('linked', false));

    expect(auditEntries(AuditEvent::AppointmentListed))->toHaveCount(0);
});

test('a patient can cancel an upcoming appointment', function () {
    $appointment = Appointment::factory()->for($this->patient)->at('2026-11-05 10:00')->create();

    $this->actingAs($this->user)
        ->post(route('my-appointments.cancel', $appointment), ['cancellation_reason' => 'Travelling'])
        ->assertRedirect(route('my-appointments.index'))
        ->assertSessionHasNoErrors();

    $appointment = $appointment->fresh();

    expect($appointment->status)->toBe(AppointmentStatus::Cancelled)
        ->and($appointment->cancelled_by)->toBe($this->user->id)
        ->and($appointment->cancellation_reason)->toBe('Travelling')
        ->and(auditEntries(AuditEvent::AppointmentCancelled)->sole()->user_id)->toBe($this->user->id);
});

test('another patient appointment looks the same as one that does not exist', function () {
    $other = Appointment::factory()->at('2026-11-05 10:00')->create();
    $this->actingAs($this->user);

    $this->post(route('my-appointments.cancel', $other))->assertNotFound();
    $this->post(route('my-appointments.cancel', 999999))->assertNotFound();

    expect($other->fresh()->status)->toBe(AppointmentStatus::Scheduled);
});

test('started or final appointments cannot be cancelled by the patient', function (string $start, ?string $state) {
    $factory = Appointment::factory()->for($this->patient)->at($start);
    $appointment = ($state ? $factory->{$state}() : $factory)->create();

    $this->actingAs($this->user)
        ->post(route('my-appointments.cancel', $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->fresh()->status->value)->toBe($state ?? 'scheduled');
})->with([
    'already started' => ['2026-11-02 07:45', null],
    'completed' => ['2026-10-01 10:00', 'completed'],
    'cancelled' => ['2026-11-05 10:00', 'cancelled'],
]);

test('a patient whose record is archived cannot cancel', function () {
    $appointment = Appointment::factory()->for($this->patient)->at('2026-11-05 10:00')->create();
    $this->patient->delete();

    $this->actingAs($this->user)->post(route('my-appointments.cancel', $appointment))->assertNotFound();
});
