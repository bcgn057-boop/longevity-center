export type RoleOption = {
    value: string;
    label: string;
    // Whether the signed-in administrator may give accounts this role.
    assignable: boolean;
};

export type LabPartnerOption = {
    id: number;
    name: string;
    tests: { code: string; label: string }[];
};

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string;
    lab_partner_id: number | null;
    lab_partner: string | null;
    verified: boolean;
    deactivated: boolean;
    is_you: boolean;
    can: { update: boolean; deactivate: boolean };
};
