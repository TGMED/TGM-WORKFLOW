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
    stage: 'relief' | 'approval' | 'admin';
    stage_label: string;
    comment: string | null;
    decided_at: string;
};

/** A document on a leave request: the one attached at booking, or one added since. */
export type LeaveDocument = {
    /** Null for the document attached at booking. */
    id: number | null;
    name: string;
    url: string;
    added_by: string | null;
    added_at: string | null;
    at_booking: boolean;
};
