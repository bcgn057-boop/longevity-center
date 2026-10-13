export type DashboardCard = {
    key: string;
    label: string;
    value: number | string;
    detail: string;
    // Null when the user cannot open the page behind the figure.
    href: string | null;
};

export type DashboardSection = {
    title: string;
    cards: DashboardCard[];
    charts?: DashboardChart[];
    empty?: string;
};

export type DashboardAction = {
    key: string;
    label: string;
    description: string;
    href: string;
    // A file download (an export), not a page.
    download: boolean;
};

export type DashboardChart = {
    key: string;
    title: string;
    unit: string;
    total: number;
    // One point per day, oldest first, ending today (clinic timezone).
    points: { date: string; value: number }[];
};
