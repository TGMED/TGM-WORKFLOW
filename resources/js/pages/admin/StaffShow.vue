<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Avatar from '@/components/ui/Avatar.vue';
import CheckboxGroupField from '@/components/ui/CheckboxGroupField.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    attendanceTone,
    dateTime,
    distance,
    duration,
    fullDate,
    money,
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
        department_id: number | null;
        team: string | null;
        team_id: number | null;
        position: string | null;
        roles: string[];
        role_labels: string[];
        hired_at: string | null;
        employment_status: string;
        employment_status_label: string;
        employment_status_tone:
            'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';
        confirmed_at: string | null;
        email_verified_at: string | null;
        is_active: boolean;
        deactivated_at: string | null;
        has_exited: boolean;
        exit_reason: string | null;
        exit_reason_label: string | null;
        exit_reason_tone: string | null;
        exit_date: string | null;
        exit_date_label: string | null;
        exit_note: string | null;
        created_at: string | null;
        updated_at: string | null;
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
        days_excused: number;
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
        excused: boolean;
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
    profile: {
        exists: boolean;
        completed_at: string | null;
        missing: string[];
        title: string | null;
        first_name: string | null;
        other_names: string | null;
        last_name: string | null;
        attendance_id: string | null;
        gender: string | null;
        date_of_birth: string | null;
        place_of_birth: string | null;
        marital_status: string | null;
        mothers_maiden_name: string | null;
        spouse_name: string | null;
        spouse_phone: string | null;
        number_of_kids: number | null;
        religion: string | null;
        blood_group: string | null;
        genotype: string | null;
        allergies: string | null;
        medical_history: string | null;
        national_id_number: string | null;
        country_of_origin: string | null;
        state_of_origin: string | null;
        local_government: string | null;
        alternate_phone: string | null;
        alternate_email: string | null;
        bank_name: string | null;
        account_name: string | null;
        account_number: string | null;
        bvn: string | null;
        sort_code: string | null;
        swift_code: string | null;
        tax_identification_number: string | null;
        rsa_number: string | null;
        pfa_name: string | null;
        nhf_number: string | null;
        annual_rent: number | null;
    };
    role_options: Array<{
        value: string;
        label: string;
        description: string | null;
    }>;
    departments: Array<{ value: number; label: string }>;
    teams: Array<{ value: number; label: string; department_id: number }>;
    locations: Array<{ value: number; label: string }>;
    exit_reasons: Array<{ value: string; label: string }>;
}>();

const tab = ref<'days' | 'attempts'>('days');
const editOpen = ref(false);
const exitOpen = ref(false);
const reinstateOpen = ref(false);
const reinstating = ref(false);

const form = useForm({
    name: props.staff.name,
    email: props.staff.email,
    employee_id: props.staff.employee_id ?? '',
    phone: props.staff.phone ?? '',
    department_id: props.staff.department_id,
    team_id: props.staff.team_id,
    position: props.staff.position ?? '',
    hired_at: props.staff.hired_at ?? '',
    roles: [...props.staff.roles],
    location_id: props.staff.location_id,
    password: '',
    password_confirmation: '',
});

/*
 * Editing somebody's details is not a password change, so an untouched pair of
 * password fields is left out of the request entirely rather than posted as a
 * pair of empty strings. That way a browser or password manager that fills the
 * fields on its own - they sit in a form with an email field, which is all
 * Chrome needs to offer the signed-in admin's own saved credentials - cannot
 * turn a name change into a failed save, or worse, a silent password reset.
 */
