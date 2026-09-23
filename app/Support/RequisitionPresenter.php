<?php

namespace App\Support;

use App\Models\Requisition;
use App\Services\Attachments;

/**
 * One requisition as both the requester's page and finance's desk show it.
 */
final class RequisitionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Requisition $r): array
    {
        $retirement = $r->retirement;

        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'requester' => $r->requester->name,
            'department' => $r->department?->name,
            'title' => $r->title,
            'purpose' => $r->purpose,
            'amount' => $r->amount,
            'bank_name' => $r->bank_name,
            'account_number' => $r->account_number,
            'account_name' => $r->account_name,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'status_tone' => $r->status->tone(),
            'decided_by' => $r->decidedBy?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'paid_at' => $r->paid_at?->toIso8601String(),
            'payment_reference' => $r->payment_reference,
            'documents' => Attachments::listing($r),
            'awaits_retirement' => $r->awaitsRetirement(),
            'retirement' => $retirement === null ? null : [
                'amount_spent' => $retirement->amount_spent,
                'balance' => $retirement->balance(),
                'notes' => $retirement->notes,
                'status' => $retirement->status->value,
                'status_label' => $retirement->status->label(),
                'status_tone' => $retirement->status->tone(),
                'review_note' => $retirement->review_note,
                'reviewed_by' => $retirement->reviewedBy?->name,
                'reviewed_at' => $retirement->reviewed_at?->toIso8601String(),
                'submitted_at' => $retirement->updated_at?->toIso8601String(),
                'documents' => Attachments::listing($retirement),
            ],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return [
            'requester:id,name',
            'department:id,name',
            'decidedBy:id,name',
            'attachments',
            'retirement.reviewedBy:id,name',
            'retirement.attachments',
            'retirement.requisition',
        ];
    }
}
