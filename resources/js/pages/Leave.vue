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
import type { RequestTrail } from '@/types';

type Balance = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    is_paid: boolean;
    allowance: number | null;
    used: number;
    remaining: number | null;
};

type DateRange = {
    start: string;
    end: string;
};

type PersonOption = {
    value: number;
    label: string;
};

type ReliefOption = PersonOption & {
    /** Leave they already have booked or awaiting a decision. */
    away: DateRange[];
};

type CoverDuty = DateRange & {
    colleague: string;
};

type LeaveRow = {
    id: number;
    type: string;
    type_id: number;
    supervisor: string | null;
    supervisor_id: number | null;
    relief_officer: string | null;
    relief_officer_id: number | null;
    can_edit: boolean;
    /** Sent back by the relief officer, so saving it starts a fresh round. */
    needs_resubmit: boolean;
    stage_label: string;
    start_date: string;
    end_date: string;
    range_label: string;
    days: number;
    reason: string | null;
    status: string;
    status_label: string;
    status_tone: 'signal' | 'brass' | 'alert' | 'neutral';
    approvals_given: number;
    approvals_required: number;
    decided_at: string | null;
    created_at: string | null;
    trail: RequestTrail[];
};

const props = defineProps<{
    year: number;
    balances: Balance[];
    workdays: number[];
    requests: LeaveRow[];
    supervisors: PersonOption[];
    relief_officers: ReliefOption[];
    cover_duties: CoverDuty[];
    approvers_required: number;
    stats: { pending: number; approved_days: number };
}>();

const modalOpen = ref(false);
/** The request the modal is changing, or null when raising a fresh one. */
const editing = ref<LeaveRow | null>(null);
const expanded = ref<number | null>(null);
const withdrawing = ref<LeaveRow | null>(null);
const busy = ref(false);

const today = new Date().toISOString().slice(0, 10);

const typeOptions = computed(() =>
    props.balances.map((balance) => ({
        value: balance.id,
        label:
            balance.remaining === null
                ? balance.name
                : `${balance.name} (${balance.remaining} left)`,
    })),
);

const form = useForm({
    leave_type_id: null as number | null,
    supervisor_id: null as number | null,
    relief_officer_id: null as number | null,
    start_date: today,
    end_date: today,
    reason: '',
});

function overlaps(range: DateRange, start: string, end: string): boolean {
    return range.start <= end && range.end >= start;
}

// Cover this person owes a colleague over the days they are asking for. They
// have to be at their desk for it, so the request cannot stand.
const clashingDuty = computed(
    () =>
        props.cover_duties.find((duty) =>
            overlaps(duty, form.start_date, form.end_date),
        ) ?? null,
);

// The relief officer signs off the cover first, so they cannot also be the
// approver who rules on it, and somebody who is away themselves is no cover.
const reliefOptions = computed(() =>
    props.relief_officers.filter(
        (person) =>
            person.value !== Number(form.supervisor_id) &&
            !person.away.some((range) =>
                overlaps(range, form.start_date, form.end_date),
            ),
    ),
);

const awayCount = computed(
    () =>
        props.relief_officers.length -
        props.relief_officers.filter(
            (person) =>
                !person.away.some((range) =>
                    overlaps(range, form.start_date, form.end_date),
                ),
        ).length,
);

// Moving the dates can strand a relief officer who is now away themselves.
watch(reliefOptions, (options) => {
    if (!options.some((person) => person.value === form.relief_officer_id)) {
        form.relief_officer_id = null;
    }
});

const selectedBalance = computed(
    () =>
        props.balances.find(
            (balance) => balance.id === Number(form.leave_type_id),
        ) ?? null,
);

/**
 * Days left this year on the chosen type, or null when it is uncapped. The
 * request being edited already counts against its own type, so its days come
 * back before the replacement is measured, exactly as the server does it.
 */