function submitEdit() {
    form.transform((data) => {
        if (String(data.password).trim() !== '') {
            return data;
        }

        const rest: Record<string, unknown> = { ...data };
        delete rest.password;
        delete rest.password_confirmation;

        return rest;
    }).put(`/admin/staff/${props.staff.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('password', 'password_confirmation');
            editOpen.value = false;
        },
    });
}

/* ---- Exit ------------------------------------------------------------- */
const exitForm = useForm({
    exit_reason: '',
    exit_date: new Date().toISOString().slice(0, 10),
    exit_note: '',
});

function submitExit() {
    exitForm.post(`/admin/staff/${props.staff.id}/exit`, {
        preserveScroll: true,
        onSuccess: () => {
            exitForm.reset();
            exitOpen.value = false;
        },
    });
}

function confirmReinstate() {
    reinstating.value = true;

    router.patch(
        `/admin/staff/${props.staff.id}/reinstate`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                reinstating.value = false;
                reinstateOpen.value = false;
            },
        },
    );
}

/*
 * The whole record, grouped the way somebody reads it: who they are, where
 * they sit, where they stand with us, the money details payroll needs, and the
 * account itself.
 *
 * Nothing is dropped for being empty. A blank next of kin or a missing RSA
 * number is the thing the people team is looking for, and a row that
 * disappears when unfilled hides exactly that - so a value that is not there
 * shows as "Not provided" and stays in its place.
 */
type DetailRow = { label: string; value: string; missing?: boolean };
/** `icon` is a single SVG path, the way the nav in AppLayout carries its own. */
type DetailGroup = { title: string; icon: string; rows: DetailRow[] };

const NOT_PROVIDED = 'Not provided';

/** A plain value, or the placeholder that keeps its row on the page. */
function text(value: string | number | null | undefined): DetailRow['value'] {
    if (value === null || value === undefined || String(value).trim() === '') {
        return NOT_PROVIDED;
    }

    return String(value);
}

function date(iso: string | null | undefined): string {
    return iso ? fullDate(iso) : NOT_PROVIDED;
}

function row(label: string, value: string): DetailRow {
    return { label, value, missing: value === NOT_PROVIDED };
}

const detailGroups = computed<DetailGroup[]>(() => {
    const p = props.profile;
    const location = props.staff.location;

    const fullName = [p.title, p.first_name, p.other_names, p.last_name]
        .filter(Boolean)
        .join(' ');

    return [
        {
            title: 'Identity & contact',
            icon: 'M20 20v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
            rows: [
                row('Staff ID', text(props.staff.employee_id)),
                row('Attendance ID', text(p.attendance_id)),
                row('Full name', text(fullName)),
                row('Email', text(props.staff.email)),
                row('Phone', text(props.staff.phone)),
                row('Alternate email', text(p.alternate_email)),
                row('Alternate phone', text(p.alternate_phone)),
            ],
        },
        {
            title: 'Personal & family',
            icon: 'M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM22 20v-1.5a4 4 0 0 0-3-3.87M16 3.63a4 4 0 0 1 0 7.75',
            rows: [
                row('Gender', text(p.gender)),
                row('Date of birth', date(p.date_of_birth)),
                row('Place of birth', text(p.place_of_birth)),
                row('Marital status', text(p.marital_status)),
                row('Spouse', text(p.spouse_name)),
                row('Spouse phone', text(p.spouse_phone)),
                row('Children', text(p.number_of_kids)),
                row('Religion', text(p.religion)),
                row("Mother's maiden name", text(p.mothers_maiden_name)),
            ],
        },
        {
            title: 'Origin & health',
            icon: 'M3.5 12h4l2-5 3 10 2.5-5h5',
            rows: [
                row('Country', text(p.country_of_origin)),
                row('State', text(p.state_of_origin)),
                row('Local government', text(p.local_government)),
                row('National ID', text(p.national_id_number)),
                row('Blood group', text(p.blood_group)),
                row('Genotype', text(p.genotype)),
                row('Allergies', text(p.allergies)),
                row('Medical history', text(p.medical_history)),
            ],
        },
        {
            title: 'Role & team',
            icon: 'M8 7V5.5A1.5 1.5 0 0 1 9.5 4h5A1.5 1.5 0 0 1 16 5.5V7M4.5 7h15A1.5 1.5 0 0 1 21 8.5v10a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5v-10A1.5 1.5 0 0 1 4.5 7Zm0 4.5h15',
            rows: [
                row('Department', text(props.staff.department)),
                row('Team', text(props.staff.team)),
                row('Job title', text(props.staff.position)),
                row(
                    props.staff.role_labels.length === 1 ? 'Role' : 'Roles',
                    text(props.staff.role_labels.join(', ')),
                ),
            ],
        },
        {
            title: 'Employment',
            icon: 'M8 3v3m8-3v3M3.5 9.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Zm3.5 7.5 2 2 4.5-4.5',
            rows: [
                row('Status', props.staff.employment_status_label),
                row('Started', date(props.staff.hired_at)),
                row('Confirmed', date(props.staff.confirmed_at)),
                row(
                    'Profile completed',
                    p.completed_at ? date(p.completed_at) : 'Not yet',
                ),
                row(
                    'Deactivated',
                    props.staff.deactivated_at
                        ? dateTime(props.staff.deactivated_at)
                        : 'No',
                ),
            ],
        },
        {
            title: 'Work location',
            icon: 'M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11ZM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
            rows: [
                row('Site', text(location?.name)),
                row('Address', text(location?.address)),
                row('City', text(location?.city)),
                row(
                    'Work hours',
                    location
                        ? `${location.work_starts_at} - ${location.work_ends_at}`
                        : NOT_PROVIDED,
                ),
                row('Timezone', text(location?.timezone)),
                row(
                    'Clock-in radius',
                    location ? `${location.radius_meters} m` : NOT_PROVIDED,
                ),
            ],
        },
        {
            title: 'Bank details',
            icon: 'M3.5 9.5 12 4l8.5 5.5M5 10v8m4-8v8m6-8v8m4-8v8M3.5 20.5h17',
            rows: [
                row('Bank', text(p.bank_name)),
                row('Account name', text(p.account_name)),
                row('Account number', text(p.account_number)),
                row('BVN', text(p.bvn)),
                row('Sort code', text(p.sort_code)),
                row('SWIFT', text(p.swift_code)),
            ],
        },
        {
            title: 'Tax & pension',
            icon: 'M6 3.5h12A1.5 1.5 0 0 1 19.5 5v15.5l-2.5-1.5-2.5 1.5-2.5-1.5-2.5 1.5-2.5-1.5V5A1.5 1.5 0 0 1 6 3.5ZM9 8h6M9 11.5h6M9 15h3',
            rows: [
                row('Tax ID (TIN)', text(p.tax_identification_number)),
                row('RSA number', text(p.rsa_number)),
                row('Pension manager', text(p.pfa_name)),
                row('NHF number', text(p.nhf_number)),
                row(
                    'Annual rent',
                    p.annual_rent === null
                        ? NOT_PROVIDED
                        : money(p.annual_rent),
                ),
            ],
        },
        {
            title: 'Account & access',
            icon: 'M12 3.5 4.5 6.5v5c0 4.5 3.2 7.6 7.5 9 4.3-1.4 7.5-4.5 7.5-9v-5L12 3.5Z',
            rows: [
                row(
                    'Clocks in',
                    props.staff.clocks_in ? 'Yes' : 'No, administrator',
                ),
                row(
                    'Email verified',
                    props.staff.email_verified_at
                        ? date(props.staff.email_verified_at)
                        : 'Not verified',
                ),
                row('Added', date(props.staff.created_at)),
                row(
                    'Last updated',
                    props.staff.updated_at
                        ? dateTime(props.staff.updated_at)
                        : NOT_PROVIDED,
                ),
            ],
        },
    ];
});

/** How much of one group is filled in, for the count on its header. */
function filledIn(group: DetailGroup): number {
    return group.rows.filter((r) => !r.missing).length;
}

/** How much of the HR record the employee has actually filled in. */
const recordFilled = computed(() => {
    const rows = detailGroups.value.flatMap((group) => group.rows);
    const filled = rows.filter((r) => !r.missing).length;

    return { filled, total: rows.length };
});

const resultTone = (result: string) =>
    result === 'success'
        ? ('signal' as const)
        : result === 'out_of_range'
          ? ('alert' as const)
          : ('brass' as const);

// A team only makes sense inside its department, so the picker narrows as soon
// as a department is chosen, and a team left behind by a change of department
// is cleared rather than quietly posted back.
const teamsInDepartment = computed(() =>
    props.teams.filter((team) => team.department_id === form.department_id),
);

watch(
    () => form.department_id,
    () => {
        if (
            !teamsInDepartment.value.some((team) => team.value === form.team_id)
        ) {
            form.team_id = null;
        }
    },
);
</script>

<template>
    <Head :title="staff.name" />

    <AppLayout
        :heading="staff.name"
        :lede="staff.position ?? staff.role_labels[0]"
    >
        <template #toolbar>
            <AppButton size="sm" variant="secondary" @click="editOpen = true">
                Edit details
            </AppButton>
            <AppButton
                v-if="staff.is_active"
                size="sm"
                variant="ghost"
                @click="exitOpen = true"
            >
                Record exit
            </AppButton>
            <AppButton
                v-else
                size="sm"
                variant="primary"
                @click="reinstateOpen = true"
            >
                Reinstate
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

            <div class="space-y-5">
                <!-- Identity -->
                <div>
                    <Panel>
                        <div class="flex flex-col gap-5 lg:flex-row lg:gap-6">
                            <div
                                class="flex flex-col items-center gap-4 text-center sm:flex-row sm:items-start sm:gap-5 sm:text-left lg:w-[280px] lg:shrink-0"
                            >
                                <Avatar
                                    :initials="staff.initials"
                                    :name="staff.name"
                                    size="xl"
                                    :muted="!staff.is_active"
                                />
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="font-display text-lg font-semibold tracking-tight"
                                    >
                                        {{ staff.name }}
                                    </p>
                                    <p class="mt-0.5 text-[13px] text-muted">
                                        {{ staff.position ?? 'No job title' }}
                                    </p>

                                    <div
                                        class="mt-3 flex flex-wrap justify-center gap-1.5 sm:justify-start"
                                    >
                                        <StatusPill
                                            :tone="
                                                staff.is_active
                                                    ? 'signal'
                                                    : 'neutral'
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
                                            v-if="
                                                staff.employment_status ===
                                                'probation'
                                            "
                                            :tone="staff.employment_status_tone"
                                        >
                                            {{ staff.employment_status_label }}
                                        </StatusPill>
                                        <StatusPill
                                            v-if="
                                                staff.roles.includes(
                                                    'super_admin',
                                                )
                                            "
                                            tone="beacon"
                                        >
                                            Super admin
                                        </StatusPill>
                                        <StatusPill
                                            v-if="
                                                staff.roles.includes(
                                                    'head_of_department',
                                                )
                                            "
                                            tone="neutral"
                                        >
                                            Head of department
                                        </StatusPill>
                                        <StatusPill
                                            v-if="
                                                staff.roles.includes(
                                                    'team_lead',
                                                )
                                            "
                                            tone="neutral"
                                        >
                                            Team lead
                                        </StatusPill>
                                    </div>

                                    <div
                                        v-if="staff.has_exited"
                                        class="mt-3 space-y-1 rounded-lg border border-line-soft bg-sunken px-3 py-2.5 text-left"
                                    >
                                        <p class="eyebrow">Exit</p>
                                        <p
                                            class="text-[13px] font-medium text-text"
                                        >
                                            {{ staff.exit_reason_label }}
                                        </p>
                                        <p class="text-[12px] text-muted">
                                            Last worked
                                            {{ staff.exit_date_label }}
                                        </p>
                                        <p
                                            v-if="staff.exit_note"
                                            class="text-[12px] leading-relaxed text-faint"
                                        >
                                            {{ staff.exit_note }}
                                        </p>
                                    </div>

                                    <p
                                        v-else-if="
                                            !staff.is_active &&
                                            staff.deactivated_at
                                        "
                                        class="mt-3 text-[12px] text-faint"
                                    >
                                        Deactivated
                                        {{ dateTime(staff.deactivated_at) }}
                                    </p>
                                </div>
                            </div>

                            <!--
                            Their month, alongside their name. Admins do not
                            punch a clock, so there is nothing to show them and
                            the card stays to who the person is.
                        -->
                            <div
                                v-if="staff.clocks_in"
                                class="stagger grid min-w-0 flex-1 grid-cols-2 content-center gap-3 border-t border-line-soft pt-5 lg:grid-cols-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6"
                            >
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
                                    :tone="
                                        stats.days_late > 0
                                            ? 'brass'
                                            : 'default'
                                    "
                                    :caption="
                                        stats.days_excused > 0
                                            ? `${duration(stats.late_minutes)} total, ${stats.days_excused} excused`
                                            : duration(stats.late_minutes) +
                                              ' total'
                                    "
                                />
                                <StatTile
                                    label="Hours worked"
                                    :value="stats.total_hours"
                                    :decimals="1"
                                    suffix="h"
                                />
                            </div>
                        </div>
                    </Panel>
                </div>

                <div class="space-y-5">
                    <!--
                        The record itself. Fifty-odd fields is a mile of ribbon
                        in one column and a comfortable read across three, so it
                        takes the full width and the card above stays to who the
                        person is.
                    -->
                    <Panel
                        title="Details"
                        :subtitle="`${recordFilled.filled} of ${recordFilled.total} fields filled in`"
                    >
                        <template #action>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="editOpen = true"
                            >
                                Edit
                            </AppButton>
                        </template>

                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <section
                                v-for="group in detailGroups"
                                :key="group.title"
                                class="min-w-0 rounded-xl border border-line-soft bg-sunken/50 p-4"
                            >
                                <header class="flex items-center gap-2.5">
                                    <span
                                        class="grid size-7 shrink-0 place-items-center rounded-lg border border-line-soft bg-panel text-muted"
                                    >
                                        <svg
                                            class="size-[15px]"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.7"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        >
                                            <path :d="group.icon" />
                                        </svg>
                                    </span>
                                    <h3
                                        class="min-w-0 truncate font-display text-[13.5px] font-semibold tracking-tight"
                                    >
                                        {{ group.title }}
                                    </h3>
                                    <span
                                        class="ml-auto shrink-0 text-[11px] text-faint tabular-nums"
                                    >
                                        {{ filledIn(group) }}/{{
                                            group.rows.length
                                        }}
                                    </span>
                                </header>

                                <dl class="mt-4 space-y-2.5">
                                    <div
                                        v-for="item in group.rows"
                                        :key="item.label"
                                        class="min-w-0"
                                    >
                                        <dt class="text-[11.5px] text-faint">
                                            {{ item.label }}
                                        </dt>
                                        <dd
                                            :class="[
                                                'mt-0.5 text-[13.5px] break-words',
                                                item.missing
                                                    ? 'text-faint italic'
                                                    : 'font-medium text-text',
                                            ]"
                                        >
                                            {{ item.value }}
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        </div>
                    </Panel>

                    <!-- Record. Admins have none: they run the clock, not punch it. -->
                    <Panel v-if="!staff.clocks_in" flush>
                        <EmptyState
                            title="No attendance record"
                            :message="`${staff.name} is an administrator, so they do not clock in or out. Their work location is only a note of where they are based.`"
                        />
                    </Panel>

                    <template v-else>
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
                                                    timeOfDay(
                                                        record.clocked_in_at,
                                                    )
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
                                                    duration(
                                                        record.worked_minutes,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <StatusPill
                                                    :tone="
                                                        record.excused
                                                            ? 'neutral'
                                                            : attendanceTone(
                                                                  record.status,
                                                              )
                                                    "
                                                    :title="
                                                        record.excused
                                                            ? 'Explained and approved, so it does not count as lateness'
                                                            : undefined
                                                    "
                                                >
                                                    <template
                                                        v-if="record.excused"
                                                    >
                                                        Excused
                                                    </template>
                                                    <template
                                                        v-else-if="
                                                            record.status ===
                                                            'late'
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
                                                :tone="
                                                    resultTone(attempt.result)
                                                "
                                            >
                                                {{ attempt.result_label }}
                                            </StatusPill>
                                            <span
                                                class="text-[13px] font-medium"
                                            >
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
                                                    attempt.distance_meters !==
                                                    null
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
                                                    attempt.accuracy_meters !==
                                                    null
                                                "
                                            >
                                                · ±{{
                                                    attempt.accuracy_meters
                                                }}m
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
                    </template>
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
                        autocomplete="off"
                        :error="form.errors.email"
                    />
                    <TextField
                        v-model="form.phone"
                        label="Phone"
                        :error="form.errors.phone"
                    />
                    <SelectField
                        v-model="form.department_id"
                        label="Department"
                        :options="departments"
                        :error="form.errors.department_id"
                    >
                        <option :value="null">No department</option>
                    </SelectField>
                    <SelectField
                        v-model="form.team_id"
                        label="Team"
                        :options="teamsInDepartment"
                        :error="form.errors.team_id"
                        hint="Teams belong to a department. Who leads one is set on the departments page."
                    >
                        <option :value="null">No team</option>
                    </SelectField>
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
                        v-model="form.location_id"
                        label="Work location"
                        :options="locations"
                        :required="!form.roles.includes('super_admin')"
                        :error="form.errors.location_id"
                        :hint="
                            form.roles.includes('super_admin')
                                ? 'Optional. Admins do not clock in.'
                                : undefined
                        "
                    >
                        <option :value="null" disabled>Choose a site</option>
                    </SelectField>
                </div>

                <CheckboxGroupField
                    v-model="form.roles"
                    label="Roles"
                    required
                    :options="role_options"
                    :error="
                        form.errors.roles ??
                        (form.errors as Record<string, string>)['roles.0']
                    "
                    hint="Somebody may hold more than one. Heads of department and team leads are named on the departments page, so the people they cover are named at the same time."
                />

                <div class="h-px bg-line-soft" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.password"
                        label="New password"
                        type="password"
                        autocomplete="new-password"
                        hint="Leave blank to keep the current one"
                        :error="form.errors.password"
                    />
                    <TextField
                        v-model="form.password_confirmation"
                        label="Confirm new password"
                        type="password"
                        autocomplete="new-password"
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

        <!-- Record an exit -->
        <ModalShell
            :open="exitOpen"
            width="md"
            title="Record this exit"
            @close="exitOpen = false"
        >
            <form
                id="staff-exit"
                class="space-y-4"
                @submit.prevent="submitExit"
            >
                <p class="text-[13.5px] leading-relaxed text-muted">
                    <span class="font-medium text-text">{{ staff.name }}</span>
                    will be signed out and blocked from signing in or clocking,
                    and will stop counting towards company figures. Their
                    attendance and leave history is kept in full, and anything
                    of theirs still awaiting a decision is withdrawn.
                </p>

                <SelectField
                    v-model="exitForm.exit_reason"
                    label="Reason for leaving"
                    :options="exit_reasons"
                    required
                    :error="exitForm.errors.exit_reason"
                >
                    <option value="" disabled>Choose a reason</option>
                </SelectField>

                <TextField
                    v-model="exitForm.exit_date"
                    label="Last working day"
                    type="date"
                    required
                    :error="exitForm.errors.exit_date"
                />

                <TextareaField
                    v-model="exitForm.exit_note"
                    label="Note"
                    hint="Optional. Kept on the HR record."
                    :error="exitForm.errors.exit_note"
                />
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="exitOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="staff-exit"
                    variant="danger"
                    :loading="exitForm.processing"
                >
                    Record exit
                </AppButton>
            </template>
        </ModalShell>

        <!-- Reinstate -->
        <ModalShell
            :open="reinstateOpen"
            width="md"
            title="Reinstate this account?"
            @close="reinstateOpen = false"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                <span class="font-medium text-text">{{ staff.name }}</span>
                will be able to sign in and clock again straight away, and will
                count towards company figures once more. The recorded exit is
                cleared.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="reinstateOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="reinstating" @click="confirmReinstate">
                    Reinstate
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
