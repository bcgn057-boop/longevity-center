<?php

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Simulate an attacker with direct access to the database file, who can
 * bypass the append-only triggers.
 */
function dropAuditTriggers(): void
{
    DB::unprepared('DROP TRIGGER audit_logs_prevent_update');
    DB::unprepared('DROP TRIGGER audit_logs_prevent_delete');
}

function seedAuditChain(int $count = 3): void
{
    $user = User::factory()->create();

    foreach (range(1, $count) as $i) {
        app(AuditLogger::class)->record(AuditEvent::Login, actor: $user, metadata: ['n' => $i]);
    }
}

test('an empty audit log verifies', function () {
    $this->artisan('audit:verify')
        ->expectsOutputToContain('0 entries verified')
        ->assertSuccessful();
});

test('an intact chain verifies', function () {
    seedAuditChain();

    $this->artisan('audit:verify')
        ->expectsOutputToContain('3 entries verified')
        ->assertSuccessful();
});

test('verification detects a modified entry', function () {
    seedAuditChain();
    dropAuditTriggers();

    DB::table('audit_logs')->where('id', 2)->update(['actor_email' => 'innocent@example.com']);

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Entry #2 has been modified')
        ->assertFailed();
});

test('verification detects a modified json payload', function () {
    seedAuditChain();
    dropAuditTriggers();

    DB::table('audit_logs')->where('id', 3)->update(['metadata' => json_encode(['n' => 99])]);

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Entry #3 has been modified')
        ->assertFailed();
});

test('verification detects a deleted entry', function () {
    seedAuditChain();
    dropAuditTriggers();

    DB::table('audit_logs')->where('id', 2)->delete();

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Chain broken at entry #3')
        ->assertFailed();
});

test('verification detects an entry re-sealed with a forged hash', function () {
    seedAuditChain();
    dropAuditTriggers();

    // An attacker without APP_KEY cannot produce a valid HMAC.
    DB::table('audit_logs')->where('id', 1)->update([
        'actor_email' => 'forged@example.com',
        'hash' => hash('sha256', 'forged'),
    ]);

    $this->artisan('audit:verify')->assertFailed();
});

test('verification fails when the signing key changes', function () {
    seedAuditChain();

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Entry #1 has been modified')
        ->assertFailed();
});
