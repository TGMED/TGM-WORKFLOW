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

type Window = {
    start: string;
    end: string;
    label: string;
};

type Balance = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    is_paid: boolean;
    allowance: number | null;
    used: number;
    remaining: number | null;
    /** Whether the policy opens this type to you at all. */
    eligible: boolean;
    ineligible_reason: string | null;
    requires_evidence: boolean;
    /** Set on entitlement that lapses, such as birthday leave. */
    window: Window | null;
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

/** A stretch of the calendar the business has closed to leave. */
type RestrictedPeriod = DateRange & {
    name: string;
    reason: string | null;
    range_label: string;
    /** Whether this person's marital status lets them book over it anyway. */
    exempt: boolean;
    /** Types of leave the period does not stand in the way of. */
    allowed_type_ids: number[];
    /** They might have been let through, but their profile does not say. */
    needs_marital_status: boolean;
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
    /** The supporting document, where the type asks for one. */
    has_evidence: boolean;
    evidence_name: string | null;
    evidence_url: string | null;
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
    restricted_periods: RestrictedPeriod[];
    approvers_required: number;
    years: number[];
    stats: { pending: number; approved_days: number };
}>();

/*
 * Filters over the table. All but the year narrow the rows already in hand:
 * the list is one year of somebody's own leave, which is small, and filtering
 * it in the browser keeps the table responsive under the hand. The year is the
 * exception, because it is what the server scoped the page to.
 */
const statusFilter = ref('all');
const typeFilter = ref<'all' | number>('all');
const rowSearch = ref('');

const modalOpen = ref(false);
/** The request the modal is changing, or null when raising a fresh one. */
const editing = ref<LeaveRow | null>(null);
const expanded = ref<number | null>(null);
const withdrawing = ref<LeaveRow | null>(null);
const busy = ref(false);

const today = new Date().toISOString().slice(0, 10);

/** The statuses actually present this year, so the filter offers no dead ends. */
const statusOptions = computed(() => {
    const seen = new Map<string, string>();

    for (const row of props.requests) {
        seen.set(row.status, row.status_label);
    }

    return [
        { value: 'all', label: 'Every status' },
        ...[...seen].map(([value, label]) => ({ value, label })),
    ];
});

const typeFilterOptions = computed(() => {
    const seen = new Map<number, string>();

    for (const row of props.requests) {
        seen.set(row.type_id, row.type);
    }

    return [
        { value: 'all' as const, label: 'Every type' },
        ...[...seen].map(([value, label]) => ({ value, label })),
    ];
});

const yearOptions = computed(() =>
    props.years.map((year) => ({ value: year, label: String(year) })),
);

const visibleRequests = computed(() => {
    const term = rowSearch.value.trim().toLowerCase();

    return props.requests.filter((row) => {
        if (statusFilter.value !== 'all' && row.status !== statusFilter.value) {
            return false;
        }

        if (typeFilter.value !== 'all' && row.type_id !== typeFilter.value) {
            return false;
        }

        if (term === '') {
            return true;
        }

        return [
            row.type,
            row.reason ?? '',
            row.relief_officer ?? '',
            row.supervisor ?? '',
            row.range_label,
        ].some((field) => field.toLowerCase().includes(term));
    });
});

const filtered = computed(
    () =>
        statusFilter.value !== 'all' ||
        typeFilter.value !== 'all' ||
        rowSearch.value.trim() !== '',
);

function clearFilters() {
    statusFilter.value = 'all';
    typeFilter.value = 'all';
    rowSearch.value = '';
}

function changeYear(year: string | number | null | undefined) {
    // A year is what the page was built around, so it is fetched rather than
    // filtered: the balances and the tally have to move with it.
    router.get(
        '/leave',
        { year: Number(year) },
        { preserveScroll: true, preserveState: true },
    );
}

