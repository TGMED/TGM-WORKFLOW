<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    attendanceLabel,
    attendanceTone,
    dateTime,
    distance,
    duration,
    timeOfDay,
} from '@/lib/format';

const props = defineProps<{
    month: string;
    month_label: string;
    records: Array<{
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
        type: string;
        type_label: string;
        result: string;
        result_label: string;
        message: string | null;
        distance_meters: number | null;
        accuracy_meters: number | null;
        created_at: string;
    }>;
    summary: {
        days_present: number;
        days_late: number;
        days_excused: number;
        total_hours: number;
        late_minutes: number;
    };
    location: {
        name: string;
        address: string;
        work_starts_at: string;
        timezone: string;
    } | null;
}>();

const tab = ref<'records' | 'attempts'>('records');

function shiftMonth(step: number) {
    const [year, month] = props.month.split('-').map(Number);
    const target = new Date(year, month - 1 + step, 1);
    const value = `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}`;

    router.get('/attendance', { month: value }, { preserveState: false });
}

const isCurrentMonth = computed(() => {
    const now = new Date();

    return (
        props.month ===
        `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    );
});

const resultTone = (result: string) =>
    result === 'success'
        ? ('signal' as const)
        : result === 'out_of_range'
          ? ('alert' as const)
          : ('brass' as const);

const recordPages = usePaginated(() => props.records);
const attemptPages = usePaginated(() => props.attempts);
</script>

<template>
    <Head :title="`Attendance · ${month_label}`" />

    <AppLayout
        heading="My attendance"
        :lede="
            location
                ? `${location.name} · ${location.work_starts_at} start`
                : 'No work location assigned'
        "
    >
        <template #toolbar>
            <div
                class="flex items-center gap-1 rounded-xl border border-line bg-panel p-1"
            >
                <button
                    type="button"
                    aria-label="Previous month"
                    class="grid size-7 place-items-center rounded-lg text-muted transition-colors hover:bg-line-soft hover:text-text"
                    @click="shiftMonth(-1)"
                >
                    <svg
                        class="size-4"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                </button>
                <span
                    class="min-w-[104px] px-1 text-center text-[12.5px] font-medium whitespace-nowrap"
                >
                    {{ month_label }}
                </span>
                <button
                    type="button"
                    aria-label="Next month"
                    :disabled="isCurrentMonth"
                    class="grid size-7 place-items-center rounded-lg text-muted transition-colors hover:bg-line-soft hover:text-text disabled:pointer-events-none disabled:opacity-35"
                    @click="shiftMonth(1)"
                >
                    <svg
                        class="size-4"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>
            </div>
        </template>

        <div class="space-y-5">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="Days present"
                    :value="summary.days_present"
                    caption="This month"
                />
                <StatTile
                    label="Late arrivals"
                    :value="summary.days_late"
                    :tone="summary.days_late > 0 ? 'brass' : 'default'"
                    :caption="
                        summary.days_excused > 0
                            ? `${duration(summary.late_minutes)} total, ${summary.days_excused} excused`
                            : duration(summary.late_minutes) + ' total'
                    "
                />
                <StatTile
                    label="Hours worked"
                    :value="summary.total_hours"
                    :decimals="1"
                    suffix="h"
                />
                <StatTile
                    label="Punctuality"
                    :value="
                        summary.days_present
                            ? Math.round(
                                  ((summary.days_present - summary.days_late) /
                                      summary.days_present) *
                                      100,
                              )
                            : 100
                    "
                    suffix="%"
                    tone="signal"
                />
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 rounded-xl border border-line bg-panel p-1">
                <button
                    v-for="option in [
                        { key: 'records', label: `Days (${records.length})` },
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
                    @click="tab = option.key as 'records' | 'attempts'"
                >
                    {{ option.label }}
                </button>
            </div>

            <Panel v-if="tab === 'records'" flush>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[780px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Day
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Clock in
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Clock out
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Worked
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Site
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    From site
                                </th>
                                <th
                                    class="eyebrow px-5 py-3 text-right font-medium"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="!records.length">
                                <td colspan="7">
                                    <EmptyState
                                        :title="`Nothing recorded in ${month_label}`"
                                        message="Days you clock in will appear here with arrival time, hours worked and how far you were from the office."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="record in recordPages.paged"
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
                                <td class="px-5 py-3 text-[12.5px] text-muted">
                                    {{ record.location_name ?? '-' }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px] text-faint"
                                >
                                    {{ distance(record.clock_in_distance) }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <StatusPill
                                        :tone="
                                            record.excused
                                                ? 'neutral'
                                                : attendanceTone(record.status)
                                        "
                                        :title="
                                            record.excused
                                                ? 'Your explanation was approved, so this day does not count as lateness'
                                                : undefined
                                        "
                                    >
                                        <template v-if="record.excused">
                                            Excused
                                        </template>
                                        <template
                                            v-else-if="record.status === 'late'"
                                        >
                                            +{{ duration(record.late_minutes) }}
                                            late
                                        </template>
                                        <template v-else>
                                            {{ attendanceLabel(record.status) }}
                                        </template>
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="recordPages.page"
                    v-model:per-page="recordPages.perPage"
                    :last-page="recordPages.lastPage"
                    :from="recordPages.from"
                    :to="recordPages.to"
                    :total="recordPages.total"
                />
            </Panel>

            <!-- Every trial, in order. -->
            <Panel v-else flush>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Result
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Attempt
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Detail
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    When
                                </th>
                                <th
                                    class="eyebrow px-5 py-3 text-right font-medium"
                                >
                                    Distance
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="!attempts.length">
                                <td colspan="5">
                                    <EmptyState
                                        title="No punch attempts logged"
                                        message="Every clock-in and clock-out you try is written here, whether it was accepted or turned away."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="attempt in attemptPages.paged"
                                :key="attempt.id"
                                class="transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3">
                                    <StatusPill
                                        :tone="resultTone(attempt.result)"
                                    >
                                        {{ attempt.result_label }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3 text-[13px] font-medium">
                                    {{ attempt.type_label }}
                                </td>
                                <td
                                    class="px-5 py-3 text-[12.5px] leading-snug text-muted"
                                >
                                    {{ attempt.message ?? '-' }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(attempt.created_at) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 text-right font-mono text-[11.5px] whitespace-nowrap text-faint"
                                >
                                    <template
                                        v-if="attempt.distance_meters !== null"
                                    >
                                        {{ distance(attempt.distance_meters) }}
                                        out
                                    </template>
                                    <template
                                        v-if="attempt.accuracy_meters !== null"
                                    >
                                        · ±{{ attempt.accuracy_meters }}m
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="attemptPages.page"
                    v-model:per-page="attemptPages.perPage"
                    :last-page="attemptPages.lastPage"
                    :from="attemptPages.from"
                    :to="attemptPages.to"
                    :total="attemptPages.total"
                />
            </Panel>

            <p class="text-center text-[12px] text-faint">
                Something look wrong?
                <Link href="/dashboard" class="text-beacon hover:opacity-75">
                    Check today's status
                </Link>
                or raise it with your administrator.
            </p>
        </div>
    </AppLayout>
</template>
