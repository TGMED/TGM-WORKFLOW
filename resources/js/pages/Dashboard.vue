<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ArrivalStrip from '@/components/ArrivalStrip.vue';
import PunchDial from '@/components/PunchDial.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LocationSelect from '@/components/ui/LocationSelect.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { useGeoFix } from '@/composables/useGeoFix';
import { useToasts } from '@/composables/useToasts';
import AppLayout from '@/layouts/AppLayout.vue';
import { distance, duration, timeOfDay } from '@/lib/format';
import type { SharedProps } from '@/types';

type Attendance = {
    id: number;
    work_date: string;
    clocked_in_at: string | null;
    clocked_out_at: string | null;
    status: string;
    status_label: string;
    late_minutes: number;
    worked_minutes: number | null;
    clock_in_distance: number | null;
    is_open: boolean;
    break_started_at: string | null;
    break_ended_at: string | null;
    break_minutes: number | null;
    on_break: boolean;
    has_taken_break: boolean;
};

type SelectableLocation = {
    id: number;
    name: string;
    address: string;
    city: string | null;
    work_starts_at: string;
    work_ends_at: string;
    radius_meters: number;
};

const props = defineProps<{
    clocksIn: boolean;
    selectableLocations: SelectableLocation[];
    location: {
        id: number;
        name: string;
        address: string;
        city: string | null;
        latitude: number | null;
        longitude: number | null;
        radius_meters: number;
        max_accuracy_meters: number;
        work_starts_at: string;
        work_ends_at: string;
        grace_minutes: number;
        break_minutes: number;
        timezone: string;
        is_active: boolean;
        configured: boolean;
        is_workday: boolean;
        server_time: string;
    } | null;
    today: Attendance | null;
    stats: {
        days_present: number;
        days_late: number;
        days_on_time: number;
        punctuality: number;
        total_hours: number;
        late_minutes: number;
        average_arrival: string | null;
    } | null;
    trend: Array<{
        date: string;
        label: string;
        offset: number | null;
        status: string | null;
        is_workday: boolean;
    }>;
    recent: Attendance[];
    lastAttempt: {
        result: string;
        label: string;
        message: string;
        distance_meters: number | null;
        created_at: string;
    } | null;
    leave: {
        balances: Array<{
            id: number;
            name: string;
            allowance: number | null;
            remaining: number | null;
        }>;
        next: {
            type: string;
            days: number;
            start_date: string;
            range_label: string;
            started: boolean;
        } | null;
        pending: number;
    } | null;
    overview: {
        active_staff: number;
        locations: number;
        clocked_in_today: number;
        late_today: number;
        on_leave_today: number;
        still_out: number;
        rejected_attempts_today: number;
        unassigned_staff: number;
        sites: Array<{
            id: number;
            name: string;
            city: string | null;
            headcount: number;
            clocked_in: number;
            late: number;
            rejected: number;
            work_starts_at: string;
            timezone: string;
            attendance_rate: number;
        }>;
    } | null;
}>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const { push } = useToasts();
const { state: geoState, error: geoError, acquire } = useGeoFix();

const busy = ref(false);
const outcome = ref<SharedProps['flash']['clock'] | null>(null);

// Approvers get a nudge only while something is actually waiting on them.
const waitingOnMe = computed(() => page.props.pending_approvals ?? 0);

// Claiming a site is a one-time action for staff who have none.
const siteForm = useForm({ location_id: null as number | null });

function claimSite() {
    siteForm.post('/work-location', { preserveScroll: true });
}

const canPunch = computed(
    () =>
        props.location !== null &&
        props.location.configured &&
        props.location.is_active,
);
const canClockIn = computed(() => !props.today?.clocked_in_at);
const canClockOut = computed(
    () => !!props.today?.clocked_in_at && !props.today?.clocked_out_at,
);

