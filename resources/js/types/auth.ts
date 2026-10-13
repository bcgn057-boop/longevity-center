export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type UserRole =
    | 'super_admin'
    | 'clinic_admin'
    | 'doctor'
    | 'practitioner'
    | 'lab_staff'
    | 'patient'
    | 'compliance_officer';

export type Auth = {
    user: User;
    role: UserRole | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