const remaining = computed(() => {
    const left = selectedBalance.value?.remaining ?? null;

    if (left === null) {
        return null;
    }

    const own =
        editing.value && editing.value.type_id === Number(form.leave_type_id)
            ? editing.value.days
            : 0;

    return left + own;
});

function parseDate(value: string): Date | null {
    const date = new Date(`${value}T00:00:00`);

    return Number.isNaN(date.getTime()) ? null : date;
}

function toIsoDate(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/** Mirrors App\Support\Workdays: Monday is 1, Sunday is 7. */
function isWorkday(date: Date): boolean {
    return props.workdays.includes(date.getDay() === 0 ? 7 : date.getDay());
}

// The same count the server will do, so what the form shows is what gets
// stored rather than a calendar-day guess.
const workingDays = computed(() => {
    const start = parseDate(form.start_date);
    const end = parseDate(form.end_date);

    if (!start || !end || end < start) {
        return 0;
    }

    let days = 0;

    for (
        const day = new Date(start);
        day <= end;
        day.setDate(day.getDate() + 1)
    ) {
        if (isWorkday(day)) {
            days++;
        }
    }

    return days;
});

// The last day the allowance can stretch to: walk forward from the first day
// until the remaining working days are spent. The date picker will not open
// past this, so the balance cannot be overrun from the calendar.
const latestEndDate = computed(() => {
    const start = parseDate(form.start_date);
    const budget = remaining.value;

    if (!start || budget === null || budget <= 0) {
        return null;
    }

    const day = new Date(start);
    let spent = 0;
    let last = new Date(start);

    // A year of walking is far past any allowance and stops a runaway loop on
    // a site with no working days configured.
    for (let step = 0; step < 366 && spent < budget; step++) {
        if (isWorkday(day)) {
            spent++;
            last = new Date(day);
        }

        day.setDate(day.getDate() + 1);
    }

    return spent === budget ? toIsoDate(last) : null;
});

const exhausted = computed(
    () => remaining.value !== null && remaining.value <= 0,
);

const overAllowance = computed(
    () => remaining.value !== null && workingDays.value > remaining.value,
);
const canSubmit = computed(
    () =>
        !exhausted.value &&
        !overAllowance.value &&
        workingDays.value > 0 &&
        clashingDuty.value === null &&
        form.supervisor_id !== null &&
        form.relief_officer_id !== null,
);

// Changing the type or the first day can leave the last day out of range, so
// it is pulled back rather than left showing something that cannot be sent.
watch([latestEndDate, () => form.start_date], () => {
    if (form.end_date < form.start_date) {
        form.end_date = form.start_date;
    }

    if (latestEndDate.value !== null && form.end_date > latestEndDate.value) {
        form.end_date = latestEndDate.value;
    }
});

function open() {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        leave_type_id: props.balances[0]?.id ?? null,
        supervisor_id: props.supervisors[0]?.value ?? null,
        relief_officer_id: null,
        start_date: today,
        end_date: today,
        reason: '',
    });
    form.reset();
    modalOpen.value = true;
}

function edit(row: LeaveRow) {
    editing.value = row;
    form.clearErrors();
    form.defaults({
        leave_type_id: row.type_id,
        supervisor_id: row.supervisor_id,
        relief_officer_id: row.relief_officer_id,
        start_date: row.start_date,
        end_date: row.end_date,
        reason: row.reason ?? '',
    });
    form.reset();
    modalOpen.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
            editing.value = null;
        },
    };

    if (editing.value) {
        form.put(`/leave/${editing.value.id}`, options);

        return;
    }

    form.post('/leave', options);
}

