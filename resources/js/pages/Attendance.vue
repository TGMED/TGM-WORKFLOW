<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, distance, duration, timeOfDay } from '@/lib/format';

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
                    :caption="duration(summary.late_minutes) + ' total'"
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
                <div v-if="records.length" class="overflow-x-auto">
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
                            <tr
                                v-for="record in records"
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
                                            record.status === 'late'
                                                ? 'brass'
                                                : 'signal'
                                        "
                                    >
                                        <template
                                            v-if="record.status === 'late'"
                                        >
                                            +{{ duration(record.late_minutes) }}
                                            late
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
                    :title="`Nothing recorded in ${month_label}`"
                    message="Days you clock in will appear here with arrival time, hours worked and how far you were from the office."
                />
            </Panel>

            <!-- Every trial, in order. -->
            <Panel v-else flush>
                <ul v-if="attempts.length" class="divide-y divide-line-soft">
                    <li
                        v-for="attempt in attempts"
                        :key="attempt.id"
                        class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-3.5 transition-colors hover:bg-line-soft/40"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusPill :tone="resultTone(attempt.result)">
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
                        </div>

                        <div class="text-right">
                            <p class="tabular font-mono text-[12px] text-muted">
                                {{ dateTime(attempt.created_at) }}
                            </p>
                            <p
                                class="tabular mt-0.5 font-mono text-[11px] text-faint"
                            >
                                <template
                                    v-if="attempt.distance_meters !== null"
                                >
                                    {{ distance(attempt.distance_meters) }} out
                                </template>
                                <template
                                    v-if="attempt.accuracy_meters !== null"
                                >
                                    · ±{{ attempt.accuracy_meters }}m
                                </template>
                            </p>
                        </div>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    title="No punch attempts logged"
                    message="Every clock-in and clock-out you try is written here, whether it was accepted or turned away."
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
