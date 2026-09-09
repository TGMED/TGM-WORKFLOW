<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type ModuleCounts = {
    pending: number;
    approved: number;
    rejected: number;
    total: number;
};

type ActivityRow = {
    id: number;
    event_label: string;
    event_tone: 'signal' | 'brass' | 'alert' | 'neutral';
    type_label: string;
    subject: string;
    actor: string | null;
    created_at: string | null;
};

const props = defineProps<{
    headline: {
        active_staff: number;
        on_probation: number;
        unassigned: number;
        sites: number;
        clocked_in_today: number;
        late_today: number;
        on_leave_today: number;
        pending_requests: number;
        rejected_attempts_today: number;
    };
    sites: Array<{
        id: number;
        name: string;
        city: string | null;
        headcount: number;
        clocked_in: number;
        late: number;
        turnout: number;
    }>;
    attendance_trend: Array<{
        date: string;
        label: string;
        present: number;
        late: number;
        on_time: number;
    }>;
    leave_liability: Array<{
        id: number;
        name: string;
        entitled: number;
        taken: number;
        outstanding: number;
        used_percent: number;
    }>;
    requests: {
        leave: ModuleCounts;
        lateness: ModuleCounts;
        month_label: string;
    };
    roles: Array<{
        id: number;
        name: string;
        users_count: number;
        permissions_count: number;
        holds_everything: boolean;
    }>;
    oldest_pending: Array<{
        id: number;
        staff: string;
        type: string;
        days: number;
        range_label: string;
        with: string | null;
        waiting_days: number;
    }>;
    /** Null for somebody who may not see the trail. */
    recent_activity: ActivityRow[] | null;
}>();

// The trend bars are drawn against the busiest day in the window, so a quiet
// fortnight does not render as a flat line.
const peak = computed(() =>
    Math.max(1, ...props.attendance_trend.map((day) => day.present)),
);

const turnout = computed(() =>
    props.headline.active_staff > 0
        ? Math.round(
              (props.headline.clocked_in_today / props.headline.active_staff) *
                  100,
          )
        : 0,
);
</script>

