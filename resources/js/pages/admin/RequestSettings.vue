<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type ModuleRow = {
    value: string;
    label: string;
    description: string;
    approvers_required: number;
    pending: number;
};

type LeaveTypeRow = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    days_per_year: number | null;
    /** The separate figure managers and above draw, where the type sets one. */
    days_per_year_manager: number | null;
    min_service_months: number;
    requires_confirmed: boolean;
    requires_evidence: boolean;
    /** The date an expiring entitlement is counted from, if any. */
    anchor: string | null;
    window_months: number | null;
    is_paid: boolean;
    is_active: boolean;
    requests: number;
};

type RestrictedPeriodRow = {
    id: number;
    name: string;
    reason: string | null;
    start_date: string;
    end_date: string;
    range_label: string;
    is_over: boolean;
    exempt_marital_statuses: string[];
    exempt_leave_types: { id: number; name: string }[];
};

const props = defineProps<{
    modules: ModuleRow[];
    leave_types: LeaveTypeRow[];
    leave_anchors: Array<{ value: string; label: string }>;
    restricted_periods: RestrictedPeriodRow[];
    marital_statuses: string[];
    approver_count: number;
    totals: { leave_requests: number; lateness_requests: number };
}>();

const steps = [1, 2, 3, 4, 5];

// Each module's dial is edited on the spot and saved on its own.
const drafts = reactive<Record<string, number>>(
    Object.fromEntries(
        props.modules.map((module) => [
            module.value,
            module.approvers_required,
        ]),
    ),
);

const saving = ref<string | null>(null);

function save(module: ModuleRow) {
    saving.value = module.value;

    router.put(
        `/admin/request-settings/${module.value}`,
        { approvers_required: drafts[module.value] },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = null;
            },
        },
    );
}

const modalOpen = ref(false);
const editing = ref<LeaveTypeRow | null>(null);
const retiring = ref<LeaveTypeRow | null>(null);
const toggling = ref(false);

const form = useForm({
    name: '',
    description: '',
    days_per_year: null as number | null,
    days_per_year_manager: null as number | null,
    min_service_months: 0,
    requires_confirmed: false,
    requires_evidence: false,
    anchor: null as string | null,
    window_months: null as number | null,
    is_paid: true,
});

function open(type: LeaveTypeRow | null) {
    editing.value = type;

    form.clearErrors();
    form.defaults({
        name: type?.name ?? '',
        description: type?.description ?? '',
        days_per_year: type?.days_per_year ?? null,
        days_per_year_manager: type?.days_per_year_manager ?? null,
        min_service_months: type?.min_service_months ?? 0,
        requires_confirmed: type?.requires_confirmed ?? false,
        requires_evidence: type?.requires_evidence ?? false,
        anchor: type?.anchor ?? null,
        window_months: type?.window_months ?? null,
        is_paid: type?.is_paid ?? true,
    });
    form.reset();
    modalOpen.value = true;
}

function submit() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(`/admin/leave-types/${editing.value.id}`, done);
    } else {
        form.post('/admin/leave-types', done);
    }
}

const periodModalOpen = ref(false);
const editingPeriod = ref<RestrictedPeriodRow | null>(null);
const lifting = ref<RestrictedPeriodRow | null>(null);
const dropping = ref(false);

const today = new Date().toISOString().slice(0, 10);

const periodForm = useForm({
    name: '',
    reason: '',
    start_date: today,
    end_date: today,
    exempt_marital_statuses: [] as string[],
    exempt_leave_type_ids: [] as number[],
});

function openPeriod(period: RestrictedPeriodRow | null) {
    editingPeriod.value = period;

    periodForm.clearErrors();
    periodForm.defaults({
        name: period?.name ?? '',
        reason: period?.reason ?? '',
        start_date: period?.start_date ?? today,
        end_date: period?.end_date ?? today,
        exempt_marital_statuses: [...(period?.exempt_marital_statuses ?? [])],
        exempt_leave_type_ids: (period?.exempt_leave_types ?? []).map(
            (type) => type.id,
        ),
    });
    periodForm.reset();
    periodModalOpen.value = true;
}

function submitPeriod() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            periodModalOpen.value = false;
        },
    };

    if (editingPeriod.value) {
        periodForm.put(
            `/admin/restricted-periods/${editingPeriod.value.id}`,
            done,
        );
    } else {
        periodForm.post('/admin/restricted-periods', done);
    }
}

