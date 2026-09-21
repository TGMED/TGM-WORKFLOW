<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
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

            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
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
                <StatTile
                    label="No department"
                    :value="headline.unassigned_department"
                    :tone="
                        headline.unassigned_department > 0 ? 'brass' : 'default'
                    "
                    caption="Nobody sees their numbers"
                />
            </div>
        </section>

        <!-- 2. Timekeeping. Turnout by day is on the admin console. -->
        <div class="grid gap-5 xl:grid-cols-2">
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

        <!-- 4. Movement, and probation. -->
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

        <!-- 5. Behind their own permissions. -->
        <div
            v-if="metrics.payroll || metrics.incidents"
            class="grid gap-5 xl:grid-cols-2"
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
        </div>
    </div>
</template>
