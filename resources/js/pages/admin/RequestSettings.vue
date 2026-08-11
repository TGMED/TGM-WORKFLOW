<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
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
    is_paid: boolean;
    is_active: boolean;
    requests: number;
};

type ApproverRow = {
    id: number;
    name: string;
    department: string | null;
    position: string | null;
    is_approver: boolean;
    locked: boolean;
};

const props = defineProps<{
    modules: ModuleRow[];
    leave_types: LeaveTypeRow[];
    approvers: ApproverRow[];
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

// The approver pool is edited as a whole and posted in one go, so several
// people can be given or stripped of the rights on a single save.
const picked = ref<number[]>(
    props.approvers.filter((row) => row.is_approver).map((row) => row.id),
);
const search = ref('');
const savingApprovers = ref(false);

// A save can decline part of what was asked for, so the ticks follow whatever
// the server sends back rather than what was posted.
watch(
    () => props.approvers,
    (rows) => {
        picked.value = rows
            .filter((row) => row.is_approver)
            .map((row) => row.id);
    },
);

const matches = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return props.approvers;
    }

    return props.approvers.filter((row) =>
        [row.name, row.department, row.position]
            .filter((field): field is string => Boolean(field))
            .some((field) => field.toLowerCase().includes(term)),
    );
});

const approverDirty = computed(() => {
    const before = props.approvers
        .filter((row) => row.is_approver)
        .map((row) => row.id);

    return (
        before.length !== picked.value.length ||
        before.some((id) => !picked.value.includes(id))
    );
});

function toggleApprover(row: ApproverRow) {
    if (row.locked) {
        return;
    }

    picked.value = picked.value.includes(row.id)
        ? picked.value.filter((id) => id !== row.id)
        : [...picked.value, row.id];
}

function saveApprovers() {
    savingApprovers.value = true;

    router.put(
        '/admin/request-settings/approvers',
        { user_ids: picked.value },
        {
            preserveScroll: true,
            onFinish: () => {
                savingApprovers.value = false;
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
    is_paid: true,
});

function open(type: LeaveTypeRow | null) {
    editing.value = type;

    form.clearErrors();
    form.defaults({
        name: type?.name ?? '',
        description: type?.description ?? '',
        days_per_year: type?.days_per_year ?? null,
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
                title="Who can approve"
                subtitle="Tick everyone who should be able to rule on leave and lateness. Staff pick their approver from this list when they raise a request."
            >
                <template #action>
                    <AppButton
                        size="sm"
                        :disabled="!approverDirty"
                        :loading="savingApprovers"
                        @click="saveApprovers"
                    >
                        Save
                    </AppButton>
                </template>

                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <TextField
                            v-model="search"
                            class="min-w-56 flex-1"
                            label="Find someone"
                            placeholder="Name, department or job title"
                        />

                        <StatusPill :tone="picked.length ? 'signal' : 'alert'">
                            {{ picked.length }} selected
                        </StatusPill>
                    </div>

                    <div
                        class="max-h-96 divide-y divide-line-soft overflow-y-auto rounded-xl border border-line-soft"
                    >
                        <label
                            v-for="row in matches"
                            :key="row.id"
                            :class="[
                                'flex items-start gap-3 px-4 py-3 transition-colors',
                                row.locked
                                    ? 'cursor-not-allowed opacity-70'
                                    : 'cursor-pointer hover:bg-line-soft/40',
                            ]"
                        >
                            <input
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-line text-brand focus:ring-brand/30"
                                :checked="picked.includes(row.id)"
                                :disabled="row.locked"
                                @change="toggleApprover(row)"
                            />

                            <span class="min-w-0 flex-1">
                                <span class="block text-[13.5px] font-medium">
                                    {{ row.name }}
                                </span>
                                <span
                                    v-if="row.department || row.position"
                                    class="mt-0.5 block text-[12.5px] text-muted"
                                >
                                    {{
                                        [row.position, row.department]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </span>
                                <span
                                    v-if="row.locked"
                                    class="mt-1 block text-[12px] text-faint"
                                >
                                    Named on leave still waiting on them, so
                                    their rights cannot be removed yet.
                                </span>
                            </span>
                        </label>

                        <p
                            v-if="matches.length === 0"
                            class="px-4 py-6 text-center text-[13px] text-muted"
                        >
                            Nobody matches "{{ search }}".
                        </p>
                    </div>

                    <p class="text-[12.5px] text-faint">
                        Super admins approve as part of running the system and
                        are not listed here. Anyone unticked drops back to
                        ordinary staff.
                    </p>
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
                                            : `${type.days_per_year} days/year`
                                    }}
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ type.is_paid ? 'Paid' : 'Unpaid' }}
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
        </div>

        <ModalShell
            :open="modalOpen"
            :title="editing ? `Edit ${editing.name}` : 'Add a leave type'"
            subtitle="Leave the allowance blank for a type with no yearly cap."
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

                <TextField
                    v-model="form.days_per_year"
                    label="Days per year"
                    type="number"
                    min="1"
                    max="365"
                    placeholder="Blank for uncapped"
                    :error="form.errors.days_per_year"
                />

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
    </AppLayout>
</template>
