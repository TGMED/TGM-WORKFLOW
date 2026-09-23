<script setup lang="ts">
import DocumentLinks from '@/components/banking/DocumentLinks.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { dateTime, money } from '@/lib/format';
import type { RequisitionRow } from '@/types/requisition';

defineProps<{ row: RequisitionRow }>();
</script>

<template>
    <div class="space-y-5">
        <dl class="grid gap-3 text-[13px] sm:grid-cols-2">
            <div>
                <dt class="text-[12px] text-faint">Amount</dt>
                <dd class="mt-0.5 font-medium">
                    {{ money(Number(row.amount)) }}
                </dd>
            </div>
            <div>
                <dt class="text-[12px] text-faint">Raised by</dt>
                <dd class="mt-0.5">
                    {{ row.requester }}
                    <span v-if="row.department" class="text-muted">
                        · {{ row.department }}
                    </span>
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-[12px] text-faint">Pay to</dt>
                <dd class="mt-0.5">
                    <span class="font-medium">{{ row.account_name }}</span>
                    · {{ row.bank_name }} {{ row.account_number }}
                </dd>
            </div>
        </dl>

        <div class="rounded-xl bg-sunken/50 p-4">
            <p class="text-[13.5px] leading-relaxed whitespace-pre-line">
                {{ row.purpose }}
            </p>
        </div>

        <div class="space-y-1.5">
            <p class="text-[12px] text-faint">Documents</p>
            <DocumentLinks :documents="row.documents" />
        </div>

        <p v-if="row.decided_at" class="text-[12.5px] text-muted">
            {{ row.status === 'declined' ? 'Declined' : 'Approved' }} by
            {{ row.decided_by ?? 'finance' }} on {{ dateTime(row.decided_at) }}
            <template v-if="row.decision_note">
                · "{{ row.decision_note }}"
            </template>
        </p>
        <p v-if="row.paid_at" class="text-[12.5px] text-muted">
            Paid {{ dateTime(row.paid_at) }}
            <template v-if="row.payment_reference">
                · ref {{ row.payment_reference }}
            </template>
        </p>

        <div
            v-if="row.retirement"
            class="space-y-3 border-t border-line-soft pt-4"
        >
            <div class="flex items-center justify-between">
                <p class="text-[13px] font-medium">Retirement</p>
                <StatusPill :tone="row.retirement.status_tone" dot>
                    {{ row.retirement.status_label }}
                </StatusPill>
            </div>
            <dl class="grid gap-3 text-[13px] sm:grid-cols-2">
                <div>
                    <dt class="text-[12px] text-faint">Spent</dt>
                    <dd class="mt-0.5 font-medium">
                        {{ money(Number(row.retirement.amount_spent)) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[12px] text-faint">
                        {{
                            Number(row.retirement.balance) >= 0
                                ? 'To return to the company'
                                : 'Owed to the requester'
                        }}
                    </dt>
                    <dd class="mt-0.5 font-medium">
                        {{ money(Math.abs(Number(row.retirement.balance))) }}
                    </dd>
                </div>
            </dl>
            <p
                v-if="row.retirement.notes"
                class="text-[13px] whitespace-pre-line text-muted"
            >
                {{ row.retirement.notes }}
            </p>
            <DocumentLinks :documents="row.retirement.documents" />
            <p
                v-if="row.retirement.review_note"
                class="rounded-xl bg-sunken/60 px-3 py-2 text-[12.5px] text-muted"
            >
                <span class="font-medium">
                    {{ row.retirement.reviewed_by ?? 'Finance' }}:
                </span>
                {{ row.retirement.review_note }}
            </p>
        </div>
    </div>
</template>
