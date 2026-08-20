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