function withdraw() {
    if (!withdrawing.value) {
        return;
    }

    busy.value = true;

    router.delete(`/leave/${withdrawing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            withdrawing.value = null;
        },
    });
}

function toggleTrail(row: LeaveRow) {
    expanded.value = expanded.value === row.id ? null : row.id;
}
</script>

<template>
    <Head title="Leave" />

    <AppLayout
        heading="Leave"
        :lede="`Your time off for ${year}. Requests need ${approvers_required} approval${approvers_required === 1 ? '' : 's'}.`"
    >
        <template #toolbar>
            <AppButton
                size="sm"
                :disabled="balances.length === 0 || supervisors.length === 0"
                @click="open"
            >
                Request leave
            </AppButton>
        </template>

        <div class="space-y-6">
            <div
                v-if="balances.length"
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <article
                    v-for="balance in balances"
                    :key="balance.id"
                    class="rounded-2xl border border-line bg-panel p-5 shadow-panel"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="truncate font-display text-[15px] font-semibold tracking-tight"
                            >
                                {{ balance.name }}
                            </p>
                            <p
                                v-if="balance.description"
                                class="mt-0.5 line-clamp-2 text-[12.5px] text-muted"
                            >
                                {{ balance.description }}
                            </p>
                        </div>
                        <StatusPill
                            :tone="balance.is_paid ? 'signal' : 'neutral'"
                        >
                            {{ balance.is_paid ? 'Paid' : 'Unpaid' }}
                        </StatusPill>
                    </div>

                    <div class="mt-4 flex items-end gap-2">
                        <span
                            class="font-display text-3xl font-semibold tracking-tight"
                        >
                            {{ balance.remaining ?? '—' }}
                        </span>
                        <span class="pb-1 text-[12.5px] text-muted">
                            {{
                                balance.allowance === null
                                    ? 'no yearly cap'
                                    : `of ${balance.allowance} days left`
                            }}
                        </span>
                    </div>

                    <div
                        v-if="balance.allowance"
                        class="mt-3 h-1.5 overflow-hidden rounded-full bg-line-soft"
                    >
                        <div
                            class="h-full rounded-full bg-brand transition-all duration-500"
                            :style="{
                                width: `${Math.min(100, (balance.used / balance.allowance) * 100)}%`,
                            }"
                        />
                    </div>
                    <p class="mt-2 text-[12px] text-faint">
                        {{ balance.used }} day{{
                            balance.used === 1 ? '' : 's'
                        }}
                        used this year
                    </p>
                </article>
            </div>

            <Panel
                title="Your requests"
                :subtitle="`${stats.pending} awaiting a decision · ${stats.approved_days} approved days in ${year}`"
                flush
            >
                <EmptyState
                    v-if="requests.length === 0"
                    title="No leave requested yet"
                    message="When you book time off it shows here with where it has reached in the approval run."
                >
                    <template #action>
                        <AppButton
                            size="sm"
                            :disabled="balances.length === 0"
                            @click="open"
                        >
                            Request leave
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
                                    {{ row.type }}
                                </p>
                                <p class="mt-0.5 text-[13px] text-muted">
                                    {{ row.range_label }} ·
                                    {{ row.days }} working day{{
                                        row.days === 1 ? '' : 's'
                                    }}
                                </p>
                                <p
                                    v-if="row.relief_officer || row.supervisor"
                                    class="mt-0.5 text-[12.5px] text-faint"
                                >
                                    Cover: {{ row.relief_officer ?? '—' }} ·
                                    Approver: {{ row.supervisor ?? '—' }}
                                </p>
                                <p
                                    v-if="row.reason"
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
                                    {{ row.stage_label }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center gap-3">
                            <button
                                v-if="row.trail.length"
                                type="button"
                                class="text-[12.5px] font-medium text-muted transition-colors hover:text-text"
                                @click="toggleTrail(row)"
                            >
                                {{ expanded === row.id ? 'Hide' : 'Show' }}
                                decisions ({{ row.trail.length }})
                            </button>

                            <AppButton
                                v-if="row.can_edit"
                                :variant="
                                    row.needs_resubmit ? 'primary' : 'ghost'
                                "
                                size="sm"
                                @click="edit(row)"
                            >
                                {{
                                    row.needs_resubmit
                                        ? 'Change and resubmit'
                                        : 'Edit'
                                }}
                            </AppButton>

                            <AppButton
                                v-if="
                                    row.status === 'pending' ||
                                    row.needs_resubmit
                                "
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
                                <span class="font-medium">{{
                                    step.approver
                                }}</span>
                                <span class="text-muted">
                                    ({{ step.stage_label.toLowerCase() }})
                                    {{ step.decision_label.toLowerCase() }} on
                                    {{ dateTime(step.decided_at) }}
                                </span>
                                <span v-if="step.superseded" class="text-faint">
                                    · on an earlier version
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
            :title="
                editing?.needs_resubmit
                    ? 'Redo this request'
                    : editing
                      ? 'Change this request'
                      : 'Request leave'
            "
            :subtitle="
                editing?.needs_resubmit
                    ? 'It was sent back to you. Saving sends it round the chain again from the start.'
                    : editing
                      ? 'Nobody has ruled on it yet, so it can still be changed.'
                      : 'Non-working days at your site are not counted.'
            "
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <SelectField
                    v-model="form.leave_type_id"
                    label="Leave type"
                    required
                    :options="typeOptions"
                    :error="form.errors.leave_type_id"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.supervisor_id"
                        label="Approver"
                        required
                        :options="supervisors"
                        :error="form.errors.supervisor_id"
                        hint="Who signs the leave off."
                    />
                    <SelectField
                        v-model="form.relief_officer_id"
                        label="Relief officer"
                        required
                        :options="reliefOptions"
                        :error="form.errors.relief_officer_id"
                        hint="Covers your desk, and agrees first."
                    >
                        <option :value="null" disabled>Pick a colleague</option>
                    </SelectField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.start_date"
                        label="First day"
                        type="date"
                        required
                        :disabled="exhausted"
                        :error="form.errors.start_date"
                    />
                    <TextField
                        v-model="form.end_date"
                        label="Last day"
                        type="date"
                        required
                        :disabled="exhausted"
                        :min="form.start_date"
                        :max="latestEndDate ?? undefined"
                        :error="form.errors.end_date"
                        :hint="
                            workingDays > 0
                                ? `${workingDays} working day(s) selected`
                                : undefined
                        "
                    />
                </div>

                <p
                    v-if="clashingDuty"
                    class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    You are covering for {{ clashingDuty.colleague }} from
                    {{ clashingDuty.start }} to {{ clashingDuty.end }}. Hand
                    that over before booking these days.
                </p>
                <p v-else-if="awayCount > 0" class="text-[12.5px] text-faint">
                    {{ awayCount }} colleague(s) are away over these dates and
                    are not on the relief list.
                </p>

                <p
                    v-if="exhausted"
                    class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    You have no {{ selectedBalance?.name?.toLowerCase() }} left
                    for {{ year }}. Pick another type.
                </p>
                <p
                    v-else-if="overAllowance"
                    class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    That is {{ workingDays }} working days but you only have
                    {{ remaining }} left.
                </p>
                <p
                    v-else-if="remaining !== null"
                    class="text-[12.5px] text-faint"
                >
                    {{ remaining }} day(s) of
                    {{ selectedBalance?.name?.toLowerCase() }} left this year,
                    so the last day cannot go past {{ latestEndDate }}.
                </p>

                <div class="space-y-1.5">
                    <label
                        for="leave-reason"
                        class="text-[13px] font-medium text-muted"
                    >
                        Reason
                    </label>
                    <textarea
                        id="leave-reason"
                        v-model="form.reason"
                        rows="3"
                        placeholder="Optional, but it helps your approver decide."
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
                <AppButton
                    :loading="form.processing"
                    :disabled="!canSubmit"
                    @click="submit"
                >
                    {{
                        editing?.needs_resubmit
                            ? 'Resubmit'
                            : editing
                              ? 'Save changes'
                              : 'Send for cover'
                    }}
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
                The days go back to your balance and your approver stops seeing
                it. You can raise a fresh request afterwards.
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
