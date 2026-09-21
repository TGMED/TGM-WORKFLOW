<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusFilter from '@/components/ui/StatusFilter.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { usePaginated } from '@/composables/usePaginated';
import { useStatusFilter } from '@/composables/useStatusFilter';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, duration } from '@/lib/format';
import type { RequestStatusTone, RequestTrail } from '@/types';

type LatenessRow = {
    id: number;
    work_date: string;
    day_label: string;
    minutes_late: number;
    reason: string;
    status: string;
    status_label: string;
    status_tone: RequestStatusTone;
    approvals_given: number;
    approvals_required: number;
    decided_at: string | null;
    created_at: string | null;
    trail: RequestTrail[];
};

type UnexplainedDay = {
    work_date: string;
    day_label: string;
    late_minutes: number;
};

const props = defineProps<{
    requests: LatenessRow[];
    unexplained: UnexplainedDay[];
    today: string;
    today_label: string;
    explained_today: boolean;
    approvers_required: number;
    window: {
        /** Whether filing is still open today. */
        open: boolean;
        closes_at: string | null;
        closes_at_label: string | null;
        cutoff_minutes: number;
    };
    stats: { pending: number; excused: number };
}>();

// Two separate reasons the form is shut: already filed, or filed too late to
// count as notice. The page says which rather than greying a button out.
const canFile = computed(() => !props.explained_today && props.window.open);

const shutReason = computed(() => {
    if (props.explained_today) {
        return 'You have already raised today.';
    }

    if (!props.window.open) {
        return props.window.closes_at_label
            ? `Filing closed at ${props.window.closes_at_label} today.`
            : 'Filing has closed for today.';
    }

    return null;
});

const modalSubtitle = computed(() =>
    props.window.closes_at_label
        ? `Filed against today, before ${props.window.closes_at_label}. The minutes come from your own clock-in; only the reason is yours to give.`
        : 'Filed against today, with the minutes taken from your own clock-in. Only the reason is yours to give.',
);

const lede = computed(() => {
    const approvals = `Each one needs ${props.approvers_required} approval${props.approvers_required === 1 ? '' : 's'}.`;

    return props.window.closes_at_label
        ? `Tell your approver ahead of the morning, by ${props.window.closes_at_label}. ${approvals}`
        : `Tell your approver you will be late. ${approvals}`;
});

const modalOpen = ref(false);
const expanded = ref<number | null>(null);
const withdrawing = ref<LatenessRow | null>(null);
const busy = ref(false);

// Lateness is explained on the day it happens, so there is no date to pick:
// the form only ever files against today.
const form = useForm({
    work_date: props.today,
    reason: '',
});

function open() {
    form.clearErrors();
    form.defaults({ work_date: props.today, reason: '' });
    form.reset();
    modalOpen.value = true;
}

function submit() {
    form.post('/lateness', {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    });
}