// Types the policy has closed stay on the list rather than quietly vanishing:
// somebody looking for sick leave should find it and be told why it is shut,
// not wonder where it went.
const typeOptions = computed(() =>
    props.balances.map((balance) => ({
        value: balance.id,
        label: !balance.eligible
            ? `${balance.name} — not open to you yet`
            : balance.remaining === null
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
    evidence: null as File | null,
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

// Days closed to leave that this person cannot book over: the period does not
// exempt them, and does not let the type they picked through either.
const closedPeriod = computed(
    () =>
        props.restricted_periods.find(
            (period) =>
                !period.exempt &&
                !period.allowed_type_ids.includes(Number(form.leave_type_id)) &&
                overlaps(period, form.start_date, form.end_date),
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

// The policy gates on the chosen type, read from the same figures the server
// checks against, so the form says no in the same words rather than letting
// somebody fill the whole thing in first.
const ineligibleReason = computed(() =>
    selectedBalance.value && !selectedBalance.value.eligible
        ? selectedBalance.value.ineligible_reason
        : null,
);

/** Set on entitlement that lapses, and caps the picker at both ends. */
const claimWindow = computed(() => selectedBalance.value?.window ?? null);

const needsEvidence = computed(
    () =>
        selectedBalance.value?.requires_evidence === true &&
        !editing.value?.has_evidence,
);

const outsideWindow = computed(() => {
    const claim = claimWindow.value;

    if (claim === null) {
        return false;
    }

    return form.start_date < claim.start || form.end_date > claim.end;
});

const exhausted = computed(
    () => remaining.value !== null && remaining.value <= 0,
);

const overAllowance = computed(
    () => remaining.value !== null && workingDays.value > remaining.value,
);
// Why the request cannot be sent yet, or null when it can. The send button
// reads this rather than a condition list of its own, so the two cannot drift
// apart and leave a dead button with nothing on screen to explain it.
//
// Three of these say nothing anywhere else on the form: dates with no working
// day in them, no approver, and no relief officer. The last is the one that
// bites, since the watcher above drops a relief officer who turns out to be
// away, and the person who picked them never sees it happen.
const blockedReason = computed<string | null>(() => {
    if (ineligibleReason.value !== null) {
        return ineligibleReason.value;
    }

    if (exhausted.value) {
        return `You have no ${selectedBalance.value?.name?.toLowerCase()} left for ${props.year}.`;
    }

    if (workingDays.value === 0) {
        return 'Those dates contain no working days for your site.';
    }

    if (overAllowance.value) {
        return `That is ${workingDays.value} working days but you only have ${remaining.value} left.`;
    }

    if (outsideWindow.value && claimWindow.value) {
        return `${selectedBalance.value?.name} has to be taken ${claimWindow.value.label}.`;
    }

    if (closedPeriod.value !== null) {
        return `Leave is closed over these dates for ${closedPeriod.value.name}.`;
    }

    if (clashingDuty.value !== null) {
        return `You are covering for ${clashingDuty.value.colleague} over these dates.`;
    }

    if (form.supervisor_id === null) {
        return 'Pick an approver to sign this off.';
    }

    if (form.relief_officer_id === null) {
        return reliefOptions.value.length === 0
            ? 'Nobody on the relief list is free to cover your desk over these dates.'
            : 'Pick a relief officer to cover your desk.';
    }

    if (needsEvidence.value && form.evidence === null) {
        return 'Attach the supporting document this type of leave needs.';
    }

    return null;
});

const canSubmit = computed(() => blockedReason.value === null);

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
        evidence: null,
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
        // The document already on file stands unless a new one is picked.
        evidence: null,
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
        form.transform((data) => ({ ...data, _method: 'put' })).post(
            `/leave/${editing.value.id}`,
            options,
        );

        return;
    }

    form.transform((data) => data).post('/leave', options);
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
                data-tour="leave-raise"
                :disabled="balances.length === 0 || supervisors.length === 0"
                @click="open"
            >
                Request leave
            </AppButton>
        </template>

        <div class="space-y-6">
            <div
                v-if="balances.length"
                data-tour="leave-balances"
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

                    <p
                        v-if="!balance.eligible"
                        class="mt-3 rounded-xl bg-brass-soft px-3 py-2 text-[12.5px] text-brass"
                    >
                        {{ balance.ineligible_reason }}
                    </p>
                    <p
                        v-else-if="balance.window"
                        class="mt-3 text-[12px] text-faint"
                    >
                        Claimable {{ balance.window.label }}, then it lapses.
                    </p>

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
                                    : `of ${balance.allowance} working days left`
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
                        {{ balance.used }} working day{{
                            balance.used === 1 ? '' : 's'
                        }}
                        used this year
                    </p>
                </article>
            </div>

            <Panel
                title="Your requests"
                :subtitle="`${stats.pending} awaiting a decision · ${stats.approved_days} approved working days in ${year}`"
                flush
            >
                <template #action>
                    <div class="w-28">
                        <SelectField
                            :model-value="year"
                            :options="yearOptions"
                            @update:model-value="changeYear"
                        />
                    </div>
                </template>

                <EmptyState
                    v-if="requests.length === 0"
                    title="No leave requested yet"
                    :message="`Nothing booked in ${year}. When you book time off it shows here with where it has reached in the approval run.`"
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

                <template v-else>
                    <div
                        class="flex flex-wrap items-end gap-3 border-b border-line-soft px-5 py-4"
                    >
                        <div class="w-44">
                            <SelectField
                                v-model="statusFilter"
                                label="Status"
                                :options="statusOptions"
                            />
                        </div>
                        <div class="w-52">
                            <SelectField
                                v-model="typeFilter"
                                label="Leave type"
                                :options="typeFilterOptions"
                            />
                        </div>
                        <div class="min-w-[12rem] flex-1">
                            <TextField
                                v-model="rowSearch"
                                label="Search"
                                placeholder="Reason, cover, approver"
                            />
                        </div>
                        <AppButton
                            v-if="filtered"
                            variant="ghost"
                            size="sm"
                            @click="clearFilters"
                        >
                            Clear
                        </AppButton>
                    </div>

                    <EmptyState
                        v-if="visibleRequests.length === 0"
                        title="Nothing matches those filters"
                        message="Widen them, or clear them to see the whole year again."
                    >
                        <template #action>
                            <AppButton size="sm" @click="clearFilters">
                                Clear filters
                            </AppButton>
                        </template>
                    </EmptyState>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-[13.5px]">
                            <thead
                                class="border-b border-line-soft text-left text-[12px] text-faint"
                            >
                                <tr>
                                    <th class="px-5 py-3 font-medium">Type</th>
                                    <th class="px-5 py-3 font-medium">Dates</th>
                                    <th
                                        class="px-5 py-3 text-right font-medium"
                                    >
                                        Days
                                    </th>
                                    <th class="px-5 py-3 font-medium">
                                        Cover and approver
                                    </th>
                                    <th class="px-5 py-3 font-medium">
                                        Status
                                    </th>
                                    <th class="px-5 py-3"></th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-line-soft">
                                <template
                                    v-for="row in visibleRequests"
                                    :key="row.id"
                                >
                                    <tr
                                        class="transition-colors hover:bg-sunken/40"
                                    >
                                        <td class="px-5 py-3.5">
                                            <p class="font-medium">
                                                {{ row.type }}
                                            </p>
                                            <p
                                                v-if="row.reason"
                                                class="max-w-[22rem] truncate text-[12px] text-faint"
                                                :title="row.reason"
                                            >
                                                {{ row.reason }}
                                            </p>
                                        </td>

                                        <td class="px-5 py-3.5 text-muted">
                                            {{ row.range_label }}
                                        </td>

                                        <td
                                            class="tabular px-5 py-3.5 text-right font-mono"
                                        >
                                            {{ row.days }}
                                        </td>

                                        <td
                                            class="px-5 py-3.5 text-[12.5px] text-muted"
                                        >
                                            {{ row.relief_officer ?? '—' }}
                                            <span class="text-faint">/</span>
                                            {{ row.supervisor ?? '—' }}
                                        </td>

                                        <td class="px-5 py-3.5">
                                            <StatusPill
                                                :tone="row.status_tone"
                                                dot
                                            >
                                                {{ row.status_label }}
                                            </StatusPill>
                                            <p
                                                v-if="row.status === 'pending'"
                                                class="mt-1 text-[11.5px] text-faint"
                                            >
                                                {{ row.stage_label }}
                                            </p>
                                            <p
                                                v-else-if="row.needs_resubmit"
                                                class="mt-1 text-[11.5px] text-brass"
                                            >
                                                Sent back to you
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
                                                    @click="toggleTrail(row)"
                                                >
                                                    {{
                                                        expanded === row.id
                                                            ? 'Hide'
                                                            : 'Trail'
                                                    }}
                                                </AppButton>

                                                <AppButton
                                                    v-if="row.can_edit"
                                                    :variant="
                                                        row.needs_resubmit
                                                            ? 'primary'
                                                            : 'ghost'
                                                    "
                                                    size="sm"
                                                    @click="edit(row)"
                                                >
                                                    {{
                                                        row.needs_resubmit
                                                            ? 'Redo'
                                                            : 'Edit'
                                                    }}
                                                </AppButton>

                                                <AppButton
                                                    v-if="
                                                        row.status ===
                                                            'pending' ||
                                                        row.needs_resubmit
                                                    "
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="withdrawing = row"
                                                >
                                                    Withdraw
                                                </AppButton>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- The decision trail opens under the row
                                         it belongs to rather than in a modal:
                                         it is read against the request, not
                                         instead of it. -->
                                    <tr v-if="expanded === row.id">
                                        <td
                                            colspan="6"
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
                                                        ({{
                                                            step.stage_label.toLowerCase()
                                                        }})
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
                                                    <span
                                                        v-if="step.superseded"
                                                        class="text-faint"
                                                    >
                                                        · on an earlier version
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

                        <p
                            v-if="filtered"
                            class="border-t border-line-soft px-5 py-3 text-[12px] text-faint"
                        >
                            Showing {{ visibleRequests.length }} of
                            {{ requests.length }} requests in {{ year }}.
                        </p>
                    </div>
                </template>
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

                <p
                    v-if="ineligibleReason"
                    class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    {{ ineligibleReason }}
                </p>

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
                        hint="Covers your desk, and agrees first. Colleagues in your own department."
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
                        :min="claimWindow?.start"
                        :max="claimWindow?.end"
                        :error="form.errors.start_date"
                    />
                    <TextField
                        v-model="form.end_date"
                        label="Last day"
                        type="date"
                        required
                        :disabled="exhausted"
                        :min="form.start_date"
                        :max="claimWindow?.end ?? latestEndDate ?? undefined"
                        :error="form.errors.end_date"
                        :hint="
                            workingDays > 0
                                ? `${workingDays} working day${workingDays === 1 ? '' : 's'} selected`
                                : undefined
                        "
                    />
                </div>

                <div
                    v-if="closedPeriod"
                    class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    <p>
                        Leave is closed from {{ closedPeriod.range_label }} for
                        {{ closedPeriod.name }}.
                        {{ closedPeriod.reason }}
                    </p>
                    <p v-if="closedPeriod.needs_marital_status" class="mt-1.5">
                        Some staff can book over it on their marital status,
                        which your profile does not record. Set it on your
                        profile if it applies to you.
                    </p>
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

                <p
                    v-if="outsideWindow && claimWindow"
                    class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    {{ selectedBalance?.name }} has to be taken
                    {{ claimWindow.label }}. After that it lapses.
                </p>

                <div
                    v-if="selectedBalance?.requires_evidence"
                    class="space-y-1.5"
                >
                    <label
                        for="leave-evidence"
                        class="flex items-center gap-1 text-[13px] font-medium text-muted"
                    >
                        Supporting document
                        <span
                            v-if="needsEvidence"
                            class="text-alert"
                            aria-hidden="true"
                            >*</span
                        >
                    </label>
                    <input
                        id="leave-evidence"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        class="w-full cursor-pointer rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out file:mr-3 file:rounded-lg file:border-0 file:bg-line-soft file:px-3 file:py-1.5 file:text-[13px] file:text-text focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                        @change="
                            form.evidence =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p
                        v-if="form.errors.evidence"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.evidence }}
                    </p>
                    <p
                        v-else-if="editing?.has_evidence"
                        class="text-[12px] text-faint"
                    >
                        {{ editing.evidence_name }} is already on file. Pick a
                        file only to replace it.
                    </p>
                    <p v-else class="text-[12px] text-faint">
                        {{ selectedBalance.name }} needs a sick paper, letter or
                        certificate. PDF or image, under 8 MB.
                    </p>
                </div>

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
                <p
                    v-if="blockedReason"
                    class="mr-auto min-w-0 text-[12.5px] text-muted"
                >
                    {{ blockedReason }}
                </p>
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
