<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    dateTime,
    distance,
    duration,
    fullDate,
    timeOfDay,
} from '@/lib/format';

const props = defineProps<{
    staff: {
        id: number;
        employee_id: string | null;
        name: string;
        email: string;
        initials: string;
        phone: string | null;
        department: string | null;
        position: string | null;
        role: string;
        role_label: string;
        hired_at: string | null;
        is_active: boolean;
        deactivated_at: string | null;
        created_at: string | null;
        location_id: number | null;
        clocks_in: boolean;
        location: {
            id: number;
            name: string;
            address: string;
            city: string | null;
            work_starts_at: string;
            work_ends_at: string;
            timezone: string;
            radius_meters: number;
        } | null;
    };
    stats: {
        days_present: number;
        days_late: number;
        total_hours: number;
        late_minutes: number;
        punctuality: number;
    };
    attendances: Array<{
        id: number;
        work_date: string;
        day_label: string;
        location_name: string | null;
        clocked_in_at: string | null;
        clocked_out_at: string | null;
        status: string;
        status_label: string;
        late_minutes: number;
        worked_minutes: number | null;
        break_minutes: number | null;
        clock_in_distance: number | null;
    }>;
    attempts: Array<{
        id: number;
        type_label: string;
        result: string;
        result_label: string;
        message: string | null;
        latitude: number | null;
        longitude: number | null;
        distance_meters: number | null;
        accuracy_meters: number | null;
        ip_address: string | null;
        created_at: string;
    }>;
    roles: Array<{ value: string; label: string }>;
    locations: Array<{ value: number; label: string }>;
}>();

const tab = ref<'days' | 'attempts'>('days');
const editOpen = ref(false);
const confirmOpen = ref(false);
const toggling = ref(false);

const form = useForm({
    name: props.staff.name,
    email: props.staff.email,
    employee_id: props.staff.employee_id ?? '',
    phone: props.staff.phone ?? '',
    department: props.staff.department ?? '',
    position: props.staff.position ?? '',
    hired_at: props.staff.hired_at ?? '',
    role: props.staff.role,
    location_id: props.staff.location_id,
    password: '',
    password_confirmation: '',
});

function submitEdit() {
    form.put(`/admin/staff/${props.staff.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('password', 'password_confirmation');
            editOpen.value = false;
        },
    });
}

function confirmToggle() {
    toggling.value = true;

    router.patch(
        `/admin/staff/${props.staff.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                confirmOpen.value = false;
            },
        },
    );
}

const details = [
    { label: 'Staff ID', value: props.staff.employee_id ?? '-' },
    { label: 'Email', value: props.staff.email },
    { label: 'Phone', value: props.staff.phone ?? '-' },
    { label: 'Department', value: props.staff.department ?? '-' },
    { label: 'Job title', value: props.staff.position ?? '-' },
    { label: 'Role', value: props.staff.role_label },
    {
        label: 'Work location',
        value: props.staff.location?.name ?? 'Not set',
    },
    {
        label: 'Clocks in',
        value: props.staff.clocks_in ? 'Yes' : 'No, administrator',
    },
    {
        label: 'Started',
        value: props.staff.hired_at ? fullDate(props.staff.hired_at) : '-',
    },
];

const resultTone = (result: string) =>
    result === 'success'
        ? ('signal' as const)
        : result === 'out_of_range'
          ? ('alert' as const)
          : ('brass' as const);
</script>

