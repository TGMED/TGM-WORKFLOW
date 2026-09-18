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