function withdraw() {
    if (!withdrawing.value) {
        return;
    }

    busy.value = true;

    router.delete(`/lateness/${withdrawing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            withdrawing.value = null;
        },
    });
}

const unexplainedPages = usePaginated(() => props.unexplained);
const statuses = useStatusFilter(
    () => props.requests,
    (row) => ({ value: row.status, label: row.status_label }),
);
const pages = usePaginated(() => statuses.rows, {
    resetOn: () => statuses.status,
});
</script>

<template>
    <Head title="Lateness" />

    <AppLayout heading="Lateness" :lede="lede">
        <template #toolbar>
            <div class="flex items-center gap-3">
                <p v-if="shutReason" class="text-[12.5px] text-muted">
                    {{ shutReason }}
                </p>
                <p
                    v-else-if="window.closes_at_label"
                    class="text-[12.5px] text-muted"
                >
                    Closes {{ window.closes_at_label }}
                </p>
                <AppButton size="sm" :disabled="!canFile" @click="open()">
                    Raise for today
                </AppButton>
            </div>
        </template>

        <div class="space-y-6">
            <Panel
                v-if="unexplained.length"
                eyebrow="Unexplained"
                title="You clocked in late today"
                :subtitle="
                    window.open
                        ? 'Filing is still open, so this day can still be raised.'
                        : 'Filing has closed for today, so this day stands as late.'
                "
                flush
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Day</th>
                                <th class="px-5 py-3 font-medium">Late by</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="day in unexplainedPages.paged"
                                :key="day.work_date"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5 font-medium">
                                    {{ day.day_label }}
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ duration(day.late_minutes) }} after start
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <AppButton
                                        variant="secondary"
                                        size="sm"
                                        :disabled="!canFile"
                                        @click="open()"
                                    >
                                        Raise
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="unexplainedPages.page"
                    v-model:per-page="unexplainedPages.perPage"
                    :last-page="unexplainedPages.lastPage"
                    :from="unexplainedPages.from"
                    :to="unexplainedPages.to"
                    :total="unexplainedPages.total"
                />
            </Panel>

            <Panel
                title="Your explanations"
                :subtitle="`${stats.pending} awaiting a decision · ${stats.excused} excused`"
                flush
            >
                <template v-if="statuses.useful" #action>
                    <StatusFilter
                        v-model="statuses.status"
                        :filter="statuses"
                    />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Day</th>
                                <th class="px-5 py-3 font-medium">Late by</th>
                                <th class="px-5 py-3 font-medium">Reason</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="requests.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        title="Nothing filed yet"
                                        message="Say you will be late and it goes to your approver, who can excuse the day."
                                    >
                                        <template #action>
                                            <AppButton
                                                size="sm"
                                                :disabled="!canFile"
                                                @click="open()"
                                            >
                                                Raise for today
                                            </AppButton>
                                        </template>
                                    </EmptyState>
                                </td>
                            </tr>
                            <template v-for="row in pages.paged" :key="row.id">
                                <tr
                                    class="transition-colors hover:bg-sunken/40"
                                >
                                    <td
                                        class="px-5 py-3.5 font-medium whitespace-nowrap"
                                    >
                                        {{ row.day_label }}
                                    </td>
                                    <td
                                        class="px-5 py-3.5 whitespace-nowrap text-muted"
                                    >
                                        {{ duration(row.minutes_late) }}
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p
                                            class="max-w-[22rem] truncate text-[12.5px] text-faint"
                                            :title="row.reason"
                                        >
                                            {{ row.reason }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <StatusPill :tone="row.status_tone" dot>
                                            {{ row.status_label }}
                                        </StatusPill>
                                        <p
                                            v-if="row.status === 'pending'"
                                            class="mt-1 text-[11.5px] text-faint"
                                        >
                                            {{ row.approvals_given }}/{{
                                                row.approvals_required
                                            }}
                                            approved
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <div
                                            class="flex items-center justify-end gap-1"
                                        >
                                            <AppButton
                                                v-if="row.trail.length"
                                                variant="ghost"
                                                size="sm"
                                                @click="
                                                    expanded =
                                                        expanded === row.id
                                                            ? null
                                                            : row.id
                                                "
                                            >
                                                {{
                                                    expanded === row.id
                                                        ? 'Hide'
                                                        : 'Trail'
                                                }}
                                            </AppButton>
                                            <AppButton
                                                v-if="row.status === 'pending'"
                                                variant="ghost"
                                                size="sm"
                                                @click="withdrawing = row"
                                            >
                                                Withdraw
                                            </AppButton>
                                        </div>
                                    </td>
                                </tr>

                                <tr v-if="expanded === row.id">
                                    <td
                                        colspan="5"
                                        class="bg-sunken/40 px-5 py-3"
                                    >
                                        <ul class="space-y-2">
                                            <li
                                                v-for="step in row.trail"
                                                :key="step.id"
                                                class="text-[12.5px]"
                                            >
                                                <span class="font-medium">
                                                    {{ step.approver }}
                                                </span>
                                                <span class="text-muted">
                                                    {{
                                                        step.decision_label.toLowerCase()
                                                    }}
                                                    on
                                                    {{
                                                        dateTime(
                                                            step.decided_at,
                                                        )
                                                    }}
                                                </span>
                                                <p
                                                    v-if="step.comment"
                                                    class="text-faint"
                                                >
                                                    “{{ step.comment }}”
                                                </p>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="pages.page"
                    v-model:per-page="pages.perPage"
                    :last-page="pages.lastPage"
                    :from="pages.from"
                    :to="pages.to"
                    :total="pages.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            title="Raise a late arrival"
            :subtitle="modalSubtitle"
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <p class="text-[13px] font-medium text-muted">Day</p>
                    <div
                        class="flex items-center gap-2.5 rounded-xl border border-line bg-sunken px-3.5 py-2.5"
                    >
                        <svg
                            class="size-4 shrink-0 text-faint"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                        >
                            <path
                                d="M8 3v3m8-3v3M3.5 9.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Z"
                            />
                        </svg>
                        <span class="text-sm">{{ today_label }}</span>
                        <span class="ml-auto text-[12px] text-faint"
                            >Today</span
                        >
                    </div>
                    <p
                        v-if="form.errors.work_date"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.work_date }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label
                        for="lateness-reason"
                        class="flex items-center gap-1 text-[13px] font-medium text-muted"
                    >
                        What happened
                        <span class="text-alert" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="lateness-reason"
                        v-model="form.reason"
                        rows="4"
                        required
                        placeholder="Give your approver enough detail to decide on."
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                    <p v-if="form.errors.reason" class="text-[13px] text-alert">
                        {{ form.errors.reason }}
                    </p>
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Send for approval
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="withdrawing !== null"
            width="md"
            title="Withdraw this explanation?"
            :subtitle="withdrawing?.day_label"
            @close="withdrawing = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                Your approver stops seeing it and the day goes back to being
                unexplained. You can file a fresh one afterwards.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="withdrawing = null">
                    Keep it
                </AppButton>
                <AppButton variant="danger" :loading="busy" @click="withdraw">
                    Withdraw
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
