/** Mirrors App\Enums\PayrollRunStatus::tone(). */
export type PayrollStatusTone = 'brass' | 'signal';

export type PayrollBand = {
    /** Null on the open-ended top band. */
    up_to: number | null;
    rate: number;
};

export type PayrollSettings = {
    currency: string;
    basic_percent: number;
    housing_percent: number;
    transport_percent: number;
    other_percent: number;
    pension_employee_percent: number;
    pension_employer_percent: number;
    nhf_percent: number;
    rent_relief_percent: number;
    rent_relief_cap: number;
    tax_bands: PayrollBand[];
};

export type PayrollRunRow = {
    id: number;
    year: number;
    month: number;
    period_label: string;
    status: string;
    status_label: string;
    status_tone: PayrollStatusTone;
    is_draft: boolean;
    headcount: number;
    gross_total: number;
    net_total: number;
    created_by: string | null;
    finalised_by: string | null;
    finalised_at: string | null;
};

export type PayrollStaffRow = {
    id: number;
    name: string;
    initials: string;
    employee_id: string | null;
    department: string | null;
    position: string | null;
    /** Null when nobody has set a salary, which keeps them out of every run. */
    annual_gross: number | null;
    monthly_gross: number | null;
    pension_applies: boolean;
    nhf_applies: boolean;
    effective_from: string | null;
    annual_rent: number;
};

/** One line on a payslip, earning or deduction. */
export type PayslipLine = {
    label: string;
    amount: number;
    /** Only on deductions: how the figure was arrived at. */
    basis?: string;
};