function lift(period: RestrictedPeriodRow) {
    dropping.value = true;

    router.delete(`/admin/restricted-periods/${period.id}`, {
        preserveScroll: true,
        onFinish: () => {
            dropping.value = false;
            lifting.value = null;
        },
    });
}

/**
 * The policy gates on a type, in a line, so an administrator can see what a
 * type asks for without opening it.
 */
function ruleSummary(type: LeaveTypeRow): string {
    const rules: string[] = [];

    if (type.min_service_months > 0) {
        rules.push(`after ${type.min_service_months} months`);
    }

    if (type.requires_confirmed) {
        rules.push('confirmed staff');
    }

    if (type.requires_evidence) {
        rules.push('evidence');
    }

    if (type.window_months !== null) {
        rules.push(`expires after ${type.window_months} months`);
    }

    return rules.join(' · ');
}

function toggle(type: LeaveTypeRow) {
    toggling.value = true;

    router.patch(
        `/admin/leave-types/${type.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                retiring.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Request settings" />

    <AppLayout
        heading="Request settings"
        lede="How leave and lateness requests are approved, and what staff can ask for."
    >
        <div class="space-y-6">
            <Panel
                title="Approvals required"
                subtitle="How many approvers must agree before a request is granted. Requests already raised keep the number they were raised under."
            >
                <div class="space-y-5">
                    <div
                        v-for="module in modules"
                        :key="module.value"
                        class="rounded-xl border border-line-soft p-4"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p
                                    class="font-display text-[15px] font-semibold tracking-tight"
                                >
                                    {{ module.label }}
                                </p>
                                <p class="mt-0.5 text-[12.5px] text-muted">
                                    {{ module.description }}
                                </p>
                            </div>

                            <StatusPill
                                :tone="module.pending ? 'brass' : 'neutral'"
                            >
                                {{ module.pending }} pending
                            </StatusPill>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <div
                                class="flex items-center gap-1 rounded-xl bg-sunken p-1"
                            >
                                <button
                                    v-for="step in steps"
                                    :key="step"
                                    type="button"
                                    :class="[
                                        'size-9 rounded-lg text-[13px] font-semibold transition-all duration-200',
                                        drafts[module.value] === step
                                            ? 'bg-brand text-white'
                                            : 'text-muted hover:bg-line-soft hover:text-text',
                                    ]"
                                    @click="drafts[module.value] = step"
                                >
                                    {{ step }}
                                </button>
                            </div>

                            <AppButton
                                size="sm"
                                :disabled="
                                    drafts[module.value] ===
                                    module.approvers_required
                                "
                                :loading="saving === module.value"
                                @click="save(module)"
                            >
                                Save
                            </AppButton>

                            <p
                                v-if="drafts[module.value] > approver_count"
                                class="text-[12.5px] text-alert"
                            >
                                Only {{ approver_count }} people can approve
                                today, so requests would never clear.
                            </p>
                        </div>
                    </div>
                </div>
            </Panel>

            <Panel
                title="Leave types"
                subtitle="What staff can book time off against. Retired types stay on old requests but cannot be chosen again."
                flush
            >
                <template #action>
                    <AppButton size="sm" @click="open(null)">
                        Add type
                    </AppButton>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-[12px] tracking-wide text-faint uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Allowance</th>
                                <th class="px-5 py-3 font-medium">Paid</th>
                                <th class="px-5 py-3 font-medium">Requests</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="type in leave_types"
                                :key="type.id"
                                class="transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ type.name }}</p>
                                    <p
                                        v-if="type.description"
                                        class="mt-0.5 max-w-sm text-[12.5px] text-muted"
                                    >
                                        {{ type.description }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{
                                        type.days_per_year === null
                                            ? 'Uncapped'
                                            : `${type.days_per_year} working days/year`
                                    }}
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ type.is_paid ? 'Paid' : 'Unpaid' }}
                                    <span
                                        v-if="ruleSummary(type)"
                                        class="block text-[12px] text-faint"
                                    >
                                        {{ ruleSummary(type) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ type.requests }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        :tone="
                                            type.is_active
                                                ? 'signal'
                                                : 'neutral'
                                        "
                                    >
                                        {{
                                            type.is_active
                                                ? 'Available'
                                                : 'Retired'
                                        }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <AppButton
                                            variant="ghost"
                                            size="sm"
                                            @click="open(type)"
                                        >
                                            Edit
                                        </AppButton>
                                        <AppButton
                                            variant="ghost"
                                            size="sm"
                                            @click="
                                                type.is_active
                                                    ? (retiring = type)
                                                    : toggle(type)
                                            "
                                        >
                                            {{
                                                type.is_active
                                                    ? 'Retire'
                                                    : 'Restore'
                                            }}
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
            <Panel
                title="Restricted periods"
                subtitle="Days closed to leave. Staff cannot book over one unless their marital status or the type of leave they pick is exempt. An approver filing on behalf of someone still gets through."
                flush
            >
                <template #action>
                    <AppButton size="sm" @click="openPeriod(null)">
                        Close a period
                    </AppButton>
                </template>

                <div v-if="restricted_periods.length" class="overflow-x-auto">
                    <table class="w-full text-left text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-[12px] tracking-wide text-faint uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Period</th>
                                <th class="px-5 py-3 font-medium">Dates</th>
                                <th class="px-5 py-3 font-medium">
                                    Can book anyway
                                </th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="period in restricted_periods"
                                :key="period.id"
                                class="transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ period.name }}</p>
                                    <p
                                        v-if="period.reason"
                                        class="mt-0.5 max-w-sm text-[12.5px] text-muted"
                                    >
                                        {{ period.reason }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ period.range_label }}
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    <p
                                        v-if="
                                            period.exempt_marital_statuses
                                                .length
                                        "
                                    >
                                        {{
                                            period.exempt_marital_statuses.join(
                                                ', ',
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-if="period.exempt_leave_types.length"
                                        class="text-[12.5px] text-faint"
                                    >
                                        {{
                                            period.exempt_leave_types
                                                .map((type) => type.name)
                                                .join(', ')
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            !period.exempt_marital_statuses
                                                .length &&
                                            !period.exempt_leave_types.length
                                        "
                                    >
                                        Nobody
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        :tone="
                                            period.is_over ? 'neutral' : 'alert'
                                        "
                                    >
                                        {{ period.is_over ? 'Over' : 'Closed' }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <AppButton
                                            variant="ghost"
                                            size="sm"
                                            @click="openPeriod(period)"
                                        >
                                            Edit
                                        </AppButton>
                                        <AppButton
                                            variant="ghost"
                                            size="sm"
                                            @click="lifting = period"
                                        >
                                            Lift
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="px-5 py-6 text-[13.5px] text-muted">
                    No periods are closed. Staff can book leave on any working
                    day.
                </p>
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            :title="editing ? `Edit ${editing.name}` : 'Add a leave type'"
            subtitle="Allowances are counted in working days: non-working days at a site are never deducted. Leave it blank for a type with no yearly cap."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.name"
                    label="Name"
                    required
                    placeholder="Compassionate leave"
                    :error="form.errors.name"
                />

                <TextField
                    v-model="form.description"
                    label="Description"
                    placeholder="Shown to staff on the leave page."
                    :error="form.errors.description"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.days_per_year"
                        label="Working days per year"
                        type="number"
                        min="1"
                        max="365"
                        placeholder="Blank for uncapped"
                        :error="form.errors.days_per_year"
                    />
                    <TextField
                        v-model="form.days_per_year_manager"
                        label="Working days per year · managers"
                        type="number"
                        min="1"
                        max="365"
                        placeholder="Blank for the same as everyone"
                        hint="Managers and above draw this instead."
                        :error="form.errors.days_per_year_manager"
                    />
                </div>

                <TextField
                    v-model="form.min_service_months"
                    label="Months of service before it opens"
                    type="number"
                    min="0"
                    max="120"
                    hint="0 for a type anyone can take from their first day."
                    :error="form.errors.min_service_months"
                />

                <label class="flex items-center gap-2.5">
                    <input
                        v-model="form.requires_confirmed"
                        type="checkbox"
                        class="size-4 rounded border-line text-brand focus:ring-brand/30"
                    />
                    <span class="text-[13.5px]">
                        Confirmed staff only
                        <span class="text-faint">
                            · closed to anyone still on probation
                        </span>
                    </span>
                </label>

                <label class="flex items-center gap-2.5">
                    <input
                        v-model="form.requires_evidence"
                        type="checkbox"
                        class="size-4 rounded border-line text-brand focus:ring-brand/30"
                    />
                    <span class="text-[13.5px]">
                        Needs supporting evidence
                        <span class="text-faint">
                            · a sick paper, letter or certificate is attached
                        </span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.anchor"
                        label="Entitlement expires from"
                        :options="leave_anchors"
                        :error="form.errors.anchor"
                        hint="Leave blank for a type that runs the calendar year."
                    >
                        <option :value="null">Does not expire</option>
                    </SelectField>
                    <TextField
                        v-model="form.window_months"
                        label="Claimable for (months)"
                        type="number"
                        min="1"
                        max="24"
                        placeholder="e.g. 6"
                        :disabled="form.anchor === null"
                        :error="form.errors.window_months"
                    />
                </div>

                <label class="flex items-center gap-2.5">
                    <input
                        v-model="form.is_paid"
                        type="checkbox"
                        class="size-4 rounded border-line text-brand focus:ring-brand/30"
                    />
                    <span class="text-[13.5px]">
                        Paid leave
                        <span class="text-faint">
                            · unpaid types still need approval
                        </span>
                    </span>
                </label>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Add type' }}
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="retiring !== null"
            width="md"
            title="Retire this leave type?"
            :subtitle="retiring?.name"
            @close="retiring = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                Staff will no longer be able to request it. The
                {{ retiring?.requests ?? 0 }} request(s) already raised against
                it keep their history, and you can restore it at any time.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="retiring = null">
                    Keep it
                </AppButton>
                <AppButton
                    variant="danger"
                    :loading="toggling"
                    @click="retiring && toggle(retiring)"
                >
                    Retire
                </AppButton>
            </template>
        </ModalShell>
        <ModalShell
            :open="periodModalOpen"
            :title="
                editingPeriod
                    ? `Edit ${editingPeriod.name}`
                    : 'Close a period to leave'
            "
            subtitle="Nobody can book leave touching these days unless you let them through below."
            @close="periodModalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submitPeriod">
                <TextField
                    v-model="periodForm.name"
                    label="Name"
                    required
                    placeholder="Year-end stock count"
                    :error="periodForm.errors.name"
                />

                <TextField
                    v-model="periodForm.reason"
                    label="Reason"
                    placeholder="Shown to staff when a request is turned away."
                    :error="periodForm.errors.reason"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="periodForm.start_date"
                        label="First day"
                        type="date"
                        required
                        :error="periodForm.errors.start_date"
                    />
                    <TextField
                        v-model="periodForm.end_date"
                        label="Last day"
                        type="date"
                        required
                        :min="periodForm.start_date"
                        :error="periodForm.errors.end_date"
                    />
                </div>

                <div class="space-y-2">
                    <p class="text-[13px] font-medium text-muted">
                        Marital statuses that can book anyway
                    </p>
                    <div class="flex flex-wrap gap-x-5 gap-y-2.5">
                        <label
                            v-for="status in marital_statuses"
                            :key="status"
                            class="flex items-center gap-2.5"
                        >
                            <input
                                v-model="periodForm.exempt_marital_statuses"
                                type="checkbox"
                                :value="status"
                                class="size-4 rounded border-line text-brand focus:ring-brand/30"
                            />
                            <span class="text-[13.5px]">{{ status }}</span>
                        </label>
                    </div>
                    <p class="text-[12.5px] text-faint">
                        Staff whose profile records no marital status are turned
                        away, and told to set one.
                    </p>
                </div>

                <div class="space-y-2">
                    <p class="text-[13px] font-medium text-muted">
                        Leave types the period does not cover
                    </p>
                    <div class="flex flex-wrap gap-x-5 gap-y-2.5">
                        <label
                            v-for="type in leave_types"
                            :key="type.id"
                            class="flex items-center gap-2.5"
                        >
                            <input
                                v-model="periodForm.exempt_leave_type_ids"
                                type="checkbox"
                                :value="type.id"
                                class="size-4 rounded border-line text-brand focus:ring-brand/30"
                            />
                            <span class="text-[13.5px]">{{ type.name }}</span>
                        </label>
                    </div>
                    <p class="text-[12.5px] text-faint">
                        Sick and compassionate leave are nobody's choice of
                        date, so they are usually left out of a closed window.
                    </p>
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="periodModalOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    :loading="periodForm.processing"
                    @click="submitPeriod"
                >
                    {{ editingPeriod ? 'Save changes' : 'Close the period' }}
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="lifting !== null"
            width="md"
            title="Lift this restriction?"
            :subtitle="lifting?.name"
            @close="lifting = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                Staff will be able to book leave over
                {{ lifting?.range_label }} again. Leave already granted over
                those days is unaffected either way.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="lifting = null">
                    Keep it closed
                </AppButton>
                <AppButton
                    variant="danger"
                    :loading="dropping"
                    @click="lifting && lift(lifting)"
                >
                    Lift
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
