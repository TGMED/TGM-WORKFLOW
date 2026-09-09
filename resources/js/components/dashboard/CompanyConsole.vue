<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import TurnoutBars from '@/components/dashboard/TurnoutBars.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import type { CompanyMetrics } from '@/types';

const props = defineProps<{ metrics: CompanyMetrics }>();

const headline = computed(() => props.metrics.headline);

/** Departments worth acting on first: worst turnout at the top. */
const departments = computed(() =>
    [...props.metrics.departments].sort((a, b) => a.turnout - b.turnout),
);

const peakMovement = computed(() =>
    Math.max(
        1,
        ...props.metrics.movement.months.map((m) => Math.max(m.joined, m.left)),
    ),
);

const punctualityShift = computed(() => {
    const { this_month: now, last_month: before } = props.metrics.attendance;

    if (now === null || before === null) {
        return null;
    }

    return now - before;
});

const money = new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 0,
});
</script>

<template>
    <div class="space-y-6">
        <!-- 1. Headline. -->
        <section class="space-y-3">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">Across the company</p>
                    <h2
                        class="mt-1 font-display text-[19px] font-semibold tracking-tight"
                    >
                        Where things stand
                    </h2>
                </div>
                <Link href="/admin">
                    <AppButton size="sm" variant="secondary">
                        Admin console
                    </AppButton>
                </Link>
            </div>

            <div
                class="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
            >
                <StatTile label="Active staff" :value="headline.active_staff" />
                <StatTile
                    label="In today"
                    :value="headline.clocked_in_today"
                    :caption="`of ${headline.active_staff} on the books`"
                />
                <StatTile
                    label="Late today"
                    :value="headline.late_today"
                    :tone="headline.late_today > 0 ? 'brass' : 'default'"
                />
                <StatTile
                    label="On leave"
                    :value="headline.on_leave_today"
                    caption="Today"
                />
                <StatTile
                    label="Open requests"
                    :value="headline.pending_requests"
                    :tone="headline.pending_requests > 0 ? 'brass' : 'default'"
                    caption="Waiting on somebody"
                />
                <StatTile
                    label="Punctuality"
                    :value="
                        headline.punctuality_this_month === null
                            ? '-'
                            : `${headline.punctuality_this_month}%`
                    "
                    :caption="metrics.month_label"
                />
                <StatTile
                    label="Joined"
                    :value="headline.joined_this_month"
                    caption="This month"
                />
                <StatTile
                    label="Left"
                    :value="headline.left_this_month"
                    caption="This month"
                />
                <StatTile label="On probation" :value="headline.on_probation" />
                <StatTile
                    label="No site"
                    :value="headline.unassigned_site"
                    :tone="headline.unassigned_site > 0 ? 'brass' : 'default'"
                    caption="Cannot clock in"
                />
                <StatTile
                    label="No department"
                    :value="headline.unassigned_department"
                    :tone="
                        headline.unassigned_department > 0 ? 'brass' : 'default'
                    "
                    caption="Nobody sees their numbers"
                />
                <StatTile
                    label="Clock-ins refused"
                    :value="headline.rejected_attempts_today"
                    caption="Today, out of geofence"
                />
            </div>
        </section>

        <!-- 2. Attendance over time. -->
        <div class="grid gap-5 xl:grid-cols-3">
            <Panel
                class="xl:col-span-2"
                title="Turnout"
                subtitle="The last thirty days, on time against late"
            >
                <TurnoutBars
                    :days="metrics.attendance.days"
                    :height="170"
                    :label-every="3"
                />
            </Panel>

            <Panel title="Timekeeping" :subtitle="metrics.month_label">
                <div class="space-y-4">
                    <div>
                        <p class="eyebrow">Punctuality</p>
                        <p
                            class="tabular mt-1 font-mono text-[30px] leading-none font-semibold"
                        >
                            {{
                                metrics.attendance.this_month === null
                                    ? '-'
                                    : `${metrics.attendance.this_month}%`
                            }}
                        </p>
                        <p
                            v-if="punctualityShift !== null"
                            class="mt-1.5 text-[12.5px]"
                            :class="
                                punctualityShift >= 0
                                    ? 'text-signal'
                                    : 'text-brass'
                            "
                        >
                            {{ punctualityShift >= 0 ? '▲' : '▼' }}
                            {{ Math.abs(punctualityShift) }} points on last
                            month
                        </p>
                        <p v-else class="mt-1.5 text-[12.5px] text-faint">
                            No month to compare against yet.
                        </p>
                    </div>

                    <div class="h-px bg-line-soft" />

                    <div>
                        <p class="eyebrow">Average arrival</p>
                        <p
                            class="tabular mt-1 font-mono text-[22px] leading-none font-semibold"
                        >
                            {{ metrics.attendance.average_arrival ?? '-' }}
                        </p>
                    </div>

                    <template v-if="metrics.attendance.worst_days.length">
                        <div class="h-px bg-line-soft" />
                        <div>
                            <p class="eyebrow">Worst days</p>
                            <div class="mt-2 space-y-1.5">
                                <div
                                    v-for="day in metrics.attendance.worst_days"
                                    :key="day.date"
                                    class="flex items-center justify-between text-[12.5px]"
                                >
                                    <span class="text-muted">{{
                                        day.label
                                    }}</span>
                                    <span class="tabular text-brass">
                                        {{ day.punctuality }}% · {{ day.late }}
                                        late
                                    </span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </Panel>
        </div>

        <!-- 3. Departments. The panel this whole thing is for. -->
        <Panel
            title="Departments"
            subtitle="Worst turnout first. Every column is this month unless it says today."
            flush
        >
            <template #action>
                <Link href="/admin/departments">
                    <AppButton size="sm" variant="ghost">Manage</AppButton>
                </Link>
            </template>

            <div v-if="departments.length" class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left">
                    <thead>
                        <tr class="border-b border-line-soft">
                            <th
                                class="px-5 py-2.5 text-[12px] font-medium text-faint"
                            >
                                Department
                            </th>
                            <th
                                class="px-3 py-2.5 text-[12px] font-medium text-faint"
                            >
                                Head
                            </th>
                            <th
                                class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                            >
                                People
                            </th>
                            <th
                                class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                            >
                                In today
                            </th>
                            <th
                                class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                            >
                                Turnout
                            </th>
                            <th
                                class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                            >
                                Punctuality
                            </th>
                            <th
                                class="px-5 py-2.5 text-right text-[12px] font-medium text-faint"
                            >
                                Open
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <tr
                            v-for="department in departments"
                            :key="department.id"
                            class="transition-colors hover:bg-panel-raised"
                        >
                            <td class="px-5 py-3">
                                <p class="text-[13.5px] font-medium">
                                    {{ department.name }}
                                </p>
                                <p class="text-[12px] text-faint">
                                    {{ department.teams }}
                                    {{
                                        department.teams === 1
                                            ? 'team'
                                            : 'teams'
                                    }}
                                </p>
                            </td>
                            <td class="px-3 py-3 text-[13px]">
                                <span v-if="department.head" class="text-muted">
                                    {{ department.head }}
                                </span>
                                <StatusPill v-else tone="brass">
                                    None named
                                </StatusPill>
                            </td>
                            <td
                                class="tabular px-3 py-3 text-right text-[13px]"
                            >
                                {{ department.headcount }}
                            </td>
                            <td
                                class="tabular px-3 py-3 text-right text-[13px]"
                            >
                                {{ department.clocked_in_today }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <span
                                        class="h-1.5 w-16 overflow-hidden rounded-full bg-line-soft"
                                    >
                                        <span
                                            class="block h-full rounded-full"
                                            :class="
                                                department.turnout >= 70
                                                    ? 'bg-signal'
                                                    : 'bg-brass'
                                            "
                                            :style="{
                                                width: `${department.turnout}%`,
                                            }"
                                        />
                                    </span>
                                    <span
                                        class="tabular w-9 text-right text-[13px]"
                                    >
                                        {{ department.turnout }}%
                                    </span>
                                </div>
                            </td>
                            <td
                                class="tabular px-3 py-3 text-right text-[13px]"
                            >
                                {{
                                    department.punctuality === null
                                        ? '-'
                                        : `${department.punctuality}%`
                                }}
                            </td>
                            <td
                                class="tabular px-5 py-3 text-right text-[13px]"
                            >
                                {{ department.open_requests || '-' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-5 py-6 text-[13px] text-faint">
                No departments yet.
                <Link href="/admin/departments" class="text-beacon underline">
                    Create one
                </Link>
                and the company's numbers break down by team.
            </p>
        </Panel>

        <!-- 4 and 7. Sites, and the request funnel. -->
        <div class="grid gap-5 xl:grid-cols-2">
            <Panel
                title="Sites"
                subtitle="Turnout today, each in its own day"
                flush
            >
                <div class="divide-y divide-line-soft">
                    <div
                        v-for="site in metrics.sites"
                        :key="site.id"
                        class="flex items-center justify-between gap-3 px-5 py-3.5"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px] font-medium">
                                {{ site.name }}
                            </p>
                            <p class="text-[12px] text-faint">
                                {{ site.clocked_in }} of {{ site.headcount }} in
                                <template v-if="site.late">
                                    · {{ site.late }} late
                                </template>
                            </p>
                        </div>
                        <span class="tabular shrink-0 text-[13px] text-muted">
                            {{ site.turnout }}%
                        </span>
                    </div>
                    <p
                        v-if="!metrics.sites.length"
                        class="px-5 py-6 text-[13px] text-faint"
                    >
                        No active sites.
                    </p>
                </div>
            </Panel>

            <Panel title="Requests" :subtitle="metrics.month_label">
                <div class="space-y-5">
                    <div
                        v-for="(counts, module) in {
                            Leave: metrics.requests.leave,
                            Lateness: metrics.requests.lateness,
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
                            <span
                                class="bg-signal"
                                :style="{
                                    width: `${counts.total ? (counts.approved / counts.total) * 100 : 0}%`,
                                }"
                            />
                            <span
                                class="bg-alert/70"
                                :style="{
                                    width: `${counts.total ? (counts.rejected / counts.total) * 100 : 0}%`,
                                }"
                            />
                            <span
                                class="bg-brass/70"
                                :style="{
                                    width: `${counts.total ? (counts.pending / counts.total) * 100 : 0}%`,
                                }"
                            />
                        </div>
                        <p class="mt-1.5 text-[12px] text-faint">
                            {{ counts.approved }} approved ·
                            {{ counts.rejected }} rejected ·
                            {{ counts.pending }} still open
                        </p>
                    </div>

                    <div class="h-px bg-line-soft" />

                    <p class="text-[13px] text-muted">
                        <template
                            v-if="
                                metrics.requests.median_decision_days !== null
                            "
                        >
                            Half of all decisions land within
                            <span class="tabular font-medium text-text">
                                {{ metrics.requests.median_decision_days }}
                            </span>
                            days.
                        </template>
                        <template v-else>
                            Nothing has been decided this month yet.
                        </template>
                    </p>
                </div>
            </Panel>
        </div>

        <!-- 5 and 6. Movement, and probation. -->
        <div class="grid gap-5 xl:grid-cols-2">
            <Panel
                title="Headcount"
                subtitle="Joiners against leavers, twelve months"
            >
                <div class="flex h-40 items-end gap-1">
                    <div
                        v-for="month in metrics.movement.months"
                        :key="month.month"
                        class="flex flex-1 flex-col items-center gap-1"
                    >
                        <div
                            class="flex h-32 w-full items-end justify-center gap-0.5"
                        >
                            <span
                                class="w-2 rounded-t bg-signal/70"
                                :style="{
                                    height: `${(month.joined / peakMovement) * 100}%`,
                                }"
                                :title="`${month.joined} joined`"
                            />
                            <span
                                class="w-2 rounded-t bg-alert/60"
                                :style="{
                                    height: `${(month.left / peakMovement) * 100}%`,
                                }"
                                :title="`${month.left} left`"
                            />
                        </div>
                        <span class="text-[10px] whitespace-nowrap text-faint">
                            {{ month.label }}
                        </span>
                    </div>
                </div>

                <div
                    class="mt-3 flex items-center gap-4 text-[12px] text-muted"
                >
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-sm bg-signal/70" />
                        Joined
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-sm bg-alert/60" />
                        Left
                    </span>
                </div>

                <div
                    v-if="metrics.movement.reasons.length"
                    class="mt-4 border-t border-line-soft pt-3"
                >
                    <p class="eyebrow">Why people went</p>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                        <span
                            v-for="reason in metrics.movement.reasons"
                            :key="reason.value"
                            class="text-[12.5px] text-muted"
                        >
                            {{ reason.label }}
                            <span class="tabular text-faint">
                                {{ reason.total }}
                            </span>
                        </span>
                    </div>
                </div>
            </Panel>

            <Panel
                title="Probation"
                :subtitle="`${metrics.probation.total_on_probation} on probation · confirmation due after ${metrics.probation.months} months`"
                flush
            >
                <div
                    v-if="
                        metrics.probation.overdue.length ||
                        metrics.probation.due_soon.length
                    "
                    class="divide-y divide-line-soft"
                >
                    <div
                        v-for="person in [
                            ...metrics.probation.overdue,
                            ...metrics.probation.due_soon,
                        ]"
                        :key="person.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px] font-medium">
                                {{ person.name }}
                            </p>
                            <p class="truncate text-[12px] text-faint">
                                {{ person.department ?? 'No department' }} · due
                                {{ person.due_label }}
                            </p>
                        </div>
                        <StatusPill
                            :tone="person.overdue ? 'alert' : 'brass'"
                            class="shrink-0"
                        >
                            {{
                                person.overdue
                                    ? `${Math.abs(person.days_until)}d over`
                                    : `${person.days_until}d`
                            }}
                        </StatusPill>
                    </div>
                </div>
                <p v-else class="px-5 py-6 text-[13px] text-faint">
                    Nobody is coming up for confirmation in the next sixty days.
                </p>
            </Panel>
        </div>

        <!-- 8 and 9. Leave owed, and timekeeping. -->
        <div class="grid gap-5 xl:grid-cols-2">
            <Panel
                title="Leave owed"
                subtitle="Days the company still owes this year"
                flush
            >
                <div
                    v-if="metrics.leave_liability.length"
                    class="divide-y divide-line-soft"
                >
                    <div
                        v-for="type in metrics.leave_liability"
                        :key="type.id"
                        class="px-5 py-3.5"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-[13.5px] font-medium">
                                {{ type.name }}
                            </span>
                            <span class="tabular text-[12.5px] text-faint">
                                {{ type.outstanding }} of
                                {{ type.entitled }} left
                            </span>
                        </div>
                        <span
                            class="mt-2 block h-1.5 overflow-hidden rounded-full bg-line-soft"
                        >
                            <span
                                class="block h-full rounded-full bg-beacon/70"
                                :style="{ width: `${type.used_percent}%` }"
                            />
                        </span>
                    </div>
                </div>
                <p v-else class="px-5 py-6 text-[13px] text-faint">
                    No capped leave types to measure against.
                </p>
            </Panel>

            <Panel
                title="Timekeeping"
                :subtitle="`${metrics.month_label}. Most minutes lost first.`"
                flush
            >
                <div
                    v-if="metrics.punctuality.worst.length"
                    class="divide-y divide-line-soft"
                >
                    <div
                        v-for="person in metrics.punctuality.worst"
                        :key="person.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-[13.5px] font-medium">
                                {{ person.name }}
                            </p>
                            <p class="truncate text-[12px] text-faint">
                                {{ person.department ?? 'No department' }} ·
                                {{ person.days_late }} of
                                {{ person.days_present }} days
                            </p>
                        </div>
                        <StatusPill tone="brass" class="shrink-0">
                            {{ person.late_minutes }}m
                        </StatusPill>
                    </div>
                </div>
                <p v-else class="px-5 py-6 text-[13px] text-faint">
                    Nobody has lost a minute this month.
                </p>
            </Panel>
        </div>

        <!-- 10, 11 and 12. Behind their own permissions. -->
        <div
            v-if="metrics.payroll || metrics.incidents || metrics.access"
            class="grid gap-5 xl:grid-cols-3"
        >
            <Panel
                v-if="metrics.payroll"
                title="Payroll"
                subtitle="The last month signed off"
            >
                <template v-if="metrics.payroll.last_run">
                    <p class="eyebrow">{{ metrics.payroll.last_run.label }}</p>
                    <p
                        class="tabular mt-1 font-mono text-[24px] leading-none font-semibold"
                    >
                        {{ money.format(metrics.payroll.last_run.net) }}
                    </p>
                    <p class="mt-1.5 text-[12.5px] text-faint">
                        net to {{ metrics.payroll.last_run.headcount }} people ·
                        {{ money.format(metrics.payroll.last_run.gross) }} gross
                    </p>
                    <p
                        v-if="metrics.payroll.previous"
                        class="mt-3 border-t border-line-soft pt-3 text-[12.5px] text-muted"
                    >
                        {{ metrics.payroll.previous.label }}:
                        {{ money.format(metrics.payroll.previous.net) }} net
                    </p>
                    <p
                        v-if="metrics.payroll.open_runs"
                        class="mt-2 text-[12.5px] text-brass"
                    >
                        {{ metrics.payroll.open_runs }} run(s) not signed off.
                    </p>
                </template>
                <p v-else class="text-[13px] text-faint">
                    No payroll run has been signed off yet.
                </p>
            </Panel>

            <Panel
                v-if="metrics.incidents"
                title="Incidents"
                subtitle="What staff have raised"
            >
                <p class="eyebrow">Still open</p>
                <p
                    class="tabular mt-1 font-mono text-[24px] leading-none font-semibold"
                    :class="metrics.incidents.open > 0 && 'text-brass'"
                >
                    {{ metrics.incidents.open }}
                </p>
                <p
                    v-if="metrics.incidents.oldest_open_days !== null"
                    class="mt-1.5 text-[12.5px] text-faint"
                >
                    The oldest has been waiting
                    {{ metrics.incidents.oldest_open_days }} days.
                </p>

                <div class="mt-3 space-y-1.5 border-t border-line-soft pt-3">
                    <div
                        v-for="status in metrics.incidents.by_status"
                        :key="status.value"
                        class="flex items-center justify-between text-[12.5px]"
                    >
                        <span class="text-muted">{{ status.label }}</span>
                        <span class="tabular text-faint">
                            {{ status.total }}
                        </span>
                    </div>
                </div>
            </Panel>

            <Panel
                v-if="metrics.access"
                title="Access"
                subtitle="Somebody with two roles is counted under both"
                flush
            >
                <div class="divide-y divide-line-soft">
                    <Link
                        v-for="role in metrics.access.roles"
                        :key="role.id"
                        href="/admin/roles"
                        class="flex items-center justify-between gap-3 px-5 py-2.5 transition-colors hover:bg-panel-raised"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-[13px] font-medium">
                                {{ role.name }}
                            </p>
                            <p class="text-[11.5px] text-faint">
                                <template v-if="role.holds_everything">
                                    Every permission
                                </template>
                                <template v-else>
                                    {{ role.permissions_count }} permission(s)
                                </template>
                            </p>
                        </div>
                        <span
                            class="tabular shrink-0 text-[13px]"
                            :class="
                                role.held_by_nobody
                                    ? 'text-faint'
                                    : 'text-muted'
                            "
                        >
                            {{ role.users_count }}
                        </span>
                    </Link>
                </div>

                <div class="border-t border-line-soft px-5 py-3">
                    <p class="eyebrow">Confidential access</p>
                    <p class="mt-1.5 text-[12.5px] text-muted">
                        Reports desk:
                        {{
                            metrics.access.sensitive.reports.join(', ') ||
                            'nobody'
                        }}
                    </p>
                    <p class="mt-1 text-[12.5px] text-muted">
                        Payroll:
                        {{
                            metrics.access.sensitive.payroll.join(', ') ||
                            'nobody'
                        }}
                    </p>
                </div>
            </Panel>
        </div>

        <!-- 13. What is stuck. -->
        <Panel
            v-if="metrics.requests.oldest_pending.length"
            title="Waiting longest"
            subtitle="The queue that needs chasing"
            flush
        >
            <template #action>
                <Link href="/approvals">
                    <AppButton size="sm" variant="ghost">Approvals</AppButton>
                </Link>
            </template>

            <div class="divide-y divide-line-soft">
                <div
                    v-for="row in metrics.requests.oldest_pending"
                    :key="row.id"
                    class="flex items-center justify-between gap-3 px-5 py-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-[13.5px] font-medium">
                            {{ row.staff }}
                        </p>
                        <p class="truncate text-[12px] text-faint">
                            {{ row.days }}d {{ row.type }} ·
                            {{ row.range_label }} · with
                            {{ row.with ?? 'any approver' }}
                        </p>
                    </div>
                    <StatusPill
                        :tone="row.waiting_days >= 3 ? 'alert' : 'brass'"
                        class="shrink-0"
                    >
                        {{ row.waiting_days }}d
                    </StatusPill>
                </div>
            </div>
        </Panel>
    </div>
</template>
