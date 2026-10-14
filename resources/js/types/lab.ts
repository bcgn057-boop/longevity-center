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
    has_attachment: boolean;
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
    // The lab's original PDF, if one was attached.
    attachment: { name: string; size: number; url: string } | null;
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

// Patient portal: a patient's own released results.
export type TrendPoint = {
    report_id: number;
    date: string;
    date_label: string;
    value: string;
    // Null when reported with a comparator (e.g. "<0.5"): not plotted.
    number: number | null;
    unit: string;
    reference_low: number | null;
    reference_high: number | null;
    flag: 'low' | 'normal' | 'high' | null;
    flag_label: string | null;
    // Corrected after the report was released.
    updated: boolean;
};

export type TrendMarker = {
    id: number;
    name: string;
    latest: TrendPoint;
    // One series per unit: values are never converted between units.
    series: { unit: string; points: TrendPoint[] }[];
};

export type TrendGroup = {
    category: string;
    label: string;
    markers: TrendMarker[];
};

export type PatientReportSummary = {
    id: number;
    collected: string;
    released: string | null;
    lab_name: string | null;
};

export type PatientReport = PatientReportSummary & {
    // The lab's original PDF, if one was attached.
    attachment_url: string | null;
    groups: {
        category: string;
        label: string;
        results: (TrendPoint & { biomarker: string })[];
    }[];
};
