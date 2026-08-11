export type RequestStatusTone = 'signal' | 'brass' | 'alert' | 'neutral';

/** One approver's decision, as shown under a request. */
export type RequestTrail = {
    id: number;
    step: number;
    /** Which time round the chain this decision was taken on. */
    round: number;
    /** Taken on an earlier round, so it no longer gates the request. */
    superseded: boolean;
    approver: string;
    decision: 'approved' | 'rejected';
    decision_label: string;
    stage: 'relief' | 'approval';
    stage_label: string;
    comment: string | null;
    decided_at: string;
};
