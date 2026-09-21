<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { dayKey, holidayNote, holidaysBetween } from '@/lib/holidays';
import type { Holidays } from '@/lib/holidays';
import type { RequestStatusTone, RequestTrail } from '@/types';

type Kind = {
    value: string;
    label: string;
    description: string;
    needs_destination: boolean;
};

type Row = {
    id: number;
    kind: string;
    kind_label: string;
    kind_tone: RequestStatusTone;
    start_date: string;
    end_date: string;
    range_label: string;
    days: number;
    reason: string;
    destination: string | null;
    contact_number: string | null;
    status: string;
    status_label: string;
    status_tone: RequestStatusTone;
    stage_label: string;
    approvals_given: number;
    approvals_required: number;
    decided_at: string | null;
    created_at: string | null;
    trail: RequestTrail[];
};

const props = defineProps<{
    requests: Row[];
    kinds: Kind[];
    workdays: number[];
    holidays: Holidays;
    approvers_required: number;
    stats: { pending: number; days_this_year: number };
}>();

const modalOpen = ref(false);
const expanded = ref<number | null>(null);
const withdrawing = ref<Row | null>(null);
const busy = ref(false);

const form = useForm({
    kind: props.kinds[0]?.value ?? 'remote',
    start_date: '',
    end_date: '',
    reason: '',
    destination: '',
    contact_number: '',
});

const chosenKind = computed(() =>
    props.kinds.find((kind) => kind.value === form.kind),
);

// Only an assignment asks where: somebody working from home is reachable on
// the numbers already on their record.
const needsDestination = computed(
    () => chosenKind.value?.needs_destination ?? false,
);

// The same count the server will make, so the form can say what it is asking
// for before it asks.
const workingDays = computed(() => {
    if (!form.start_date || !form.end_date) {
        return 0;
    }

    // Read as local days: a bare date string is parsed as UTC midnight, which
    // lands on the day before anywhere west of Greenwich.
    const start = new Date(`${form.start_date}T00:00:00`);
    const end = new Date(`${form.end_date}T00:00:00`);

    if (end < start) {
        return 0;
    }

    let days = 0;

    for (
        const day = new Date(start);
        day <= end;
        day.setDate(day.getDate() + 1)
    ) {
        // Sunday is 0 in JavaScript and 7 in the schedule the site keeps.
        const isoDay = day.getDay() === 0 ? 7 : day.getDay();

        // A public holiday is never a working day, whatever the site's week.
        if (
            props.workdays.includes(isoDay) &&
            props.holidays[dayKey(day)] === undefined
        ) {
            days += 1;
        }
    }

    return days;
});

const holidaysInRange = computed(() =>
    holidayNote(
        holidaysBetween(form.start_date, form.end_date, props.holidays),
    ),
);

// A single day is the common case, so naming the first fills in the last.
watch(
    () => form.start_date,
    (start) => {
        if (start && !form.end_date) {
            form.end_date = start;
        }
    },
);

function open() {
    form.clearErrors();
    form.reset();
    modalOpen.value = true;
}

function submit() {
    form.post('/out-of-office', {
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

    router.delete(`/out-of-office/${withdrawing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            withdrawing.value = null;
        },
    });
}
</script>

<template>
    <Head title="Out of office" />

    <AppLayout
        heading="Out of office"
        :lede="`Days worked from home or out on company business. Nothing comes off your leave. Each one needs ${approvers_required} approval${approvers_required === 1 ? '' : 's'}.`"
    >
        <template #toolbar>
            <AppButton size="sm" @click="open()">Raise a request</AppButton>
        </template>

        <div class="space-y-6">
            <Panel
                title="Your requests"
                :subtitle="`${stats.pending} awaiting a decision · ${stats.days_this_year} day${stats.days_this_year === 1 ? '' : 's'} agreed this year`"
                flush
            >
                <EmptyState
                    v-if="requests.length === 0"
                    title="Nothing filed yet"
                    message="Working from home, or away on company business? Say so here and your approver can agree it."
                >
                    <template #action>
                        <AppButton size="sm" @click="open()">
                            Raise a request
                        </AppButton>
                    </template>
                </EmptyState>

                <ul v-else class="divide-y divide-line-soft">
                    <li v-for="row in requests" :key="row.id" class="px-5 py-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p
                                        class="text-[14px] font-semibold tracking-tight"
                                    >
                                        {{ row.range_label }}
                                    </p>
                                    <StatusPill :tone="row.kind_tone">
                                        {{ row.kind_label }}
                                    </StatusPill>
                                </div>
                                <p class="mt-0.5 text-[13px] text-muted">
                                    {{ row.days }} working
                                    {{ row.days === 1 ? 'day' : 'days' }}
                                    <template v-if="row.destination">
                                        · {{ row.destination }}
                                    </template>
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

                        <p
                            v-if="row.status === 'pending'"
                            class="mt-2 text-[12.5px] text-muted"
                        >
                            {{ row.stage_label }}
                        </p>

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
            title="Work away from the office"
            subtitle="This is not leave: the days are worked, and nothing comes off your allowance."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <SelectField
                    v-model="form.kind"
                    label="What kind"
                    :options="
                        kinds.map((kind) => ({
                            value: kind.value,
                            label: kind.label,
                        }))
                    "
                    :error="form.errors.kind"
                    :hint="chosenKind?.description"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.start_date"
                        label="First day"
                        type="date"
                        required
                        :error="form.errors.start_date"
                    />
                    <TextField
                        v-model="form.end_date"
                        label="Last day"
                        type="date"
                        required
                        :error="form.errors.end_date"
                    />
                </div>

                <p
                    v-if="workingDays > 0 || holidaysInRange"
                    class="text-[12.5px] text-muted"
                >
                    <template v-if="workingDays > 0">
                        {{ workingDays }} working
                        {{ workingDays === 1 ? 'day' : 'days' }} at your site.
                    </template>
                    {{ holidaysInRange }}
                </p>

                <TextField
                    v-if="needsDestination"
                    v-model="form.destination"
                    label="Where"
                    required
                    placeholder="Client site, branch, or town"
                    :error="form.errors.destination"
                />

                <TextField
                    v-if="needsDestination"
                    v-model="form.contact_number"
                    label="Reachable on"
                    placeholder="Optional, if it is not your usual number"
                    :error="form.errors.contact_number"
                />

                <div class="space-y-1.5">
                    <label
                        for="out-of-office-reason"
                        class="flex items-center gap-1 text-[13px] font-medium text-muted"
                    >
                        What you will be doing
                        <span class="text-alert" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="out-of-office-reason"
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
            title="Withdraw this request?"
            :subtitle="withdrawing?.range_label"
            @close="withdrawing = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                Your approver stops seeing it and the days go back to being
                ordinary office days. You can file a fresh one afterwards.
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
