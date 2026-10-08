// Worded by the server when the inbox is shown; stored notifications hold ids only.
export type AppNotification = {
    id: string;
    type: string;
    title: string;
    body: string | null;
    url: string | null;
    read: boolean;
    created_at: string;
};

export type ConsentChannel = {
    channel: 'mail' | 'whatsapp';
    label: string;
    // Masked, e.g. a•••@example.com or +•••••••••23.
    destination: string | null;
    active: boolean;
    destination_changed: boolean;
    consented_at: string | null;
    source: 'staff' | 'self' | null;
    consent_version: number | null;
    wording: string;
};

export type CareReminderItem = {
    id: number;
    title: string;
    message: string | null;
    due: string;
    status: 'scheduled' | 'sent' | 'cancelled';
    status_label: string;
    created_by: string | null;
};