<template>
    <Head :title="staff.name" />

    <AppLayout :heading="staff.name" :lede="staff.position ?? staff.role_label">
        <template #toolbar>
            <AppButton size="sm" variant="secondary" @click="editOpen = true">
                Edit details
            </AppButton>
            <AppButton
                size="sm"
                :variant="staff.is_active ? 'ghost' : 'primary'"
                @click="confirmOpen = true"
            >
                {{ staff.is_active ? 'Deactivate' : 'Reactivate' }}
            </AppButton>
        </template>

        <div class="space-y-5">
            <Link
                href="/admin/staff"
                class="inline-flex items-center gap-1.5 text-[13px] text-muted transition-colors hover:text-text"
            >
                <svg
                    class="size-3.5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M15 18l-6-6 6-6" />
                </svg>
                All staff
            </Link>

            <div class="grid gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
                <!-- Identity -->
                <div class="space-y-5">
                    <Panel>
                        <div class="flex flex-col items-center text-center">
                            <Avatar
                                :initials="staff.initials"
                                :name="staff.name"
                                size="xl"
                                :muted="!staff.is_active"
                            />
                            <p
                                class="mt-4 font-display text-lg font-semibold tracking-tight"
                            >
                                {{ staff.name }}
                            </p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                {{ staff.position ?? 'No job title' }}
                            </p>

                            <div
                                class="mt-3 flex flex-wrap justify-center gap-1.5"
                            >
                                <StatusPill
                                    :tone="
                                        staff.is_active ? 'signal' : 'neutral'
                                    "
                                    dot
                                >
                                    {{
                                        staff.is_active
                                            ? 'Active'
                                            : 'Deactivated'
                                    }}
                                </StatusPill>
                                <StatusPill
                                    v-if="staff.role === 'super_admin'"
                                    tone="beacon"
                                >
                                    Super admin
                                </StatusPill>
                            </div>

                            <p
                                v-if="!staff.is_active && staff.deactivated_at"
                                class="mt-3 text-[12px] text-faint"
                            >
                                Deactivated {{ dateTime(staff.deactivated_at) }}
                            </p>
                        </div>

                        <dl
                            class="mt-6 space-y-3 border-t border-line-soft pt-5"
                        >
                            <div
                                v-for="item in details"
                                :key="item.label"
                                class="flex items-baseline justify-between gap-3"
                            >
                                <dt class="eyebrow shrink-0">
                                    {{ item.label }}
                                </dt>
                                <dd
                                    class="truncate text-right text-[13px] font-medium"
                                >
                                    {{ item.value }}
                                </dd>
                            </div>
                        </dl>
                    </Panel>
                </div>

                <!-- Record. Admins have none: they run the clock, not punch it. -->
                <Panel v-if="!staff.clocks_in" flush>
                    <EmptyState
                        title="No attendance record"
                        :message="`${staff.name} is an administrator, so they do not clock in or out. Their work location is only a note of where they are based.`"
                    />
                </Panel>

                <div v-else class="space-y-5">
                    <div class="stagger grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <StatTile
                            label="Punctuality"
                            :value="stats.punctuality"
                            suffix="%"
                            :tone="
                                stats.punctuality >= 90
                                    ? 'signal'
                                    : stats.punctuality >= 75
                                      ? 'brass'
                                      : 'alert'
                            "
                            caption="This month"
                        />
                        <StatTile
                            label="Days present"
                            :value="stats.days_present"
                        />
                        <StatTile
                            label="Late arrivals"
                            :value="stats.days_late"
                            :tone="stats.days_late > 0 ? 'brass' : 'default'"
                            :caption="duration(stats.late_minutes) + ' total'"
                        />
                        <StatTile
                            label="Hours worked"
                            :value="stats.total_hours"
                            :decimals="1"
                            suffix="h"
                        />
                    </div>

                    <div
                        class="flex gap-1 rounded-xl border border-line bg-panel p-1"
                    >
                        <button
                            v-for="option in [
                                {
                                    key: 'days',
                                    label: `Attendance (${attendances.length})`,
                                },
                                {
                                    key: 'attempts',
                                    label: `Punch log (${attempts.length})`,
                                },
                            ]"
                            :key="option.key"
                            type="button"
                            :class="[
                                'flex-1 rounded-lg px-3 py-2 text-[13px] font-medium transition-all duration-200',
                                tab === option.key
                                    ? 'bg-line-soft text-text'
                                    : 'text-muted hover:text-text',
                            ]"
                            @click="tab = option.key as 'days' | 'attempts'"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <Panel v-if="tab === 'days'" flush>
                        <div
                            v-if="attendances.length"
                            class="max-h-[520px] overflow-auto"
                        >
                            <table class="w-full min-w-[600px] text-left">
                                <thead class="sticky top-0 bg-panel">
                                    <tr class="border-b border-line-soft">
                                        <th
                                            class="eyebrow px-5 py-3 font-medium"
                                        >
                                            Day
                                        </th>
                                        <th
                                            class="eyebrow px-5 py-3 font-medium"
                                        >
                                            In
                                        </th>
                                        <th
                                            class="eyebrow px-5 py-3 font-medium"
                                        >
                                            Out
                                        </th>
                                        <th
                                            class="eyebrow px-5 py-3 font-medium"
                                        >
                                            Worked
                                        </th>
                                        <th
                                            class="eyebrow px-5 py-3 text-right font-medium"
                                        >
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line-soft">
                                    <tr
                                        v-for="record in attendances"
                                        :key="record.id"
                                        class="transition-colors hover:bg-line-soft/40"
                                    >
                                        <td
                                            class="px-5 py-3 text-[13px] font-medium whitespace-nowrap"
                                        >
                                            {{ record.day_label }}
                                        </td>
                                        <td
                                            class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                        >
                                            {{
                                                timeOfDay(record.clocked_in_at)
                                            }}
                                        </td>
                                        <td
                                            class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                        >
                                            {{
                                                record.clocked_out_at
                                                    ? timeOfDay(
                                                          record.clocked_out_at,
                                                      )
                                                    : '-'
                                            }}
                                        </td>
                                        <td
                                            class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                        >
                                            {{
                                                duration(record.worked_minutes)
                                            }}
                                        </td>
                                        <td class="px-5 py-3 text-right">
                                            <StatusPill
                                                :tone="
                                                    record.status === 'late'
                                                        ? 'brass'
                                                        : 'signal'
                                                "
                                            >
                                                <template
                                                    v-if="
                                                        record.status === 'late'
                                                    "
                                                >
                                                    +{{
                                                        duration(
                                                            record.late_minutes,
                                                        )
                                                    }}
                                                </template>
                                                <template v-else
                                                    >On time</template
                                                >
                                            </StatusPill>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <EmptyState
                            v-else
                            title="No attendance yet"
                            message="Once this staff member clocks in, their days will appear here."
                        />
                    </Panel>

                    <Panel v-else flush>
                        <ul
                            v-if="attempts.length"
                            class="max-h-[520px] divide-y divide-line-soft overflow-auto"
                        >
                            <li
                                v-for="attempt in attempts"
                                :key="attempt.id"
                                class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-3.5 transition-colors hover:bg-line-soft/40"
                            >
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <StatusPill
                                            :tone="resultTone(attempt.result)"
                                        >
                                            {{ attempt.result_label }}
                                        </StatusPill>
                                        <span class="text-[13px] font-medium">
                                            {{ attempt.type_label }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="attempt.message"
                                        class="mt-1 text-[12.5px] leading-snug text-muted"
                                    >
                                        {{ attempt.message }}
                                    </p>
                                    <p
                                        v-if="attempt.latitude !== null"
                                        class="tabular mt-1 font-mono text-[11px] text-faint"
                                    >
                                        {{ attempt.latitude?.toFixed(5) }},
                                        {{ attempt.longitude?.toFixed(5) }}
                                        <span v-if="attempt.ip_address">
                                            · {{ attempt.ip_address }}
                                        </span>
                                    </p>
                                </div>

                                <div class="text-right">
                                    <p
                                        class="tabular font-mono text-[12px] text-muted"
                                    >
                                        {{ dateTime(attempt.created_at) }}
                                    </p>
                                    <p
                                        class="tabular mt-0.5 font-mono text-[11px] text-faint"
                                    >
                                        <template
                                            v-if="
                                                attempt.distance_meters !== null
                                            "
                                        >
                                            {{
                                                distance(
                                                    attempt.distance_meters,
                                                )
                                            }}
                                            out
                                        </template>
                                        <template
                                            v-if="
                                                attempt.accuracy_meters !== null
                                            "
                                        >
                                            · ±{{ attempt.accuracy_meters }}m
                                        </template>
                                    </p>
                                </div>
                            </li>
                        </ul>

                        <EmptyState
                            v-else
                            title="No punch attempts"
                            message="Accepted and rejected clock attempts both land here, with the coordinates that were submitted."
                        />
                    </Panel>
                </div>
            </div>
        </div>

        <!-- Edit -->
        <ModalShell
            :open="editOpen"
            :title="`Edit ${staff.name}`"
            subtitle="Leave the password fields blank to keep the current password."
            @close="editOpen = false"
        >
            <form
                id="edit-staff"
                class="space-y-4"
                @submit.prevent="submitEdit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.name"
                        label="Full name"
                        required
                        :error="form.errors.name"
                    />
                    <TextField
                        v-model="form.employee_id"
                        label="Staff ID"
                        :error="form.errors.employee_id"
                    />
                    <TextField
                        v-model="form.email"
                        label="Work email"
                        type="email"
                        required
                        :error="form.errors.email"
                    />
                    <TextField
                        v-model="form.phone"
                        label="Phone"
                        :error="form.errors.phone"
                    />
                    <TextField
                        v-model="form.department"
                        label="Department"
                        :error="form.errors.department"
                    />
                    <TextField
                        v-model="form.position"
                        label="Job title"
                        :error="form.errors.position"
                    />
                    <TextField
                        v-model="form.hired_at"
                        label="Start date"
                        type="date"
                        :error="form.errors.hired_at"
                    />
                    <SelectField
                        v-model="form.role"
                        label="Role"
                        :options="roles"
                        required
                        :error="form.errors.role"
                    />
                    <SelectField
                        v-model="form.location_id"
                        label="Work location"
                        :options="locations"
                        :required="form.role === 'staff'"
                        :error="form.errors.location_id"
                        :hint="
                            form.role === 'staff'
                                ? undefined
                                : 'Optional. Admins do not clock in.'
                        "
                    >
                        <option :value="null" disabled>Choose a site</option>
                    </SelectField>
                </div>

                <div class="h-px bg-line-soft" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.password"
                        label="New password"
                        type="password"
                        hint="Optional"
                        :error="form.errors.password"
                    />
                    <TextField
                        v-model="form.password_confirmation"
                        label="Confirm new password"
                        type="password"
                    />
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="editOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="edit-staff"
                    :loading="form.processing"
                >
                    Save changes
                </AppButton>
            </template>
        </ModalShell>

        <!-- Deactivate -->
        <ModalShell
            :open="confirmOpen"
            width="md"
            :title="
                staff.is_active
                    ? 'Deactivate this account?'
                    : 'Reactivate this account?'
            "
            @close="confirmOpen = false"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                <template v-if="staff.is_active">
                    <span class="font-medium text-text">{{ staff.name }}</span>
                    will be signed out and blocked from signing in or clocking.
                    Their attendance history is kept.
                </template>
                <template v-else>
                    <span class="font-medium text-text">{{ staff.name }}</span>
                    will be able to sign in and clock again straight away.
                </template>
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="confirmOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    :variant="staff.is_active ? 'danger' : 'primary'"
                    :loading="toggling"
                    @click="confirmToggle"
                >
                    {{ staff.is_active ? 'Deactivate' : 'Reactivate' }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
