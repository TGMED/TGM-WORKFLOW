import type { Option } from './profile';

/** The lists behind the approvals page's "raise for a colleague" form. */
export type RaiseOptions = {
    staff: Option[];
    leave_types: Option[];
    approvers: Option[];
    colleagues: Option[];
};

/** One row on the notification settings page. */
export type NotificationTopicSetting = {
    topic: string;
    label: string;
    description: string;
    /** Not the person's to switch off, so its switches are locked on. */
    required: boolean;
    email: boolean;
    push: boolean;
};

/** The public half of the Firebase config, handed to the browser. */
export type PushConfig = {
    apiKey: string;
    authDomain: string;
    projectId: string;
    messagingSenderId: string;
    appId: string;
    vapidKey: string;
};

export type PushState = {
    /** Whether Firebase is set up at all on this deployment. */
    configured: boolean;
    config: PushConfig | null;
    /** How many browsers this person has push switched on in. */
    devices: number;
};

/** Where a notice stands: a draft, dated for later, up, or come down. */
export type AnnouncementState = 'draft' | 'scheduled' | 'live' | 'expired';

/** A live notice, as the dashboard panel shows it. */
export type Announcement = {
    id: number;
    title: string;
    body: string;
    excerpt: string;
    is_pinned: boolean;
    /** Null once the administrator who wrote it has left. */
    author: string | null;
    published_at: string | null;
};

/** The fuller shape the admin page works with. */
export type AnnouncementRow = Announcement & {
    state: AnnouncementState;
    expires_at: string | null;
    /** When the company was written to, and null until they were. */
    notified_at: string | null;
    created_at: string | null;
};

export type WhatsNewFeature = {
    title: string;
    description: string;
};

export type WhatsNewNotes = {
    version: string;
    title: string;
    lede: string;
    features: WhatsNewFeature[];
};
