<?php

use App\Enums\AuditEvent;
use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function recordEntry(): AuditLog
{
    return app(AuditLogger::class)->record(AuditEvent::Login, actor: User::factory()->create());
}

test('entries cannot be updated through the model', function () {
    $entry = recordEntry();

    $entry->actor_email = 'someone-else@example.com';

    expect(fn () => $entry->save())->toThrow(AuditLogImmutableException::class);
    expect($entry->fresh()->actor_email)->not->toBe('someone-else@example.com');
});

test('entries cannot be deleted through the model', function () {
    $entry = recordEntry();

    expect(fn () => $entry->delete())->toThrow(AuditLogImmutableException::class);
    expect(AuditLog::count())->toBe(1);
});

test('the database rejects raw updates', function () {
    recordEntry();

    expect(fn () => DB::table('audit_logs')->update(['actor_email' => 'tampered@example.com']))
        ->toThrow(QueryException::class, 'append-only');
});

test('the database rejects raw deletes', function () {
    recordEntry();

    expect(fn () => DB::table('audit_logs')->delete())
        ->toThrow(QueryException::class, 'append-only');
    expect(AuditLog::count())->toBe(1);
});

test('mass updates and deletes through the query builder are rejected', function () {
    recordEntry();

    expect(fn () => AuditLog::query()->update(['actor_email' => 'x']))->toThrow(QueryException::class);
    expect(fn () => AuditLog::query()->delete())->toThrow(QueryException::class);
});

test('entries form a hash chain', function () {
    $first = recordEntry();
    $second = recordEntry();

    expect($first->previous_hash)->toBe(AuditLogger::GENESIS_HASH)
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($first->hash)->toHaveLength(64);
});

test('the chain cannot fork: two entries cannot share a previous hash', function () {
    $first = recordEntry();

    expect(fn () => DB::table('audit_logs')->insert([
        'event' => AuditEvent::Login->value,
        'previous_hash' => $first->previous_hash,
        'hash' => str_repeat('a', 64),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('sensitive fields are redacted at any depth', function () {
    $entry = app(AuditLogger::class)->record(
        AuditEvent::Login,
        newValues: ['password' => 'hunter2', 'name' => 'Ada'],
        metadata: [
            'request' => ['Password' => 'hunter2', 'two_factor_secret' => 'ABC123', 'remember_token' => 'tok'],
            'safe' => 'kept',
        ],
    );

    expect($entry->fresh()->new_values)->toBe(['password' => AuditLogger::REDACTED, 'name' => 'Ada'])
        ->and($entry->fresh()->metadata)->toBe([
            'request' => [
                'Password' => AuditLogger::REDACTED,
                'two_factor_secret' => AuditLogger::REDACTED,
                'remember_token' => AuditLogger::REDACTED,
            ],
            'safe' => 'kept',
        ]);

    expect(json_encode(AuditLog::query()->toBase()->get()))
        ->not->toContain('hunter2')
        ->not->toContain('ABC123');
});

test('long user agents are truncated to fit the column', function () {
    $this->withHeader('User-Agent', str_repeat('x', 1000))
        ->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'secret']);

    expect(strlen(AuditLog::sole()->user_agent))->toBeLessThanOrEqual(255);
});
