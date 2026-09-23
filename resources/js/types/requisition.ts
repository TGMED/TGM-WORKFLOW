export type RequisitionTone =
    'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

export type RequisitionDocument = { id: number; name: string };

export type RequisitionRow = {
    id: number;
    reference: string;
    requester: string;
    department: string | null;
    title: string;
    purpose: string;
    amount: string;
    bank_name: string;
    account_number: string;
    account_name: string;
    status: string;
    status_label: string;
    status_tone: RequisitionTone;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    paid_at: string | null;
    payment_reference: string | null;
    documents: RequisitionDocument[];
    awaits_retirement: boolean;
    retirement: null | {
        amount_spent: string;
        balance: string;
        notes: string | null;
        status: string;
        status_label: string;
        status_tone: RequisitionTone;
        review_note: string | null;
        reviewed_by: string | null;
        reviewed_at: string | null;
        submitted_at: string | null;
        documents: RequisitionDocument[];
    };
    created_at: string | null;
};
