import type { PatientOption } from './appointment';

export type BiomarkerOption = {
    id: number;
    code: string;
    name: string;
    units: string[];
};

export type BiomarkerGroup = {
    category: string;
    label: string;
    biomarkers: BiomarkerOption[];
};

export type LabReportStatus = 'pending_review' | 'released';

// A list row: never includes values.
export type LabReportSummary = {
    id: number;
    patient: PatientOption;
    collected: string;
    lab_name: string | null;
    status: LabReportStatus;
    status_label: string;
    entered_by: string | null;
    results_count: number | null;
};

export type LabResultVersion = {
    id: number;
    biomarker: { id: number; name: string; units: string[] };
    value: string;
    unit: string;
    reference_low: string | null;
    reference_high: string | null;
    range: string | null;
    // Against the lab's printed range; not a clinical interpretation.
    flag: 'low' | 'normal' | 'high' | null;
    flag_label: string | null;
    comment: string | null;
    correction_reason: string | null;
    entered_by: string | null;
    entered_at: string | null;
};

export type LabResultItem = LabResultVersion & {
    // Earlier versions this result replaced, newest first.
    history: LabResultVersion[];
};

export type LabReport = LabReportSummary & {
    notes: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    groups: { category: string; label: string; results: LabResultItem[] }[];
};

export type ResultRow = {
    biomarker_id: string;
    value: string;
    unit: string;
    reference_low: string;
    reference_high: string;
    comment: string;
};
