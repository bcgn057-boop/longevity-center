export type EmergencyContact = {
    name: string;
    relationship: string;
    phone: string;
    email: string | null;
};

export type Patient = {
    id: number;
    medical_record_number: string | null;
    first_name: string;
    last_name: string;
    full_name: string;
    sex: string;
    sex_label: string;
    date_of_birth: string;
    age: number;
    email: string | null;
    phone: string | null;
    address: string | null;
    // Absent (not null) when the viewer may not see medical information.
    medical_history?: string | null;
    allergies?: string | null;
    emergency_contacts: EmergencyContact[];
    account: { id: number; name: string; email: string } | null;
    archived: boolean;
    created_at: string | null;
};

export type PatientListItem = {
    id: number;
    medical_record_number: string | null;
    full_name: string;
    sex_label: string;
    has_account: boolean;
};

export type SelectOption = {
    value: string;
    label: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