<template>
    <Head title="Admin console" />

    <AppLayout
        heading="Admin console"
        lede="Headcount, attendance and what is waiting on somebody"
    >
        <div class="space-y-5">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="Active staff"
                    :value="headline.active_staff"
                    :caption="`${headline.sites} site(s)`"
                />
                <StatTile
                    label="In today"
                    :value="headline.clocked_in_today"
                    :caption="`${turnout}% turnout`"
                    tone="signal"
                />
                <StatTile
                    label="Late today"
                    :value="headline.late_today"
                    tone="brass"
                    caption="Feeds the lateness ladder"
                />
                <StatTile
                    label="Awaiting a decision"
                    :value="headline.pending_requests"
                    tone="alert"
                    caption="Leave and lateness"
                />
            </div>

            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="On leave today"
                    :value="headline.on_leave_today"
                />
                <StatTile
                    label="On probation"
                    :value="headline.on_probation"
                    caption="Some leave types stay closed"
                />
                <StatTile
                    label="No site assigned"
                    :value="headline.unassigned"
                    :tone="headline.unassigned > 0 ? 'brass' : 'default'"
                    caption="Cannot clock in"
                />
                <StatTile
                    label="Clock-ins refused"
                    :value="headline.rejected_attempts_today"
                    caption="Today, out of geofence"
                />
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <Panel
                    class="xl:col-span-2"
                    title="Turnout"
                    subtitle="The last fortnight, on time against late"
                >
                    <div class="flex h-44 items-end gap-1.5">
                        <div
                            v-for="day in attendance_trend"
                            :key="day.date"
                            class="group flex flex-1 flex-col items-center gap-1.5"
                        >
                            <div
                                class="flex w-full flex-col justify-end"
                                :style="{ height: '150px' }"
                            >
                                <div
                                    class="w-full rounded-t bg-brass/70"
                                    :style="{
                                        height: `${(day.late / peak) * 100}%`,
                                    }"
                                    :title="`${day.late} late`"
                                />
                                <div
                                    class="w-full bg-signal/70"
                                    :class="day.late === 0 && 'rounded-t'"
                                    :style="{
                                        height: `${(day.on_time / peak) * 100}%`,
                                    }"
                                    :title="`${day.on_time} on time`"
                                />
                            </div>
                            <span
                                class="text-[10.5px] whitespace-nowrap text-faint"
                            >
                                {{ day.label }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-4 flex items-center gap-4 text-[12px] text-muted"
                    >
                        <span class="flex items-center gap-1.5">
                            <span class="size-2.5 rounded-sm bg-signal/70" />
                            On time
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="size-2.5 rounded-sm bg-brass/70" />
                            Late
                        </span>
                    </div>
                </Panel>

                <Panel title="Requests" :subtitle="requests.month_label">
                    <div class="space-y-5">
                        <div
                            v-for="(counts, module) in {
                                Leave: requests.leave,
                                Lateness: requests.lateness,
                            }"
                            :key="module"
                        >
                            <div class="flex items-baseline justify-between">
                                <span class="text-[13.5px] font-medium">
                                    {{ module }}
                                </span>
                                <span class="tabular text-[12.5px] text-faint">
                                    {{ counts.total }} raised
                                </span>
                            </div>

                            <div
                                class="mt-2 flex h-2 overflow-hidden rounded-full bg-line-soft"
                            >
                                <div
                                    v-for="bar in [
                                        {
                                            key: 'approved',
                                            n: counts.approved,
                                            css: 'bg-signal',
                                        },
                                        {
                                            key: 'pending',
                                            n: counts.pending,
                                            css: 'bg-brass',
                                        },
                                        {
                                            key: 'rejected',
                                            n: counts.rejected,
                                            css: 'bg-alert',
                                        },
                                    ]"
                                    :key="bar.key"
                                    :class="bar.css"
                                    :style="{
                                        width: `${counts.total > 0 ? (bar.n / counts.total) * 100 : 0}%`,
                                    }"
                                />
                            </div>

                            <p class="mt-1.5 text-[12px] text-faint">
                                {{ counts.approved }} approved ·
                                {{ counts.pending }} pending ·
                                {{ counts.rejected }} rejected
                            </p>
                        </div>
                    </div>
                </Panel>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <Panel
                    title="Sites"
                    subtitle="Turnout against headcount, each in its own working day"
                    flush
                >
                    <EmptyState
                        v-if="sites.length === 0"
                        title="No active sites"
                        message="Add a site before anyone can clock in."
                    />
                    <div v-else class="divide-y divide-line-soft">
                        <div
                            v-for="site in sites"
                            :key="site.id"
                            class="px-5 py-3.5"
                        >
                            <div class="flex items-baseline justify-between">
                                <span class="text-[13.5px] font-medium">
                                    {{ site.name }}
                                    <span
                                        v-if="site.city"
                                        class="text-[12px] text-faint"
                                    >
                                        · {{ site.city }}
                                    </span>
                                </span>
                                <span class="tabular text-[12.5px] text-muted">
                                    {{ site.clocked_in }}/{{ site.headcount }}
                                </span>
                            </div>
                            <div
                                class="mt-2 h-1.5 overflow-hidden rounded-full bg-line-soft"
                            >
                                <div
                                    class="h-full rounded-full bg-brand transition-all duration-500"
                                    :style="{ width: `${site.turnout}%` }"
                                />
                            </div>
                            <p
                                v-if="site.late > 0"
                                class="mt-1.5 text-[12px] text-brass"
                            >
                                {{ site.late }} arrived late
                            </p>
                        </div>
                    </div>
                </Panel>

                <Panel
                    title="Leave owed"
                    subtitle="Days still on the books this year, per capped type"
                    flush
                >
                    <EmptyState
                        v-if="leave_liability.length === 0"
                        title="Nothing capped"
                        message="No leave type carries a yearly allowance, so there is no liability to total."
                    />
                    <div v-else class="divide-y divide-line-soft">
                        <div
                            v-for="type in leave_liability"
                            :key="type.id"
                            class="px-5 py-3.5"
                        >
                            <div class="flex items-baseline justify-between">
                                <span class="text-[13.5px] font-medium">
                                    {{ type.name }}
                                </span>
                                <span class="tabular text-[12.5px] text-muted">
                                    {{ type.outstanding }} of
                                    {{ type.entitled }} working days left
                                </span>
                            </div>
                            <div
                                class="mt-2 h-1.5 overflow-hidden rounded-full bg-line-soft"
                            >
                                <div
                                    class="h-full rounded-full bg-signal transition-all duration-500"
                                    :style="{
                                        width: `${Math.min(100, type.used_percent)}%`,
                                    }"
                                />
                            </div>
                            <p class="mt-1.5 text-[12px] text-faint">
                                {{ type.taken }} taken or promised
                            </p>
                        </div>
                    </div>
                </Panel>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <Panel
                    title="Waiting longest"
                    subtitle="Leave nobody has ruled on yet"
                    flush
                >
                    <EmptyState
                        v-if="oldest_pending.length === 0"
                        title="Nothing outstanding"
                        message="Every leave request has been decided."
                    />
                    <div v-else class="divide-y divide-line-soft">
                        <div
                            v-for="row in oldest_pending"
                            :key="row.id"
                            class="flex items-center justify-between gap-3 px-5 py-3.5"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-medium">
                                    {{ row.staff }}
                                </p>
                                <p class="text-[12px] text-faint">
                                    {{ row.days }} working day(s) of
                                    {{ row.type.toLowerCase() }} ·
                                    {{ row.range_label }}
                                    <template v-if="row.with">
                                        · with {{ row.with }}
                                    </template>
                                </p>
                            </div>
                            <StatusPill
                                :tone="
                                    row.waiting_days >= 3 ? 'alert' : 'brass'
                                "
                            >
                                {{ row.waiting_days }}d
                            </StatusPill>
                        </div>
                    </div>
                </Panel>

                <Panel
                    title="Roles"
                    subtitle="Who holds what. Somebody holding two roles is counted under both."
                    flush
                >
                    <div class="divide-y divide-line-soft">
                        <Link
                            v-for="role in roles"
                            :key="role.id"
                            href="/admin/roles"
                            class="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-panel-raised"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-medium">
                                    {{ role.name }}
                                </p>
                                <p class="text-[12px] text-faint">
                                    <template v-if="role.holds_everything">
                                        Every permission
                                    </template>
                                    <template v-else>
                                        {{ role.permissions_count }}
                                        permission(s)
                                    </template>
                                </p>
                            </div>
                            <span class="tabular text-[13px] text-muted">
                                {{ role.users_count }}
                            </span>
                        </Link>
                    </div>
                </Panel>
            </div>

            <Panel
                v-if="recent_activity"
                title="Recent changes"
                subtitle="The last few entries in the audit trail"
                flush
            >
                <EmptyState
                    v-if="recent_activity.length === 0"
                    title="Nothing recorded yet"
                    message="Changes to staff, roles, sites and leave types will appear here."
                />
                <div v-else>
                    <div class="divide-y divide-line-soft">
                        <div
                            v-for="row in recent_activity"
                            :key="row.id"
                            class="flex flex-wrap items-center gap-2 px-5 py-3"
                        >
                            <StatusPill :tone="row.event_tone">
                                {{ row.event_label }}
                            </StatusPill>
                            <span class="text-[13px] font-medium">
                                {{ row.type_label }}
                            </span>
                            <span class="text-[13px] text-muted">
                                {{ row.subject }}
                            </span>
                            <span class="ml-auto text-[12px] text-faint">
                                {{ row.actor ?? 'System' }}
                                <template v-if="row.created_at">
                                    · {{ dateTime(row.created_at) }}
                                </template>
                            </span>
                        </div>
                    </div>

                    <div class="border-t border-line-soft px-5 py-3">
                        <Link
                            href="/admin/audit"
                            class="text-[12.5px] text-muted transition-colors hover:text-text"
                        >
                            See the full trail →
                        </Link>
                    </div>
                </div>
            </Panel>
        </div>
    </AppLayout>
</template>
