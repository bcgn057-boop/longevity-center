export type AssessmentField = {
    field: string;
    label: string;
    unit: string;
    min: number;
    max: number;
    step: number;
};

export type AssessmentForm = {
    kind: 'at_home' | 'at_center';
    label: string;
    fields: AssessmentField[];
};

export type LatestMeasurement = {
    field: string;
    label: string;
    unit: string;
    value: number;
    date: string;
};

export type AssessmentEntry = {
    id: number;
    kind: 'at_home' | 'at_center';
    kind_label: string;
    measured_on: string;
    values: { label: string; unit: string; value: number }[];
    bmi: number | null;
    notes: string | null;
    via: 'portal' | 'clinic';
    // Only shown to clinicians.
    recorded_by: string | null;
};
