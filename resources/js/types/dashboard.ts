export type DashboardAnnouncement = {
    id: number;
    title: string;
    body: string;
    is_pinned: boolean;
    author: string | null;
    published_at: string | null;
    published_label: string | null;
};

export type Celebration = {
    id: number;
    name: string;
    department: string | null;
    initials: string;
    avatar_url: string | null;
    date: string;
    days_away: number;
    is_today: boolean;
    /** Ready to print: 'Today', 'Tomorrow', or 'Fri 15 Aug'. */
    when: string;
    /** Anniversaries only. */
    years?: number;
};

export type Celebrations = {
    birthdays: Celebration[];
    anniversaries: Celebration[];
};
