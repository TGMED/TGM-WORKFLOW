// Mirrors the payload App\Http\Controllers\OrganogramController sends.
export type OrgPerson = {
    id: number;
    name: string;
    initials: string;
    employee_id: string | null;
    position: string | null;
    department: string | null;
    team: string | null;
    manager_id: number | null;
    /** People reporting straight to them. */
    reports: number;
    /** Everyone below them, however many rungs down. */
    below: number;
    is_you: boolean;
};

/** A team inside a department, with whoever leads it. */
export type OrgTeam = {
    id: number;
    name: string;
    lead_user_id: number | null;
};

/**
 * A department and its teams.
 *
 * Deliberately not part of OrgPerson: the chart is drawn from `manager_id`,
 * while a head and a lead are jobs that a leave request is routed through.
 * Somebody can hold one without the other.
 */
export type OrgUnit = {
    id: number;
    name: string;
    head_user_id: number | null;
    teams: OrgTeam[];
};
