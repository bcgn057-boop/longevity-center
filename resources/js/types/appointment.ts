export type AppointmentStatus = 'scheduled' | 'completed' | 'cancelled';

// Dates and times are already in the clinic's timezone.
export type Appointment = {
    id: number;
    patient: PatientOption;
    doctor: DoctorOption;
    date: string;
    date_label: string;
    start: string;
    end: string;
    duration_minutes: number;
    // A general visit or a longevity therapy.
    type: string;
    type_label: string;
    status: AppointmentStatus;
    status_label: string;
    has_started: boolean;
    // Absent (not null) when the viewer may not read the reasons, and in list rows.
    reason?: string | null;
    cancellation_reason?: string | null;
    cancelled_at: string | null;
    cancelled_by: string | null;
};

export type PatientOption = {
    id: number;
    full_name: string;
    medical_record_number: string | null;
};

export type DoctorOption = {
    id: number;
    name: string;
};

export type AppointmentTypeOption = { value: string; label: string };
