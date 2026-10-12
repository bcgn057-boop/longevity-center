export type Option = { value: string; label: string };

export type PrescriptionOptions = {
    types: Option[];
    frequencies: Option[];
};

// Shown as "completed" once an active prescription's end date has passed.
export type PrescriptionStatus = 'active' | 'completed' | 'discontinued';

type ItemDetails = {
    id: number;
    type: string;
    type_label: string;
    name: string;
    dosage: string | null;
    instructions: string | null;
    frequency: string;
    frequency_label: string;
    // Ticked off by the patient each day.
    daily: boolean;
};

export type StaffProtocolItem = ItemDetails & {
    current: boolean;
    supersedes_id: number | null;
    adjustment_reason: string | null;
    created_at: string | null;
    discontinued_at: string | null;
    // Days ticked as taken out of days scheduled, over the last 30 days.
    adherence: { taken: number; scheduled: number };
};

export type StaffPrescription = {
    id: number;
    title: string;
    notes: string | null;
    status: PrescriptionStatus;
    status_label: string;
    starts_on: string;
    ends_on: string | null;
    prescriber: string | null;
    created_at: string | null;
    discontinued_at: string | null;
    discontinued_reason: string | null;
    // Every version, oldest first; adjustments point back via supersedes_id.
    items: StaffProtocolItem[];
};

export type AdherenceDay = {
    date: string;
    // Whether the item was part of the protocol that day.
    scheduled: boolean;
    taken: boolean;
    notes: string | null;
};

export type PatientProtocolItem = ItemDetails & {
    days: AdherenceDay[];
};

export type PatientPrescription = {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string | null;
    items: PatientProtocolItem[];
};

export type ProtocolItemRow = {
    type: string;
    name: string;
    dosage: string;
    instructions: string;
    frequency: string;
};
