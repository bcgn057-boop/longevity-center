export type ReportPeriod = { from: string; to: string; days: number };

export type AuditLogEntry = {
    id: number;
    created_at: string;
    event: string;
    category: string;
    user_id: number | null;
    actor_email: string | null;
    auditable_type: string | null;
    auditable_id: number | null;
    ip_address: string | null;
    user_agent: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
};

export type AuditLogFilters = Partial<{
    category: string;
    event: string;
    user_id: number;
    patient_id: number;
    from: string;
    to: string;
}>;

export type StaffOption = {
    id: number;
    name: string;
    email: string;
    role: string | null;
};

export type PracticeMetrics = {
    patients: {
        active: number;
        with_portal: number;
        new: number;
        archived: number;
    };
    appointments: {
        total: number;
        by_status: { status: string; label: string; count: number }[];
    };
    prescriptions: {
        in_effect: number;
        patients_on_protocol: number;
        issued: number;
    };
    adherence: {
        taken: number;
        scheduled: number;
        rate: number | null;
        patients: number;
        // null when the group is too small to show.
        groups: { label: string; patients: number | null }[];
    };
    labs: {
        pending_review: number;
        released: number;
        average_review_hours: number | null;
    };
};

export type StaffActivityRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    last_active: string | null;
    counts: Record<string, number>;
};
