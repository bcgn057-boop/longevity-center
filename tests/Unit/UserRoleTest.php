<?php

use App\Enums\UserRole;

test('there are exactly seven roles', function () {
    expect(array_map(fn (UserRole $role) => $role->value, UserRole::cases()))->toBe([
        'super_admin',
        'clinic_admin',
        'doctor',
        'practitioner',
        'lab_staff',
        'patient',
        'compliance_officer',
    ]);
});

test('every role has a label', function () {
    expect(UserRole::SuperAdmin->label())->toBe('Super Admin')
        ->and(UserRole::ClinicAdmin->label())->toBe('Clinic Admin')
        ->and(UserRole::LabStaff->label())->toBe('Lab Staff')
        ->and(UserRole::Patient->label())->toBe('Patient')
        ->and(UserRole::ComplianceOfficer->label())->toBe('Compliance Officer');
});

test('staff roles exclude patients', function () {
    expect(UserRole::staff())
        ->toHaveCount(6)
        ->not->toContain(UserRole::Patient);
});
