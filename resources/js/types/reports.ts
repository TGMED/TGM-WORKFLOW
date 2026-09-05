/**
 * Mirrors App\Enums\ReportStatus::tone(). Kept apart from RequestStatusTone:
 * a report is not a request and picks up 'beacon' for a case nobody has
 * opened yet, which the request lifecycle has no equivalent of.
 */
export type ReportStatusTone = 'beacon' | 'brass' | 'signal' | 'neutral';

export type ReportCategoryOption = {
    value: string;
    label: string;
    description: string;
    urgent: boolean;
};
