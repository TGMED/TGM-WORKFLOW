<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { attendanceTone, timeOfDay } from '@/lib/format';

type StaffRow = {
    id: number;
    employee_id: string | null;
    name: string;
    email: string;
    initials: string;
    role: string;
    role_label: string;
    department: string | null;
    position: string | null;
    is_active: boolean;
    clocks_in: boolean;
    location: { id: number; name: string; city: string | null } | null;
    late_this_month: number;
    present_this_month: number;
    today: {
        status: string;
        clocked_in_at: string | null;
        clocked_out_at: string | null;
        late_minutes: number;
    } | null;
};

const props = defineProps<{
    staff: {
        data: StaffRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        status: string;
        department: string;
        location: string;
    };
    departments: string[];
    locations: Array<{ value: number; label: string }>;
    roles: Array<{ value: string; label: string }>;
    totals: {
        all: number;
        active: number;
        inactive: number;
        unassigned: number;
    };
}>();

/* ---- Filtering -------------------------------------------------------- */
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const department = ref(props.filters.department);
const location = ref(props.filters.location);

let debounce: number | undefined;

function applyFilters(immediate = false) {
    window.clearTimeout(debounce);

    const run = () =>
        router.get(
            '/admin/staff',
            {
                search: search.value || undefined,
                status: status.value === 'all' ? undefined : status.value,
                department: department.value || undefined,
                location: location.value || undefined,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );

    if (immediate) {
        run();
    } else {
        debounce = window.setTimeout(run, 320);
    }
}

watch(search, () => applyFilters());
watch([status, department, location], () => applyFilters(true));

/* ---- Adding a staff member -------------------------------------------- */
const addOpen = ref(false);

const addForm = useForm({
    name: '',
    email: '',
    employee_id: '',
    phone: '',
    department: '',
    position: '',
    hired_at: '',
    role: 'staff',
    location_id: null as number | null,
    password: '',
    password_confirmation: '',
});

function submitAdd() {
    addForm.post('/admin/staff', {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset();
            addOpen.value = false;
        },
    });
}

/* ---- Reinstatement ----------------------------------------------------- */
/*
 * Recording an exit asks for a reason, a last day and often a note, so it
 * lives on the person's own record rather than in a list row. Putting
 * somebody back is a single decision, and stays here.
 */
const confirming = ref<StaffRow | null>(null);
const reinstating = ref(false);

function confirmReinstate() {
    if (!confirming.value) {
        return;
    }

    reinstating.value = true;

    router.patch(
        `/admin/staff/${confirming.value.id}/reinstate`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                reinstating.value = false;
                confirming.value = null;
            },
        },
    );
}

