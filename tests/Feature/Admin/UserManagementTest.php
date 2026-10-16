<?php

use App\Accounts\UserAccounts;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\LabPartner;
use App\Models\User;
use Database\Seeders\LabPartnerSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed(LabPartnerSeeder::class);
    $this->admin = userWithRole(UserRole::ClinicAdmin);
    $this->strand = LabPartner::where('code', 'strand')->sole();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accountPayload(array $overrides = []): array
{
    return ['name' => 'Priya Lab', 'email' => 'priya@strand.test', 'role' => 'lab_staff', 'lab_partner_id' => test()->strand->id, ...$overrides];
}

describe('access', function () {
    test('clinic admins and super admins manage accounts', function (UserRole $role) {
        $this->actingAs(userWithRole($role))
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/users/Index')->has('roles', count(UserRole::cases())));
    })->with(['clinic admin' => UserRole::ClinicAdmin, 'super admin' => UserRole::SuperAdmin]);

    test('everyone else is refused', function (UserRole $role) {
        $target = userWithRole(UserRole::Patient);
        $this->actingAs(userWithRole($role));

        $this->get(route('admin.users.index'))->assertForbidden();
        $this->post(route('admin.users.store'), accountPayload())->assertForbidden();
        $this->post(route('admin.users.deactivate', $target))->assertForbidden();
        expect($target->fresh()->isDeactivated())->toBeFalse();
    })->with([
        'doctor' => UserRole::Doctor,
        'lab staff' => UserRole::LabStaff,
        'compliance officer' => UserRole::ComplianceOfficer,
        'patient' => UserRole::Patient,
    ]);
});

describe('creating accounts', function () {
    test('a lab user is created for their partner and invited to set a password', function () {
        $this->actingAs($this->admin)->post(route('admin.users.store'), accountPayload())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'priya@strand.test')->sole();

        expect($user->role->slug)->toBe(UserRole::LabStaff)
            ->and($user->lab_partner_id)->toBe($this->strand->id)
            ->and($user->hasVerifiedEmail())->toBeFalse();
        Notification::assertSentTo($user, ResetPassword::class);
        expect(auditEntries(AuditEvent::UserCreated)->sole())
            ->user_id->toBe($this->admin->id)
            ->new_values->toBe(['email' => 'priya@strand.test', 'role' => 'lab_staff', 'lab_partner_id' => $this->strand->id])
            ->and(auditEntries(AuditEvent::InvitationSent)->sole()->auditable_id)->toBe($user->id);
    });

    test('lab users need a partner, and only lab users may have one', function (array $overrides, string $field) {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin)->post(route('admin.users.store'), accountPayload($overrides))->assertSessionHasErrors($field);

        expect(User::where('email', 'priya@strand.test')->exists())->toBeFalse();
    })->with([
        'lab user without a partner' => [['lab_partner_id' => null], 'lab_partner_id'],
        'unknown partner' => [['lab_partner_id' => 999], 'lab_partner_id'],
        'doctor with a partner' => [['role' => 'doctor'], 'lab_partner_id'],
        'taken email' => [['email' => 'taken@example.com'], 'email'],
        'unknown role' => [['role' => 'admin'], 'role'],
    ]);

    test('an administrator never sets the password', function () {
        $this->actingAs($this->admin)->post(route('admin.users.store'), accountPayload(['role' => 'doctor', 'lab_partner_id' => null, 'password' => 'secret-chosen-by-admin']));

        expect(Hash::check('secret-chosen-by-admin', User::where('email', 'priya@strand.test')->sole()->password))->toBeFalse();
    });

    test('following the invitation sets the password and confirms the email', function () {
        $this->actingAs($this->admin)->post(route('admin.users.store'), accountPayload(['role' => 'doctor', 'lab_partner_id' => null]));
        auth()->logout();
        $user = User::where('email', 'priya@strand.test')->sole();

        $this->post(route('password.update'), [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'a-strong-new-password',
            'password_confirmation' => 'a-strong-new-password',
        ])->assertSessionHasNoErrors();

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('a-strong-new-password', $user->fresh()->password))->toBeTrue();
    });
});