// Breaks only make sense mid-shift, and there is one a day.
const onBreak = computed(() => props.today?.on_break === true);
const canTakeBreak = computed(
    () =>
        (props.location?.break_minutes ?? 0) > 0 &&
        canClockOut.value &&
        !props.today?.has_taken_break,
);

const breakBusy = ref(false);

function breakPunch(action: 'start' | 'end') {
    if (breakBusy.value) {
        return;
    }

    breakBusy.value = true;

    router.post(
        `/break/${action}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (breakBusy.value = false),
        },
    );
}

const greeting = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: props.location?.timezone ?? undefined,
            hour: '2-digit',
            hour12: false,
        }).format(new Date()),
    );

    if (hour < 12) {
        return 'Good morning';
    }

    if (hour < 17) {
        return 'Good afternoon';
    }

    return 'Good evening';
});

async function punch(type: 'in' | 'out') {
    if (busy.value) {
        return;
    }

    busy.value = true;
    outcome.value = null;

    const fix = await acquire();

    router.post(
        `/clock/${type}`,
        fix
            ? {
                  latitude: fix.latitude,
                  longitude: fix.longitude,
                  accuracy: fix.accuracy,
              }
            : {},
        {
            preserveScroll: true,
            onFinish: () => (busy.value = false),
        },
    );
}

// The server decides the outcome of every punch; surface it verbatim.
watch(
    () => page.props.flash?.clock,
    (clock) => {
        if (!clock) {
            return;
        }

        outcome.value = clock;
        push({
            type: clock.ok ? 'success' : 'error',
            title: clock.ok ? 'Punch recorded' : clock.label,
            message: clock.message,
        });
    },
);

const statusPill = computed(() => {
    if (!props.today?.clocked_in_at) {
        return {
            tone: 'neutral' as const,
            text: 'Not clocked in',
            pulse: false,
        };
    }

    if (props.today.clocked_out_at) {
        return { tone: 'beacon' as const, text: 'Day closed', pulse: false };
    }

    if (props.today.on_break) {
        return { tone: 'beacon' as const, text: 'On break', pulse: true };
    }

    return props.today.status === 'late'
        ? {
              tone: 'brass' as const,
              text: 'On the clock · late arrival',
              pulse: true,
          }
        : { tone: 'signal' as const, text: 'On the clock', pulse: true };
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout
        :heading="clocksIn ? 'Dashboard' : 'Company overview'"
        :lede="`${greeting}, ${user?.name.split(' ')[0]}`"
    >
        <template #toolbar>
            <StatusPill
                v-if="clocksIn && location"
                :tone="statusPill.tone"
                dot
                :pulse="statusPill.pulse"
                class="hidden sm:inline-flex"
            >
                {{ statusPill.text }}
            </StatusPill>
        </template>

        <div class="space-y-5">
            <!-- One quiet line for approvers, and only when it is not zero. -->
            <Link
                v-if="waitingOnMe > 0"
                href="/approvals"
                class="flex items-center gap-3 rounded-2xl border border-line bg-panel px-5 py-3.5 shadow-panel transition-colors hover:border-faint"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand"
                >
                    <svg
                        class="size-[18px]"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path
                            d="M9 12.5 11 14.5 15.5 10M6 3.5h12A1.5 1.5 0 0 1 19.5 5v15.5l-3.5-2-4 2-4-2-3.5 2V5A1.5 1.5 0 0 1 6 3.5Z"
                        />
                    </svg>
                </span>
                <span class="min-w-0 flex-1 text-[13.5px]">
                    <span class="font-semibold">{{ waitingOnMe }}</span>
                    request{{ waitingOnMe === 1 ? '' : 's' }} waiting on your
                    decision
                </span>
                <span class="shrink-0 text-[12.5px] font-medium text-muted">
                    Review →
                </span>
            </Link>

            <!-- No site yet: claiming one is the only thing that matters. -->
            <Panel
                v-if="clocksIn && !location"
                eyebrow="One step left"
                title="Choose your work location"
                subtitle="Your clock-ins are checked against this site, so pick the one you actually report to."
            >
                <div v-if="selectableLocations.length" class="space-y-4">
                    <LocationSelect
                        v-model="siteForm.location_id"
                        :options="selectableLocations"
                        placeholder="Choose your site"
                        :error="siteForm.errors.location_id"
                    />

                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p class="text-[13px] text-faint">
                            Only an administrator can move you afterwards.
                        </p>
                        <AppButton
                            :loading="siteForm.processing"
                            :disabled="!siteForm.location_id"
                            @click="claimSite"
                        >
                            Set my work location
                        </AppButton>
                    </div>
                </div>

                <EmptyState
                    v-else
                    title="No sites available yet"
                    message="Your administrator has not opened a location for staff yet. Once they do, it will appear here and you can start clocking in."
                />
            </Panel>

            <div
                v-else-if="clocksIn && location && !location.configured"
                class="flex flex-wrap items-center gap-3 rounded-2xl border border-brass/40 bg-brass-soft px-4 py-3.5"
            >
                <svg
                    class="size-5 shrink-0 text-brass"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                >
                    <path d="M12 8.5v4.5M12 16.5h.01" />
                    <circle cx="12" cy="12" r="8.5" />
                </svg>
                <p class="min-w-0 flex-1 text-[13px] text-brass">
                    {{ location.name }} has no pin on the map yet, so punches
                    cannot be checked against it.
                </p>
                <Link v-if="user?.is_super_admin" href="/admin/locations">
                    <AppButton size="sm" variant="secondary">
                        Set the pin
                    </AppButton>
                </Link>
            </div>

            <!-- Hero: the punch dial and the act of punching. Admins run the
                 clock rather than punch it, so they never see this. -->
            <div
                v-if="clocksIn && stats"
                class="grid gap-5 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]"
            >
                <Panel
                    v-if="location"
                    eyebrow="Today"
                    :title="location.name"
                    :subtitle="
                        location.city
                            ? `${location.address}, ${location.city}`
                            : location.address
                    "
                >
                    <template #action>
                        <StatusPill
                            :tone="location.is_workday ? 'neutral' : 'beacon'"
                        >
                            {{ location.is_workday ? 'Workday' : 'Rest day' }}
                        </StatusPill>
                    </template>

                    <div class="flex flex-col items-center">
                        <PunchDial
                            :timezone="location.timezone"
                            :work-start="location.work_starts_at"
                            :work-end="location.work_ends_at"
                            :grace-minutes="location.grace_minutes"
                            :clocked-in-at="today?.clocked_in_at ?? null"
                            :clocked-out-at="today?.clocked_out_at ?? null"
                            :late-minutes="today?.late_minutes ?? 0"
                            :status="today?.status ?? null"
                            :busy="busy"
                            :locating="geoState === 'locating'"
                        />

                        <div class="mt-6 w-full space-y-3">
                            <AppButton
                                v-if="canClockIn"
                                size="lg"
                                block
                                :loading="busy"
                                :disabled="!canPunch"
                                @click="punch('in')"
                            >
                                <svg
                                    class="size-[18px]"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path
                                        d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"
                                    />
                                    <circle cx="12" cy="10" r="2.4" />
                                </svg>
                                {{
                                    busy ? 'Checking your location' : 'Clock in'
                                }}
                            </AppButton>

                            <template v-else-if="canClockOut">
                                <AppButton
                                    size="lg"
                                    block
                                    variant="secondary"
                                    :loading="busy"
                                    :disabled="onBreak"
                                    @click="punch('out')"
                                >
                                    {{
                                        busy
                                            ? 'Checking your location'
                                            : 'Clock out'
                                    }}
                                </AppButton>

                                <!-- One break a day, no geofence: they are
                                     already checked onto site. -->
                                <AppButton
                                    v-if="onBreak"
                                    block
                                    variant="primary"
                                    :loading="breakBusy"
                                    @click="breakPunch('end')"
                                >
                                    End break
                                </AppButton>
                                <AppButton
                                    v-else-if="canTakeBreak"
                                    block
                                    variant="ghost"
                                    :loading="breakBusy"
                                    @click="breakPunch('start')"
                                >
                                    Start break
                                    <span class="text-faint">
                                        · {{ location.break_minutes }}m
                                    </span>
                                </AppButton>

                                <p
                                    v-if="onBreak"
                                    class="rounded-xl border border-beacon/30 bg-beacon-soft px-3.5 py-3 text-center text-[12.5px] leading-snug text-beacon"
                                >
                                    On break since
                                    {{ timeOfDay(today?.break_started_at) }} ·
                                    {{ location.break_minutes }} minutes allowed
                                </p>
                                <p
                                    v-else-if="today?.has_taken_break"
                                    class="text-center text-[12px] text-faint"
                                >
                                    Break taken ·
                                    {{ duration(today.break_minutes) }}
                                    <span
                                        v-if="
                                            (today.break_minutes ?? 0) >
                                            location.break_minutes
                                        "
                                        class="text-brass"
                                    >
                                        ({{
                                            (today.break_minutes ?? 0) -
                                            location.break_minutes
                                        }}m over)
                                    </span>
                                </p>
                            </template>

                            <div
                                v-else
                                class="rounded-xl border border-line bg-sunken px-4 py-3.5 text-center"
                            >
                                <p class="text-[13px] font-medium">
                                    Today is done
                                </p>
                                <p
                                    class="tabular mt-0.5 font-mono text-[12px] text-muted"
                                >
                                    {{ timeOfDay(today?.clocked_in_at) }} →
                                    {{ timeOfDay(today?.clocked_out_at) }} ·
                                    {{ duration(today?.worked_minutes) }}
                                </p>
                                <p
                                    v-if="today?.break_minutes"
                                    class="mt-0.5 text-[12px] text-faint"
                                >
                                    {{ duration(today.break_minutes) }} break
                                    deducted
                                </p>
                            </div>

                            <Transition
                                enter-active-class="transition duration-300 ease-out"
                                enter-from-class="opacity-0 -translate-y-2"
                            >
                                <p
                                    v-if="outcome && !outcome.ok"
                                    class="animate-nudge rounded-xl border border-alert/30 bg-alert-soft px-3.5 py-3 text-[12.5px] leading-snug text-alert"
                                >
                                    {{ outcome.message }}
                                </p>
                            </Transition>

                            <p
                                v-if="geoError"
                                class="rounded-xl border border-brass/30 bg-brass-soft px-3.5 py-3 text-[12.5px] leading-snug text-brass"
                            >
                                {{ geoError }}
                            </p>

                            <p
                                class="text-center text-[11.5px] leading-relaxed text-faint"
                            >
                                You must be within
                                <span class="font-mono"
                                    >{{ location.radius_meters }}m</span
                                >
                                of {{ location.name }}. Every attempt is
                                recorded, including rejected ones.
                            </p>
                        </div>
                    </div>
                </Panel>

                <div class="space-y-5" :class="!location && 'lg:col-span-2'">
                    <div
                        class="stagger grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-2 xl:grid-cols-4"
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
                            :caption="`${stats.days_on_time} on time`"
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
                            :caption="
                                stats.average_arrival
                                    ? `Avg arrival ${stats.average_arrival}`
                                    : 'No arrivals yet'
                            "
                        />
                    </div>

                    <Panel
                        v-if="location"
                        eyebrow="Last 14 days"
                        title="Arrival pattern"
                        subtitle="Bars above the line are early arrivals, below are late."
                    >
                        <ArrivalStrip
                            :days="trend"
                            :work-start="location.work_starts_at"
                            :grace-minutes="location.grace_minutes"
                        />
                    </Panel>

                    <!-- Leave, kept to what is left and what is next. -->
                    <Panel v-if="leave" title="Leave">
                        <template #action>
                            <Link
                                href="/leave"
                                class="text-[12.5px] font-medium text-muted transition-colors hover:text-text"
                            >
                                Manage →
                            </Link>
                        </template>

                        <div class="space-y-4">
                            <div
                                v-if="leave.balances.length"
                                class="flex flex-wrap gap-x-6 gap-y-3"
                            >
                                <div
                                    v-for="balance in leave.balances"
                                    :key="balance.id"
                                    class="min-w-[92px]"
                                >
                                    <p
                                        class="font-display text-2xl font-semibold tracking-tight"
                                    >
                                        {{ balance.remaining ?? '—' }}
                                    </p>
                                    <p class="text-[12px] text-muted">
                                        {{ balance.name }}
                                        <span
                                            v-if="balance.allowance"
                                            class="text-faint"
                                        >
                                            of {{ balance.allowance }}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <p class="text-[13px] text-muted">
                                <template v-if="leave.next">
                                    {{ leave.next.started ? 'On' : 'Next' }}
                                    {{ leave.next.type.toLowerCase() }}:
                                    <span class="text-text">
                                        {{ leave.next.range_label }}
                                    </span>
                                    ({{ leave.next.days }} day{{
                                        leave.next.days === 1 ? '' : 's'
                                    }})
                                </template>
                                <template v-else>
                                    Nothing booked at the moment.
                                </template>
                                <template v-if="leave.pending > 0">
                                    ·
                                    <span class="text-brass">
                                        {{ leave.pending }} awaiting a decision
                                    </span>
                                </template>
                            </p>
                        </div>
                    </Panel>
                </div>
            </div>

            <!-- Company snapshot, super admins only. -->
            <Panel
                v-if="overview"
                eyebrow="Across the company"
                :title="`Today at ${overview.locations} ${overview.locations === 1 ? 'site' : 'sites'}`"
                subtitle="Administrators keep the clock, they do not punch it. These are your staff."
            >
                <template #action>
                    <div class="flex items-center gap-1">
                        <Link href="/admin/staff">
                            <AppButton size="sm" variant="ghost"
                                >Staff</AppButton
                            >
                        </Link>
                        <Link href="/admin/locations">
                            <AppButton size="sm" variant="secondary">
                                Manage locations
                            </AppButton>
                        </Link>
                    </div>
                </template>

                <div
                    class="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                >
                    <StatTile
                        label="Active staff"
                        :value="overview.active_staff"
                    />
                    <StatTile
                        label="Clocked in"
                        :value="overview.clocked_in_today"
                        tone="signal"
                    />
                    <StatTile
                        label="Late today"
                        :value="overview.late_today"
                        :tone="overview.late_today > 0 ? 'brass' : 'default'"
                    />
                    <StatTile
                        label="On leave"
                        :value="overview.on_leave_today"
                        caption="Approved for today"
                    />
                    <StatTile
                        label="Yet to arrive"
                        :value="overview.still_out"
                    />
                    <StatTile
                        label="Rejected punches"
                        :value="overview.rejected_attempts_today"
                        :tone="
                            overview.rejected_attempts_today > 0
                                ? 'alert'
                                : 'default'
                        "
                        caption="Out of range or no GPS"
                    />
                </div>

                <!-- Per site, because each one keeps its own working day. -->
                <div v-if="overview.sites.length" class="mt-5">
                    <p class="eyebrow mb-3">By location</p>
                    <ul class="divide-y divide-line-soft">
                        <li
                            v-for="site in overview.sites"
                            :key="site.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3"
                        >
                            <div class="min-w-[160px] flex-1">
                                <p class="truncate text-[13.5px] font-medium">
                                    {{ site.name }}
                                </p>
                                <p
                                    class="tabular truncate font-mono text-[11.5px] text-faint"
                                >
                                    {{ site.city ?? '-' }} · opens
                                    {{ site.work_starts_at }}
                                </p>
                            </div>

                            <!-- Attendance rate as a bar, not another number. -->
                            <div class="min-w-[140px] flex-1">
                                <div
                                    class="h-1.5 w-full overflow-hidden rounded-full bg-line"
                                >
                                    <div
                                        class="h-full rounded-full bg-signal transition-all duration-700 ease-out"
                                        :style="{
                                            width: `${site.attendance_rate}%`,
                                        }"
                                    />
                                </div>
                                <p
                                    class="tabular mt-1 font-mono text-[11px] text-faint"
                                >
                                    {{ site.clocked_in }}/{{ site.headcount }}
                                    in
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1.5">
                                <StatusPill v-if="site.late > 0" tone="brass">
                                    {{ site.late }} late
                                </StatusPill>
                                <StatusPill
                                    v-if="site.rejected > 0"
                                    tone="alert"
                                >
                                    {{ site.rejected }} rejected
                                </StatusPill>
                                <StatusPill
                                    v-if="
                                        site.late === 0 && site.rejected === 0
                                    "
                                    tone="signal"
                                >
                                    All clear
                                </StatusPill>
                            </div>
                        </li>
                    </ul>
                </div>

                <p
                    v-if="overview.unassigned_staff > 0"
                    class="mt-4 rounded-xl border border-brass/30 bg-brass-soft px-3.5 py-2.5 text-[12.5px] text-brass"
                >
                    {{ overview.unassigned_staff }} active
                    {{
                        overview.unassigned_staff === 1
                            ? 'person has'
                            : 'people have'
                    }}
                    no location and cannot clock in.
                    <Link href="/admin/staff?location=none" class="underline">
                        Assign them
                    </Link>
                </p>
            </Panel>

            <!-- Personal history -->
            <Panel
                v-if="clocksIn"
                eyebrow="Your record"
                title="Recent days"
                flush
            >
                <template #action>
                    <Link href="/attendance">
                        <AppButton size="sm" variant="ghost">See all</AppButton>
                    </Link>
                </template>

                <div v-if="recent.length" class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-2.5 font-medium">
                                    Date
                                </th>
                                <th class="eyebrow px-5 py-2.5 font-medium">
                                    In
                                </th>
                                <th class="eyebrow px-5 py-2.5 font-medium">
                                    Out
                                </th>
                                <th class="eyebrow px-5 py-2.5 font-medium">
                                    Worked
                                </th>
                                <th
                                    class="eyebrow px-5 py-2.5 text-right font-medium"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="record in recent"
                                :key="record.id"
                                class="transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3 text-[13px] font-medium">
                                    {{
                                        new Date(
                                            record.work_date,
                                        ).toLocaleDateString('en-GB', {
                                            weekday: 'short',
                                            day: 'numeric',
                                            month: 'short',
                                        })
                                    }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                >
                                    {{ timeOfDay(record.clocked_in_at) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                >
                                    {{
                                        record.clocked_out_at
                                            ? timeOfDay(record.clocked_out_at)
                                            : '-'
                                    }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                >
                                    {{ duration(record.worked_minutes) }}
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
                                            v-if="record.status === 'late'"
                                        >
                                            +{{ duration(record.late_minutes) }}
                                        </template>
                                        <template v-else>On time</template>
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <EmptyState
                    v-else
                    title="No punches yet"
                    message="Once you clock in, your days will build up here with arrival times and hours worked."
                />
            </Panel>

            <p
                v-if="lastAttempt"
                class="flex flex-wrap items-center gap-2 text-[12px] text-faint"
            >
                <span class="eyebrow">Last rejected attempt</span>
                <span>{{ lastAttempt.label }}</span>
                <span v-if="lastAttempt.distance_meters" class="font-mono">
                    · {{ distance(lastAttempt.distance_meters) }} away
                </span>
            </p>
        </div>
    </AppLayout>
</template>
