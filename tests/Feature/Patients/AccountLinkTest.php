<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;

test('a clinic admin can link a verified patient account', function () {
    $account = userWithRole(UserRole::Patient);
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->post(route('patients.account.store', $patient), ['email' => $account->email])
        ->assertRedirect(route('patients.show', $patient))
        ->assertSessionHasNoErrors();

    expect($patient->fresh()->user_id)->toBe($account->id);

    $entry = auditEntries(AuditEvent::PatientAccountLinked)->sole();
    expect($entry->new_values)->toBe(['user_id' => $account->id])
        ->and(auditEntries(AuditEvent::PatientUpdated))->toHaveCount(0);
});

test('accounts that cannot be linked are rejected', function (Closure $makeAccount, string $message) {
    $email = $makeAccount();
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->post(route('patients.account.store', $patient), ['email' => $email])
        ->assertSessionHasErrors(['email' => $message]);

    expect($patient->fresh()->user_id)->toBeNull();
})->with([
    'unknown email' => [fn () => 'nobody@example.com', 'No account exists with this email address.'],
    'staff account' => [fn () => userWithRole(UserRole::Doctor)->email, 'Only accounts with the Patient role can be linked.'],
    'unverified account' => [
        fn () => User::factory()->unverified()->withRole(UserRole::Patient)->create()->email,
        'The account must verify its email address before it can be linked.',
    ],
    'already linked account' => [
        fn () => Patient::factory()->withAccount(userWithRole(UserRole::Patient))->create()->user->email,
        'This account is already linked to another patient record.',
    ],
]);

test('a patient that is already linked must be unlinked first', function () {
    $patient = Patient::factory()->withAccount(userWithRole(UserRole::Patient))->create();
    $other = userWithRole(UserRole::Patient);

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->post(route('patients.account.store', $patient), ['email' => $other->email])
        ->assertSessionHasErrors('email');
});

test('an account can be unlinked and the change is audited', function () {
    $account = userWithRole(UserRole::Patient);
    $patient = Patient::factory()->withAccount($account)->create();

    $this->actingAs(userWithRole(UserRole::ClinicAdmin))
        ->delete(route('patients.account.destroy', $patient))
        ->assertRedirect(route('patients.show', $patient));

    expect($patient->fresh()->user_id)->toBeNull()
        ->and(auditEntries(AuditEvent::PatientAccountUnlinked)->sole()->old_values)->toBe(['user_id' => $account->id]);
});

test('clinicians cannot link or unlink accounts', function () {
    $account = userWithRole(UserRole::Patient);
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole(UserRole::Doctor))
        ->post(route('patients.account.store', $patient), ['email' => $account->email])
        ->assertForbidden();

    expect($patient->fresh()->user_id)->toBeNull();
});

test('registering with a patient email does not link the record automatically', function () {
    $patient = Patient::factory()->create(['email' => 'walkin@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Walk In',
        'email' => 'walkin@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($patient->fresh()->user_id)->toBeNull();
});

test('deleting a linked account keeps the patient record', function () {
    $account = userWithRole(UserRole::Patient);
    $patient = Patient::factory()->withAccount($account)->create();

    $account->delete();

    expect($patient->fresh())->not->toBeNull()
        ->and($patient->fresh()->user_id)->toBeNull();
});
