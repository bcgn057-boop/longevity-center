export type IntakeField = {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'number' | 'select' | 'boolean';
    min?: number;
    max?: number;
    step?: number;
    options?: { value: string; label: string }[];
};

export type IntakeSection = {
    title: string;
    description?: string;
    shape: 'fields' | 'checklist' | 'rows';
    fields?: IntakeField[];
    items?: { key: string; label: string }[];
    columns?: IntakeField[];
    extra?: IntakeField[];
    max_rows?: number;
};

export type IntakeAnswers = Record<string, any>;

export type IntakeForm = {
    id: number;
    status: 'draft' | 'sent_to_patient' | 'completed';
    status_label: string;
    doctor: string | null;
    sent_at: string | null;
    link_expires: string | null;
    submitted_at: string | null;
    created_at: string | null;
    answers: Record<string, IntakeAnswers>;
    clinical: IntakeAnswers;
    derived: { bmi: number | null; waist_to_height: number | null };
};
