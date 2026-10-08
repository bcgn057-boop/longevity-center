<?php

use App\Enums\AuditEvent;
use App\Enums\NotificationChannel;
use App\Enums\ReminderSkipReason;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Reminders\NotificationConsent;

beforeEach(function () {
    $this->consent = app(NotificationConsent::class);
    $this->patient = Patient::factory()->create(['email' => 'ada@example.com', 'phone' => '+14155550123']);
    $this->staff = userWithRole(UserRole::ClinicAdmin);
});

test('granting consent stores a fingerprint of the address, never the address', function (NotificationChannel $channel, string $address) {
    $preference = $this->consent->grant($this->patient, $channel, NotificationPreference::SOURCE_STAFF, $this->staff);

    expect($preference->isActive())->toBeTrue()
        ->and($preference->consent_version)->toBe(1)
        ->and($preference->recorded_by)->toBe($this->staff->id)
        ->and($preference->destination_hash)->toHaveLength(64)
        ->and(json_encode(NotificationPreference::query()->toBase()->get()))->not->toContain($address)
        ->and($this->consent->check($this->patient, $channel))->toBeNull();
})->with([
    'email' => [NotificationChannel::Mail, 'ada@example.com'],
    'whatsapp' => [NotificationChannel::WhatsApp, '4155550123'],
]);

test('consent changes are audited without the address', function () {
    $this->consent->grant($this->patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_STAFF, $this->staff);
    $this->consent->withdraw($this->patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_SELF, null);

    $granted = auditEntries(AuditEvent::NotificationConsentGranted)->sole();
    $withdrawn = auditEntries(AuditEvent::NotificationConsentWithdrawn)->sole();

    expect($granted->user_id)->toBe($this->staff->id)
        ->and($granted->auditable_id)->toBe($this->patient->id)
        ->and($granted->metadata)->toBe(['channel' => 'whatsapp', 'consent_version' => 1, 'source' => 'staff'])
        ->and($withdrawn->metadata)->toBe(['channel' => 'whatsapp', 'source' => 'self'])
        ->and(json_encode(AuditLog::query()->toBase()->get()))->not->toContain('4155550123');
});

test('without consent nothing may be sent', function () {
    expect($this->consent->check($this->patient, NotificationChannel::WhatsApp))->toBe(ReminderSkipReason::NoConsent)
        ->and($this->consent->check($this->patient, NotificationChannel::Mail))->toBe(ReminderSkipReason::NoConsent);
});

test('withdrawn consent stops reminders until granted again', function () {
    $this->consent->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_STAFF, $this->staff);
    $this->consent->withdraw($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_SELF, null);

    expect($this->consent->check($this->patient, NotificationChannel::Mail))->toBe(ReminderSkipReason::NoConsent);

    $this->consent->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_SELF, null);

    expect($this->consent->check($this->patient, NotificationChannel::Mail))->toBeNull()
        ->and(NotificationPreference::count())->toBe(1);
});

test('withdrawing when no consent was given does nothing', function () {
    $this->consent->withdraw($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_SELF, null);

    expect(NotificationPreference::count())->toBe(0)
        ->and(auditEntries(AuditEvent::NotificationConsentWithdrawn))->toHaveCount(0);
});

test('consent lapses when the address changes', function (NotificationChannel $channel, string $field, string $new) {
    $this->consent->grant($this->patient, $channel, NotificationPreference::SOURCE_STAFF, $this->staff);

    $this->patient->update([$field => $new]);

    expect($this->consent->check($this->patient->fresh(), $channel))->toBe(ReminderSkipReason::DestinationChanged);
})->with([
    'new phone' => [NotificationChannel::WhatsApp, 'phone', '+14155550999'],
    'new email' => [NotificationChannel::Mail, 'email', 'new@example.com'],
]);

test('formatting differences do not count as a new address', function () {
    $this->consent->grant($this->patient, NotificationChannel::Mail, NotificationPreference::SOURCE_STAFF, $this->staff);

    $this->patient->update(['email' => '  ADA@Example.com ']);

    expect($this->consent->check($this->patient->fresh(), NotificationChannel::Mail))->toBeNull();
});

test('a missing address is reported', function () {
    $patient = Patient::factory()->create(['phone' => null]);

    expect($this->consent->check($patient, NotificationChannel::WhatsApp))->toBe(ReminderSkipReason::NoDestination)
        ->and(fn () => $this->consent->grant($patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_STAFF, $this->staff))
        ->toThrow(InvalidArgumentException::class);
});

test('in-app notifications need no consent', function () {
    expect(fn () => $this->consent->grant($this->patient, NotificationChannel::Database, NotificationPreference::SOURCE_STAFF, $this->staff))
        ->toThrow(InvalidArgumentException::class);
});
