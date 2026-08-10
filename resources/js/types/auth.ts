// Roles live in the database, so this covers the three the app ships with
// while leaving room for any added later.
export type Role = 'super_admin' | 'approver' | 'staff' | (string & {});

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
    employee_id: string | null;
    department: string | null;
    position: string | null;
    role: Role;
    role_label: string;
    is_super_admin: boolean;
    can_approve: boolean;
    can_use_approvals: boolean;
    clocks_in: boolean;
    is_active: boolean;
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
    flash: {
        status: string | null;
        toast: Toast | null;
        clock: ClockFlash | null;
    };
};
