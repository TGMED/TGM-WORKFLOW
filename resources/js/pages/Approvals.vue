<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, duration, relative } from '@/lib/format';
import type { RequestStatusTone, RequestTrail } from '@/types';

type Requester = {
    id: number;
    name: string;
    initials: string;
    department: string | null;
    position: string | null;
    location: string | null;
};

type PendingRequest = {
    id: number;
    module: 'leave' | 'lateness';
    stage: 'relief' | 'approval';
    stage_label: string;
    summary: string;
    requested_at: string | null;
    approvals_given: number;
    approvals_required: number;
    requester: Requester;
    trail: RequestTrail[];
    // Leave only
    type?: string;
    range_label?: string;
    days?: number;
    supervisor?: string | null;
    relief_officer?: string | null;
    // Lateness only
    day_label?: string;
    minutes_late?: number;
    reason: string | null;
};

type HistoryRow = {
    id: number;
    module: string;
    summary: string;
    requester: string;
    decision: 'approved' | 'rejected';
    decision_label: string;
    comment: string | null;
    decided_at: string;
    outcome: string;
    outcome_tone: RequestStatusTone;
};

const props = defineProps<{
    leave: PendingRequest[];
    lateness: PendingRequest[];
    history: HistoryRow[];
}>();

const tab = ref<'leave' | 'lateness'>('leave');

const deciding = ref<PendingRequest | null>(null);
const decision = ref<'approved' | 'rejected'>('approved');

const form = useForm({
    decision: 'approved' as 'approved' | 'rejected',
    comment: '',
});

const rows = computed(() =>
    tab.value === 'leave' ? props.leave : props.lateness,
);

const total = computed(() => props.leave.length + props.lateness.length);

const relief = computed(() => deciding.value?.stage === 'relief');

const modalTitle = computed(() => {
    if (relief.value) {
        return decision.value === 'approved'
            ? 'Agree to cover this'
            : 'Send this back';
    }

    return decision.value === 'approved'
        ? 'Approve request'
        : 'Decline request';
});

const modalAction = computed(() => {
    if (relief.value) {
        return decision.value === 'approved' ? 'Agree cover' : 'Send back';
    }

    return decision.value === 'approved' ? 'Approve' : 'Decline';
});

function start(request: PendingRequest, choice: 'approved' | 'rejected') {
    deciding.value = request;
    decision.value = choice;

    form.clearErrors();
    form.defaults({ decision: choice, comment: '' });
    form.reset();
}

