/**
 * The numbers behind the company console and behind a head of department's own
 * dashboard. Mirrors App\Services\Metrics.
 */

/** One day of turnout, on time against late. */
export type TurnoutDay = {
    date: string;
    label: string;
    present: number;
    late: number;
    on_time: number;
};

export type ConsoleHeadline = {
    active_staff: number;
    joined_this_month: number;
    left_this_month: number;
    on_probation: number;
    unassigned_site: number;
    unassigned_department: number;
    clocked_in_today: number;
    late_today: number;
    on_leave_today: number;
    pending_requests: number;
    rejected_attempts_today: number;
    /** Null when nobody has worked a day this month yet. */
    punctuality_this_month: number | null;
};

export type AttendanceMetrics = {
    days: Array<TurnoutDay & { punctuality: number | null }>;
    worst_days: Array<TurnoutDay & { punctuality: number | null }>;
    this_month: number | null;
    last_month: number | null;
    /** Clock-in time averaged over the month, as HH:MM. */
    average_arrival: string | null;
};

export type DepartmentMetrics = {
    id: number;
    name: string;
    head: string | null;
    headcount: number;
    teams: number;
    clocked_in_today: number;
    on_leave_today: number;
    turnout: number;
    punctuality: number | null;
    late_minutes: number;
    open_requests: number;
};

export type MovementMetrics = {
    months: Array<{
        month: string;
        label: string;
        joined: number;
        left: number;
    }>;
    reasons: Array<{ value: string; label: string; total: number }>;
};

export type ProbationRow = {
    id: number;
    name: string;
    position: string | null;
    department: string | null;
    hired_at: string;
    due_at: string;
    due_label: string;
    days_until: number;
    overdue: boolean;
};

export type PunctualityRow = {
    id: number;
    name: string;
    department: string | null;
    days_present: number;
    days_late: number;
    late_minutes: number;
    punctuality: number;
};

export type CompanyMetrics = {
    generated_at: string;
    month_label: string;
    headline: ConsoleHeadline;
    attendance: AttendanceMetrics;
    departments: DepartmentMetrics[];
    movement: MovementMetrics;
    probation: {
        months: number;
        overdue: ProbationRow[];
        due_soon: ProbationRow[];
        total_on_probation: number;
    };
    punctuality: { worst: PunctualityRow[]; best: PunctualityRow[] };
    /** Null unless the viewer may run payroll. */
    payroll: {
        last_run: {
            id: number;
            label: string;
            headcount: number;
            gross: number;
            net: number;
            finalised_at: string | null;
        } | null;
        previous?: { label: string; gross: number; net: number } | null;
        open_runs?: number;
    } | null;
    /** Null unless the viewer may read the reports desk. */
    incidents: {
        by_status: Array<{ value: string; label: string; total: number }>;
        open: number;
        oldest_open_days: number | null;
    } | null;
};

/** One person as their head of department or team lead sees them. */
export type GroupMember = {
    id: number;
    name: string;
    position: string | null;
    team: string | null;
    location: string | null;
    days_present: number;
    days_late: number;
    late_minutes: number;
    worked_hours: number;
    leave_days_taken: number;
    open_requests: number;
    last_seen: string | null;
    punctuality: number | null;
    clocked_in_today: boolean;
    late_today: boolean;
};

/** The people somebody is responsible for. */
export type GroupMetrics = {
    label: string;
    kind: 'department' | 'team';
    headcount: number;
    headline: {
        clocked_in_today: number;
        late_today: number;
        on_leave_today: number;
        open_requests: number;
        punctuality: number | null;
        late_minutes_this_month: number;
    };
    trend: TurnoutDay[];
    members: GroupMember[];
};
