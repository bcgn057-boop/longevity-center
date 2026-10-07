<?php

use App\Enums\UserRole;

test('there are exactly six roles', function () {
    expect(array_map(fn (UserRole $role) => $role->value, UserRole::cases()))->toBe([
        'super_admin',
        'clinic_admin',
        'doctor',
        'practitioner',
        'lab_staff',
        'patient',
    ]);
});

test('every role has a label', function () {
    expect(UserRole::SuperAdmin->label())->toBe('Super Admin')
        ->and(UserRole::ClinicAdmin->label())->toBe('Clinic Admin')
        ->and(UserRole::LabStaff->label())->toBe('Lab Staff')
        ->and(UserRole::Patient->label())->toBe('Patient');
});

test('staff roles exclude patients', function () {
    expect(UserRole::staff())
        ->toHaveCount(5)
        ->not->toContain(UserRole::Patient);
});
