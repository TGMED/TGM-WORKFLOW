<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
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
    stats: { pending: number; excused: number };
}>();

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
</script>

<template>
    <Head title="Lateness" />

    <AppLayout
        heading="Lateness"
        :lede="`Explain a late arrival. Each one needs ${approvers_required} approval${approvers_required === 1 ? '' : 's'}.`"
    >
        <template #toolbar>
            <AppButton size="sm" :disabled="explained_today" @click="open()">
                Explain today
            </AppButton>
        </template>

        <div class="space-y-6">
            <Panel
                v-if="unexplained.length"
                eyebrow="Needs an explanation"
                title="You clocked in late today"
                subtitle="Lateness is explained on the day it happens, so file it before you leave."
                flush
            >
                <ul class="divide-y divide-line-soft">
                    <li
                        v-for="day in unexplained"
                        :key="day.work_date"
                        class="flex items-center justify-between gap-3 px-5 py-3.5"
                    >
                        <div class="min-w-0">
                            <p class="text-[13.5px] font-medium">
                                {{ day.day_label }}
                            </p>
                            <p class="text-[12.5px] text-muted">
                                {{ duration(day.late_minutes) }} after start
                            </p>
                        </div>

                        <AppButton
                            variant="secondary"
                            size="sm"
                            @click="open()"
                        >
                            Explain
                        </AppButton>
                    </li>
                </ul>
            </Panel>

            <Panel
                title="Your explanations"
                :subtitle="`${stats.pending} awaiting a decision · ${stats.excused} excused`"
                flush
            >
                <EmptyState
                    v-if="requests.length === 0"
                    title="Nothing filed yet"
                    message="Explain a late arrival and it goes to your approver, who can excuse it."
                >
                    <template #action>
                        <AppButton
                            size="sm"
                            :disabled="explained_today"
                            @click="open()"
                        >
                            Explain today
                        </AppButton>
                    </template>
                </EmptyState>

                <ul v-else class="divide-y divide-line-soft">
                    <li v-for="row in requests" :key="row.id" class="px-5 py-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p
                                    class="text-[14px] font-semibold tracking-tight"
                                >
                                    {{ row.day_label }}
                                </p>
                                <p class="mt-0.5 text-[13px] text-muted">
                                    {{ duration(row.minutes_late) }} late
                                </p>
                                <p
                                    class="mt-1.5 max-w-prose text-[13px] leading-relaxed text-faint"
                                >
                                    {{ row.reason }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <StatusPill :tone="row.status_tone" dot>
                                    {{ row.status_label }}
                                </StatusPill>
                                <span
                                    v-if="row.status === 'pending'"
                                    class="text-[12px] text-faint"
                                >
                                    {{ row.approvals_given }}/{{
                                        row.approvals_required
                                    }}
                                    approved
                                </span>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center gap-3">
                            <button
                                v-if="row.trail.length"
                                type="button"
                                class="text-[12.5px] font-medium text-muted transition-colors hover:text-text"
                                @click="
                                    expanded =
                                        expanded === row.id ? null : row.id
                                "
                            >
                                {{ expanded === row.id ? 'Hide' : 'Show' }}
                                decisions ({{ row.trail.length }})
                            </button>

                            <AppButton
                                v-if="row.status === 'pending'"
                                variant="ghost"
                                size="sm"
                                @click="withdrawing = row"
                            >
                                Withdraw
                            </AppButton>
                        </div>

                        <ul
                            v-if="expanded === row.id"
                            class="mt-3 space-y-2 rounded-xl bg-sunken/50 p-3"
                        >
                            <li
                                v-for="step in row.trail"
                                :key="step.id"
                                class="text-[12.5px]"
                            >
                                <span class="font-medium">
                                    {{ step.approver }}
                                </span>
                                <span class="text-muted">
                                    {{ step.decision_label.toLowerCase() }} on
                                    {{ dateTime(step.decided_at) }}
                                </span>
                                <p v-if="step.comment" class="text-faint">
                                    “{{ step.comment }}”
                                </p>
                            </li>
                        </ul>
                    </li>
                </ul>
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            title="Explain a late arrival"
            subtitle="Filed against today, with the minutes taken from your own clock-in. Only the reason is yours to give."
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