describe('roles', function () {
    test('clinic admins cannot create or manage super admins and compliance officers', function (string $role) {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), accountPayload(['role' => $role, 'lab_partner_id' => null]))
            ->assertSessionHasErrors(['role' => 'You cannot give accounts this role.']);

        $existing = userWithRole(UserRole::from($role));
        $this->get(route('admin.users.edit', $existing))->assertForbidden();
        $this->post(route('admin.users.deactivate', $existing))->assertForbidden();
    })->with(['super_admin', 'compliance_officer']);

    test('super admins can assign any role', function () {
        $this->actingAs(userWithRole(UserRole::SuperAdmin))
            ->post(route('admin.users.store'), accountPayload(['role' => 'compliance_officer', 'lab_partner_id' => null]))
            ->assertSessionHasNoErrors();

        expect(User::where('email', 'priya@strand.test')->sole()->role->slug)->toBe(UserRole::ComplianceOfficer);
    });

    test('changing a role or partner is audited, and leaving the lab clears the partner', function () {
        $user = User::factory()->withRole(UserRole::LabStaff)->create(['lab_partner_id' => $this->strand->id]);
        $other = LabPartner::where('code', 'biocogniz')->sole();
        $this->actingAs($this->admin);

        $this->put(route('admin.users.update', $user), ['name' => $user->name, 'role' => 'lab_staff', 'lab_partner_id' => $other->id])->assertSessionHasNoErrors();
        expect(auditEntries(AuditEvent::LabPartnerChanged)->sole())
            ->old_values->toBe(['lab_partner_id' => $this->strand->id])
            ->new_values->toBe(['lab_partner_id' => $other->id]);

        $this->put(route('admin.users.update', $user), ['name' => $user->name, 'role' => 'practitioner'])->assertSessionHasNoErrors();
        expect($user->fresh())
            ->lab_partner_id->toBeNull()
            ->and($user->fresh()->role->slug)->toBe(UserRole::Practitioner)
            ->and(auditEntries(AuditEvent::RoleChanged)->last()->new_values)->toBe(['role' => 'practitioner']);
    });

    test('nobody can change their own role, not even a super admin', function () {
        $super = userWithRole(UserRole::SuperAdmin);
        userWithRole(UserRole::SuperAdmin);

        $this->actingAs($super)
            ->put(route('admin.users.update', $super), ['name' => $super->name, 'role' => 'doctor'])
            ->assertSessionHasErrors(['role' => 'You cannot change your own role.']);
    });

    test('the email address cannot be changed here', function () {
        $user = userWithRole(UserRole::Doctor);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user), ['name' => 'X', 'role' => 'doctor', 'email' => 'new@example.com'])
            ->assertSessionHasErrors('email');
    });
});

describe('deactivation', function () {
    test('a deactivated user is signed out everywhere and cannot sign back in until reactivated', function () {
        $doctor = User::factory()->withRole(UserRole::Doctor)->create(['password' => 'password']);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $doctor->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->post(route('admin.users.deactivate', $doctor))->assertSessionHasNoErrors();

        expect($doctor->fresh()->isDeactivated())->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $doctor->id)->exists())->toBeFalse()
            ->and(auditEntries(AuditEvent::UserDeactivated)->sole()->auditable_id)->toBe($doctor->id);

        // A session still open in this test client is ended on its next request.
        $this->actingAs($doctor->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->admin)->post(route('admin.users.reactivate', $doctor));
        expect($doctor->fresh()->isDeactivated())->toBeFalse()
            ->and(auditEntries(AuditEvent::UserReactivated))->toHaveCount(1);
    });

    test('nobody can deactivate themselves', function () {
        $super = userWithRole(UserRole::SuperAdmin);

        $this->actingAs($super)->post(route('admin.users.deactivate', $super))->assertSessionHasErrors('account');
        expect($super->fresh()->isDeactivated())->toBeFalse();
    });

    test('the last active super admin cannot be deactivated or demoted, whatever path is used', function () {
        // Through the web only another active Super Admin can act on one, so
        // this guards other callers of the service.
        $only = userWithRole(UserRole::SuperAdmin);
        userWithRole(UserRole::SuperAdmin)->forceFill(['deactivated_at' => now()])->save();
        $accounts = app(UserAccounts::class);

        expect(fn () => $accounts->deactivate($this->admin, $only))
            ->toThrow(ValidationException::class, 'only active Super Admin')
            ->and(fn () => $accounts->update($this->admin, $only, ['name' => 'X', 'role' => UserRole::Doctor, 'lab_partner_id' => null]))
            ->toThrow(ValidationException::class, 'only active Super Admin')
            ->and($only->fresh()->isDeactivated())->toBeFalse()
            ->and($only->fresh()->role->slug)->toBe(UserRole::SuperAdmin);
    });

    test('a deactivated account gets no new invitation', function () {
        $user = User::factory()->withRole(UserRole::Doctor)->create(['deactivated_at' => now(), 'email_verified_at' => null]);

        $this->actingAs($this->admin)->post(route('admin.users.invite', $user))->assertStatus(409);
        Notification::assertNothingSent();
    });
});
