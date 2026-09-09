import type { WhatsNewNotes } from './notifications';

// Roles live in the database, so this covers the ones the app ships with
// while leaving room for any added later.
export type Role =
    | 'super_admin'
    | 'approver'
    | 'staff'
    | 'head_of_department'
    | 'team_lead'
    | (string & {});

export type HeldRole = {
    slug: Role;
    name: string;
};

// Mirrors App\Enums\Permission. The catalogue is code on both sides, since
// each entry guards a route that only exists in code.
export type Permission =
    | 'admin.dashboard'
    | 'staff.manage'
    | 'locations.manage'
    | 'departments.manage'
    | 'attendance.report'
    | 'clock-attempts.view'
    | 'announcements.manage'
    | 'request-settings.manage'
    | 'requests.approve'
    | 'payroll.manage'
    | 'reports.handle'
    | 'data.import'
    | 'roles.manage'
    | 'audit.view';

export type UserLocation = {
    id: number;
    name: string;
    city: string | null;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    initials: string;
    avatar_url: string | null;
    employee_id: string | null;
    department: string | null;
    position: string | null;
    /** Every role this person holds, most senior first. */
    roles: HeldRole[];
    /** The most senior role held, for where there is only room for one. */
    role_label: string | null;
    is_super_admin: boolean;
    /** Everything this person may do, across every role they hold. */
    permissions: Permission[];
    can_approve: boolean;
    can_use_approvals: boolean;
    clocks_in: boolean;
    is_active: boolean;
    profile_complete: boolean;
    location: UserLocation | null;
};

export type Auth = {
    user: AuthUser | null;
};

export type Toast = {
    type: 'success' | 'error' | 'info';
    message: string;
};

export type ClockFlash = {
    ok: boolean;
    result: string;
    label: string;
    message: string;
    distance_meters: number | null;
};

export type SharedProps = {
    name: string;
    auth: Auth;
    pending_approvals: number;
    /** Null once this person has read the current release's notes. */
    whats_new: WhatsNewNotes | null;
    flash: {
        status: string | null;
        toast: Toast | null;
        clock: ClockFlash | null;
    };
};