function submit() {
    if (!deciding.value) {
        return;
    }

    form.post(`/approvals/${deciding.value.module}/${deciding.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deciding.value = null;
        },
    });
}
</script>

<template>
    <Head title="Approvals" />

    <AppLayout
        heading="Approvals"
        :lede="
            total === 0
                ? 'Nothing is waiting on you.'
                : `${total} request${total === 1 ? '' : 's'} waiting on you.`
        "
    >
        <div class="space-y-6">
            <div class="flex items-center gap-1 rounded-xl bg-sunken p-1">
                <button
                    v-for="option in [
                        { key: 'leave', label: 'Leave', count: leave.length },
                        {
                            key: 'lateness',
                            label: 'Lateness',
                            count: lateness.length,
                        },
                    ]"
                    :key="option.key"
                    type="button"
                    :class="[
                        'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-[13px] font-medium transition-all duration-200',
                        tab === option.key
                            ? 'bg-panel-raised text-text shadow-panel'
                            : 'text-muted hover:text-text',
                    ]"
                    @click="tab = option.key as 'leave' | 'lateness'"
                >
                    {{ option.label }}
                    <span
                        v-if="option.count"
                        class="rounded-full bg-brand px-1.5 py-0.5 text-[11px] font-semibold text-white"
                    >
                        {{ option.count }}
                    </span>
                </button>
            </div>

            <Panel flush>
                <EmptyState
                    v-if="rows.length === 0"
                    title="Inbox clear"
                    :message="`No ${tab} requests are waiting on your decision.`"
                />

                <ul v-else class="divide-y divide-line-soft">
                    <li v-for="row in rows" :key="row.id" class="px-5 py-4">
                        <div class="flex items-start gap-3">
                            <Avatar
                                :initials="row.requester.initials"
                                :name="row.requester.name"
                                size="sm"
                            />

                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-baseline gap-x-2"
                                >
                                    <p
                                        class="text-[14px] font-semibold tracking-tight"
                                    >
                                        {{ row.requester.name }}
                                    </p>
                                    <span class="text-[12px] text-faint">
                                        {{
                                            [
                                                row.requester.position,
                                                row.requester.location,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </span>
                                </div>

                                <p
                                    v-if="row.stage === 'relief'"
                                    class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand/10 px-2 py-0.5 text-[11.5px] font-medium text-brand"
                                >
                                    You are covering this desk
                                </p>

                                <p class="mt-1 text-[13.5px] text-muted">
                                    <template v-if="row.module === 'leave'">
                                        {{ row.days }} working day{{
                                            row.days === 1 ? '' : 's'
                                        }}
                                        of {{ row.type }},
                                        {{ row.range_label }}
                                    </template>
                                    <template v-else>
                                        {{ duration(row.minutes_late) }} late on
                                        {{ row.day_label }}
                                    </template>
                                </p>

                                <p
                                    v-if="row.reason"
                                    class="mt-2 max-w-prose rounded-xl bg-sunken/60 px-3 py-2 text-[13px] leading-relaxed text-faint"
                                >
                                    {{ row.reason }}
                                </p>

                                <div
                                    class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-faint"
                                >
                                    <span>
                                        Raised {{ relative(row.requested_at) }}
                                    </span>
                                    <span v-if="row.stage === 'relief'">
                                        Then goes to
                                        {{ row.supervisor ?? 'an approver' }}
                                    </span>
                                    <span v-else>
                                        {{ row.approvals_given }}/{{
                                            row.approvals_required
                                        }}
                                        approved
                                    </span>
                                    <span
                                        v-for="step in row.trail"
                                        :key="step.id"
                                    >
                                        {{ step.approver }}
                                        {{ step.decision_label.toLowerCase() }}
                                    </span>
                                </div>
                            </div>

                            <div
                                class="flex shrink-0 flex-col gap-2 sm:flex-row"
                            >
                                <AppButton
                                    variant="secondary"
                                    size="sm"
                                    @click="start(row, 'rejected')"
                                >
                                    {{
                                        row.stage === 'relief'
                                            ? 'Send back'
                                            : 'Decline'
                                    }}
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    @click="start(row, 'approved')"
                                >
                                    {{
                                        row.stage === 'relief'
                                            ? 'Agree cover'
                                            : 'Approve'
                                    }}
                                </AppButton>
                            </div>
                        </div>
                    </li>
                </ul>
            </Panel>

            <Panel
                v-if="history.length"
                title="Your recent decisions"
                subtitle="The last 25 requests you ruled on."
                flush
            >
                <ul class="divide-y divide-line-soft">
                    <li
                        v-for="row in history"
                        :key="row.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px]">
                                <span class="font-medium">
                                    {{ row.requester }}
                                </span>
                                <span class="text-muted">
                                    · {{ row.summary }}
                                </span>
                            </p>
                            <p class="text-[12px] text-faint">
                                You {{ row.decision_label.toLowerCase() }} this
                                on {{ dateTime(row.decided_at) }}
                            </p>
                        </div>

                        <StatusPill :tone="row.outcome_tone">
                            {{ row.outcome }}
                        </StatusPill>
                    </li>
                </ul>
            </Panel>
        </div>

        <ModalShell
            :open="deciding !== null"
            width="md"
            :title="modalTitle"
            :subtitle="deciding?.summary"
            @close="deciding = null"
        >
            <div class="space-y-4">
                <p class="text-[13.5px] leading-relaxed text-muted">
                    <template v-if="deciding?.stage === 'relief'">
                        <template v-if="decision === 'rejected'">
                            Sending it back returns the request to
                            {{ deciding?.requester.name }} to redo. Nothing is
                            booked and no approver sees it.
                        </template>
                        <template v-else>
                            You are agreeing to cover the desk. The request then
                            goes to
                            {{ deciding?.supervisor ?? 'their approver' }} for
                            the decision.
                        </template>
                    </template>
                    <template v-else-if="decision === 'rejected'">
                        Declining ends the request outright, whatever approvals
                        it already has.
                    </template>
                    <template
                        v-else-if="
                            deciding &&
                            deciding.approvals_given + 1 <
                                deciding.approvals_required
                        "
                    >
                        This is one of
                        {{ deciding?.approvals_required }} approvals. Another
                        approver still has to agree before it is granted.
                    </template>
                    <template v-else>
                        This is the last approval needed, so the request is
                        granted straight away.
                    </template>
                </p>

                <div class="space-y-1.5">
                    <label
                        for="decision-comment"
                        class="text-[13px] font-medium text-muted"
                    >
                        Comment
                    </label>
                    <textarea
                        id="decision-comment"
                        v-model="form.comment"
                        rows="3"
                        placeholder="Optional. Shown to the person who raised it."
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                    <p
                        v-if="form.errors.comment"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.comment }}
                    </p>
                </div>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="deciding = null">
                    Cancel
                </AppButton>
                <AppButton
                    :variant="decision === 'approved' ? 'primary' : 'danger'"
                    :loading="form.processing"
                    @click="submit"
                >
                    {{ modalAction }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
