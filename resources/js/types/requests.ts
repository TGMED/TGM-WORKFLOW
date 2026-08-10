export type RequestStatusTone = 'signal' | 'brass' | 'alert' | 'neutral';

/** One approver's decision, as shown under a request. */
export type RequestTrail = {
    id: number;
    step: number;
    approver: string;
    decision: 'approved' | 'rejected';
    decision_label: string;
    comment: string | null;
    decided_at: string;
};
