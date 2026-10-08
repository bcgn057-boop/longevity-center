<?php

use App\Enums\AuditEvent;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Reminders\NotificationConsent;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['reminders.channels.whatsapp.enabled' => true, 'clinic.name' => 'Longevity Center']);
    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create([
        'email' => 'ada@example.com',
        'phone' => '+14155550123',
    ]);
});

describe('patient settings', function () {
    test('show each channel with a masked address and the wording', function () {
        $response = $this->actingAs($this->account)->get(route('notification-settings.edit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('linked', true)
            ->has('channels', 2)
            ->where('channels.0.channel', 'mail')
            ->where('channels.0.destination', 'a•••@example.com')
            ->where('channels.0.active', false)
            ->where('channels.0.wording', fn (string $wording) => str_contains($wording, 'Longevity Center'))
            ->where('channels.1.destination', '+•••••••••23'));

        expect($response->getContent())
            ->not->toContain('ada@example.com')
            ->not->toContain('4155550123');
    });

    test('consent needs the box ticked', function () {
        $this->actingAs($this->account)
            ->post(route('notification-settings.grant', 'whatsapp'))
            ->assertSessionHasErrors('agree');

        expect(NotificationPreference::count())->toBe(0);
    });

    test('a patient can turn a channel on and off, and both are audited as theirs', function () {
        $this->actingAs($this->account)
            ->post(route('notification-settings.grant', 'whatsapp'), ['agree' => true])
            ->assertSessionHasNoErrors();

        expect(app(NotificationConsent::class)->check($this->patient, NotificationChannel::WhatsApp))->toBeNull();

        $this->delete(route('notification-settings.withdraw', 'whatsapp'));

        expect(auditEntries(AuditEvent::NotificationConsentGranted)->sole())
            ->user_id->toBe($this->account->id)
            ->metadata->toMatchArray(['source' => 'self'])
            ->and(auditEntries(AuditEvent::NotificationConsentWithdrawn)->sole()->user_id)->toBe($this->account->id);
    });

    test('a patient with no address on record is told to contact the clinic', function () {
        $this->patient->update(['phone' => null]);

        $this->actingAs($this->account)
            ->post(route('notification-settings.grant', 'whatsapp'), ['agree' => true])
            ->assertSessionHasErrors(['agree' => 'Your record has no phone number. Please contact the clinic to add one.']);
    });

    test('disabled and in-app channels cannot be changed', function (string $channel) {
        config(['reminders.channels.whatsapp.enabled' => false]);

        $this->actingAs($this->account)
            ->post(route('notification-settings.grant', $channel), ['agree' => true])
            ->assertNotFound();
    })->with(['whatsapp', 'database', 'sms']);

    test('an unlinked account sees a notice and cannot consent', function () {
        $other = userWithRole(UserRole::Patient);

        $this->actingAs($other)
            ->get(route('notification-settings.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('linked', false)->has('channels', 0));

        $this->post(route('notification-settings.grant', 'mail'), ['agree' => true])->assertNotFound();
    });

    test('staff do not have patient notification settings', function () {
        $this->actingAs(userWithRole(UserRole::Doctor))
            ->get(route('notification-settings.edit'))
            ->assertForbidden();
    });
});

describe('staff recording consent', function () {
    test('staff see consent on the patient page without full addresses', function (UserRole $role) {
        $response = $this->actingAs(userWithRole($role))->get(route('patients.show', $this->patient));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('can.manageConsent', true)
            ->has('consent', 2)
            ->where('consent.0.destination', 'a•••@example.com'));
    })->with([
        'clinic admin' => UserRole::ClinicAdmin,
        'doctor' => UserRole::Doctor,
        'super admin' => UserRole::SuperAdmin,
    ]);

    test('recording consent needs confirmation that the patient agreed', function () {
        $this->actingAs(userWithRole(UserRole::ClinicAdmin))
            ->post(route('patients.consent.store', [$this->patient, 'mail']))
            ->assertSessionHasErrors('confirmed');

        expect(NotificationPreference::count())->toBe(0);
    });

    test('recorded consent is audited as recorded by staff', function () {
        $staff = userWithRole(UserRole::ClinicAdmin);

        $this->actingAs($staff)
            ->post(route('patients.consent.store', [$this->patient, 'mail']), ['confirmed' => true])
            ->assertRedirect(route('patients.show', $this->patient));

        $preference = NotificationPreference::sole();

        expect($preference->source)->toBe('staff')
            ->and($preference->recorded_by)->toBe($staff->id)
            ->and(auditEntries(AuditEvent::NotificationConsentGranted)->sole()->user_id)->toBe($staff->id);

        $this->delete(route('patients.consent.destroy', [$this->patient, 'mail']));

        expect(NotificationPreference::sole()->isActive())->toBeFalse();
    });

    test('other roles cannot record consent', function (UserRole $role) {
        $this->actingAs(userWithRole($role))
            ->post(route('patients.consent.store', [$this->patient, 'mail']), ['confirmed' => true])
            ->assertForbidden();

        expect(NotificationPreference::count())->toBe(0);
    })->with([
        'lab staff' => UserRole::LabStaff,
        'patient' => UserRole::Patient,
    ]);
});