const statusOptions = [
    { value: 'all', label: 'Everyone' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Deactivated only' },
];
</script>

<template>
    <Head title="Staff" />

    <AppLayout
        heading="Staff"
        :lede="`${totals.active} active · ${totals.inactive} deactivated`"
    >
        <template #toolbar>
            <AppButton size="sm" @click="addOpen = true">
                <svg
                    class="size-4"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Add staff
            </AppButton>
        </template>

        <div class="space-y-5">
            <!-- Filters -->
            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_170px_180px_190px]"
            >
                <div class="relative">
                    <svg
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-faint"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.9"
                        stroke-linecap="round"
                    >
                        <circle cx="11" cy="11" r="6.5" />
                        <path d="m20 20-4.2-4.2" />
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name, email or staff ID"
                        class="h-11 w-full rounded-xl border border-line bg-panel pr-3.5 pl-10 text-sm transition-all duration-200 placeholder:text-faint focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                </div>

                <SelectField v-model="status" :options="statusOptions" />

                <SelectField
                    v-model="department"
                    :options="departments.map((d) => ({ value: d, label: d }))"
                >
                    <option value="">All departments</option>
                </SelectField>

                <SelectField
                    v-model="location"
                    :options="
                        locations.map((l) => ({
                            value: String(l.value),
                            label: l.label,
                        }))
                    "
                >
                    <option value="">All locations</option>
                    <option value="none">No location set</option>
                </SelectField>
            </div>

            <Panel flush>
                <div v-if="staff.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Name
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Location
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Department
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Today
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    This month
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Account
                                </th>
                                <th
                                    class="eyebrow px-5 py-3 text-right font-medium"
                                >
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="person in staff.data"
                                :key="person.id"
                                class="group transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3">
                                    <Link
                                        :href="`/admin/staff/${person.id}`"
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :initials="person.initials"
                                            :name="person.name"
                                            size="sm"
                                            :muted="!person.is_active"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="flex items-center gap-1.5 text-[13.5px] font-medium"
                                            >
                                                <span class="truncate">{{
                                                    person.name
                                                }}</span>
                                                <StatusPill
                                                    v-if="
                                                        person.role ===
                                                        'super_admin'
                                                    "
                                                    tone="beacon"
                                                >
                                                    Admin
                                                </StatusPill>
                                            </span>
                                            <span
                                                class="block truncate text-[12px] text-faint"
                                            >
                                                {{
                                                    person.employee_id ??
                                                    person.email
                                                }}
                                            </span>
                                        </span>
                                    </Link>
                                </td>

                                <td class="px-5 py-3">
                                    <p
                                        v-if="person.location"
                                        class="text-[13px]"
                                    >
                                        {{ person.location.name }}
                                    </p>
                                    <StatusPill
                                        v-else
                                        :tone="
                                            person.clocks_in
                                                ? 'alert'
                                                : 'neutral'
                                        "
                                    >
                                        {{ person.clocks_in ? 'Not set' : '-' }}
                                    </StatusPill>
                                    <p
                                        v-if="person.location?.city"
                                        class="truncate text-[11.5px] text-faint"
                                    >
                                        {{ person.location.city }}
                                    </p>
                                </td>

                                <td class="px-5 py-3">
                                    <p class="text-[13px]">
                                        {{ person.department ?? '-' }}
                                    </p>
                                    <p
                                        class="truncate text-[11.5px] text-faint"
                                    >
                                        {{ person.position ?? 'No title' }}
                                    </p>
                                </td>

                                <td class="px-5 py-3">
                                    <span
                                        v-if="!person.clocks_in"
                                        class="text-[12.5px] text-faint"
                                    >
                                        Does not clock
                                    </span>
                                    <StatusPill
                                        v-else-if="person.today?.clocked_in_at"
                                        :tone="
                                            attendanceTone(person.today.status)
                                        "
                                        dot
                                        :pulse="!person.today.clocked_out_at"
                                    >
                                        {{
                                            timeOfDay(
                                                person.today.clocked_in_at,
                                            )
                                        }}
                                    </StatusPill>
                                    <span
                                        v-else
                                        class="text-[12.5px] text-faint"
                                    >
                                        Not in
                                    </span>
                                </td>

                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                >
                                    <span class="text-muted">
                                        {{ person.present_this_month }} days
                                    </span>
                                    <span
                                        v-if="person.late_this_month > 0"
                                        class="text-brass"
                                    >
                                        · {{ person.late_this_month }} late
                                    </span>
                                </td>

                                <td class="px-5 py-3">
                                    <StatusPill
                                        :tone="
                                            person.is_active
                                                ? 'signal'
                                                : 'neutral'
                                        "
                                    >
                                        {{
                                            person.is_active
                                                ? 'Active'
                                                : 'Deactivated'
                                        }}
                                    </StatusPill>
                                </td>

                                <td
                                    class="px-5 py-3 text-right whitespace-nowrap"
                                >
                                    <div
                                        class="inline-flex items-center gap-1 opacity-60 transition-opacity group-hover:opacity-100"
                                    >
                                        <Link
                                            :href="`/admin/staff/${person.id}`"
                                        >
                                            <AppButton
                                                size="sm"
                                                variant="ghost"
                                            >
                                                View
                                            </AppButton>
                                        </Link>
                                        <Link
                                            v-if="person.is_active"
                                            :href="`/admin/staff/${person.id}`"
                                        >
                                            <AppButton
                                                size="sm"
                                                variant="ghost"
                                            >
                                                Record exit
                                            </AppButton>
                                        </Link>
                                        <AppButton
                                            v-else
                                            size="sm"
                                            variant="ghost"
                                            @click="confirming = person"
                                        >
                                            Reinstate
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <EmptyState
                    v-else
                    title="No staff match those filters"
                    message="Try a different search term, or clear the filters to see the whole team."
                >
                    <template #action>
                        <AppButton
                            variant="secondary"
                            size="sm"
                            @click="
                                search = '';
                                status = 'all';
                                department = '';
                                location = '';
                            "
                        >
                            Clear filters
                        </AppButton>
                    </template>
                </EmptyState>

                <Pagination
                    :links="staff.links"
                    :from="staff.from"
                    :to="staff.to"
                    :total="staff.total"
                />
            </Panel>
        </div>

        <!-- Add staff -->
        <ModalShell
            :open="addOpen"
            title="Add a staff member"
            subtitle="They can sign in as soon as you save."
            @close="addOpen = false"
        >
            <form id="add-staff" class="space-y-4" @submit.prevent="submitAdd">
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="addForm.name"
                        label="Full name"
                        required
                        :error="addForm.errors.name"
                    />
                    <TextField
                        v-model="addForm.employee_id"
                        label="Staff ID"
                        placeholder="TGM-0042"
                        :error="addForm.errors.employee_id"
                    />
                    <TextField
                        v-model="addForm.email"
                        label="Work email"
                        type="email"
                        required
                        :error="addForm.errors.email"
                    />
                    <TextField
                        v-model="addForm.phone"
                        label="Phone"
                        :error="addForm.errors.phone"
                    />
                    <TextField
                        v-model="addForm.department"
                        label="Department"
                        :error="addForm.errors.department"
                    />
                    <TextField
                        v-model="addForm.position"
                        label="Job title"
                        :error="addForm.errors.position"
                    />
                    <TextField
                        v-model="addForm.hired_at"
                        label="Start date"
                        type="date"
                        :error="addForm.errors.hired_at"
                    />
                    <SelectField
                        v-model="addForm.role"
                        label="Role"
                        :options="roles"
                        required
                        :error="addForm.errors.role"
                        hint="Admins manage staff and locations."
                    />
                    <SelectField
                        v-model="addForm.location_id"
                        label="Work location"
                        :options="locations"
                        :required="addForm.role === 'staff'"
                        :error="addForm.errors.location_id"
                        :hint="
                            addForm.role === 'staff'
                                ? 'Their punches are measured against this site.'
                                : 'Optional. Admins do not clock in.'
                        "
                    >
                        <option :value="null" disabled>Choose a site</option>
                    </SelectField>
                </div>

                <div class="h-px bg-line-soft" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="addForm.password"
                        label="Temporary password"
                        type="password"
                        required
                        :error="addForm.errors.password"
                    />
                    <TextField
                        v-model="addForm.password_confirmation"
                        label="Confirm password"
                        type="password"
                        required
                    />
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="addOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="add-staff"
                    :loading="addForm.processing"
                >
                    Add staff member
                </AppButton>
            </template>
        </ModalShell>

        <!-- Reinstatement confirmation -->
        <ModalShell
            :open="!!confirming"
            width="md"
            title="Reinstate this account?"
            @close="confirming = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                <span class="font-medium text-text">{{
                    confirming?.name
                }}</span>
                will be able to sign in and clock again straight away, and will
                count towards company figures once more. Any recorded exit is
                cleared.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="confirming = null">
                    Cancel
                </AppButton>
                <AppButton :loading="reinstating" @click="confirmReinstate">
                    Reinstate
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
