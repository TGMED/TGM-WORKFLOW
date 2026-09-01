<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { duration } from '@/lib/format';

type Row = {
    id: number;
    employee_id: string | null;
    name: string;
    initials: string;
    department: string | null;
    position: string | null;
    is_active: boolean;
    location: string | null;
    days_present: number;
    days_late: number;
    days_grace: number;
    days_expected: number | null;
    days_absent: number | null;
    late_minutes: number;
    worked_minutes: number;
    break_minutes: number;
    open_days: number;
    last_seen: string | null;
    punctuality: number | null;
};

const props = defineProps<{
    rows: {
        data: Row[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        from: string;
        to: string;
        search: string;
        department: string;
        location: string;
    };
    range_label: string;
    range_days: number;
    summary: {
        staff: number;
        days_present: number;
        days_late: number;
        days_grace: number;
        late_minutes: number;
        total_hours: number;
        punctuality: number;
    };
    departments: string[];
    locations: Array<{ value: string; label: string }>;
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const search = ref(props.filters.search);
const department = ref(props.filters.department);
const site = ref(props.filters.location);

let debounce: number | undefined;

function applyFilters(immediate = false) {
    window.clearTimeout(debounce);

    const run = () =>
        router.get(
            '/admin/attendance',
            {
                from: from.value,
                to: to.value,
                search: search.value || undefined,
                department: department.value || undefined,
                location: site.value || undefined,
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
watch([from, to, department, site], () => applyFilters(true));

const iso = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/**
 * Presets are written into the same two date fields rather than being a mode
 * of their own, so the range shown is always the range queried.
 */
const presets = computed(() => {
    const today = new Date();
    const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastMonthStart = new Date(
        today.getFullYear(),
        today.getMonth() - 1,
        1,
    );
    const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
    const daysAgo = (n: number) =>
        new Date(today.getFullYear(), today.getMonth(), today.getDate() - n);

    return [
        { label: 'Last 7 days', from: iso(daysAgo(6)), to: iso(today) },
        { label: 'Last 30 days', from: iso(daysAgo(29)), to: iso(today) },
        { label: 'This month', from: iso(startOfMonth), to: iso(today) },
        {
            label: 'Last month',
            from: iso(lastMonthStart),
            to: iso(lastMonthEnd),
        },
        {
            label: 'This year',
            from: iso(new Date(today.getFullYear(), 0, 1)),
            to: iso(today),
        },
    ];
});

const activePreset = computed(
    () =>
        presets.value.find(
            (preset) =>
                preset.from === props.filters.from &&
                preset.to === props.filters.to,
        )?.label ?? null,
);

function applyPreset(preset: { from: string; to: string }) {
    from.value = preset.from;
    to.value = preset.to;
}

const departmentOptions = computed(() =>
    props.departments.map((name) => ({ value: name, label: name })),
);

const today = iso(new Date());

const hoursOf = (minutes: number) => Math.round((minutes / 60) * 10) / 10;
</script>

<template>
    <Head title="Attendance report" />

    <AppLayout
        heading="Attendance report"
        :lede="`${range_label} · ${range_days} ${range_days === 1 ? 'day' : 'days'}`"
    >
        <div class="space-y-5">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="Staff covered"
                    :value="summary.staff"
                    caption="Matching the filters"
                />
                <StatTile
                    label="Days worked"
                    :value="summary.days_present"
                    :caption="`${summary.days_late} late · ${summary.days_grace} within grace`"
                />
                <StatTile
                    label="Hours worked"
                    :value="summary.total_hours"
                    :decimals="1"
                    suffix="h"
                />
                <StatTile
                    label="Punctuality"
                    :value="summary.punctuality"
                    suffix="%"
                    :tone="summary.punctuality < 90 ? 'brass' : 'signal'"
                    :caption="duration(summary.late_minutes) + ' late in total'"
                />
            </div>

            <Panel>
                <div class="space-y-4">
                    <div
                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[170px_170px_minmax(0,1fr)]"
                    >
                        <div class="space-y-1.5">
                            <label
                                for="range-from"
                                class="text-[13px] font-medium text-muted"
                            >
                                From
                            </label>
                            <input
                                id="range-from"
                                v-model="from"
                                type="date"
                                :max="to || today"
                                class="h-11 w-full rounded-xl border border-line bg-panel-raised px-3.5 text-sm text-text transition-all duration-200 focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <label
                                for="range-to"
                                class="text-[13px] font-medium text-muted"
                            >
                                To
                            </label>
                            <input
                                id="range-to"
                                v-model="to"
                                type="date"
                                :min="from"
                                :max="today"
                                class="h-11 w-full rounded-xl border border-line bg-panel-raised px-3.5 text-sm text-text transition-all duration-200 focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <span class="text-[13px] font-medium text-muted">
                                Quick ranges
                            </span>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="preset in presets"
                                    :key="preset.label"
                                    type="button"
                                    :class="[
                                        'h-11 rounded-xl border px-3 text-[12.5px] font-medium transition-colors',
                                        activePreset === preset.label
                                            ? 'border-beacon bg-line-soft text-text'
                                            : 'border-line text-muted hover:bg-line-soft hover:text-text',
                                    ]"
                                    @click="applyPreset(preset)"
                                >
                                    {{ preset.label }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_190px_190px]"
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
                                placeholder="Search by staff name, email or ID"
                                class="h-11 w-full rounded-xl border border-line bg-panel pr-3.5 pl-10 text-sm transition-all duration-200 placeholder:text-faint focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                            />
                        </div>

                        <SelectField
                            v-model="department"
                            :options="departmentOptions"
                        >
                            <option value="">All departments</option>
                        </SelectField>

                        <SelectField v-model="site" :options="locations">
                            <option value="">All locations</option>
                            <option value="none">No location</option>
                        </SelectField>
                    </div>
                </div>
            </Panel>

            <Panel flush>
                <div v-if="rows.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1000px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Staff
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Present
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Absent
                                </th>
                                <th
                                    class="eyebrow px-5 py-3 font-medium"
                                    title="Arrived after the start of work but inside the site's grace period"
                                >
                                    Within grace
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Late
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Hours
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Last day
                                </th>
                                <th
                                    class="eyebrow px-5 py-3 text-right font-medium"
                                >
                                    Punctuality
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="row in rows.data"
                                :key="row.id"
                                class="transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3">
                                    <Link
                                        :href="`/admin/staff/${row.id}`"
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :initials="row.initials"
                                            :name="row.name"
                                            size="sm"
                                            :muted="!row.is_active"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate text-[13px] font-medium"
                                            >
                                                {{ row.name }}
                                            </span>
                                            <span
                                                class="block truncate text-[12px] text-faint"
                                            >
                                                {{
                                                    [
                                                        row.employee_id,
                                                        row.department,
                                                        row.location,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') || '-'
                                                }}
                                            </span>
                                        </span>
                                    </Link>
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                >
                                    {{ row.days_present
                                    }}<span
                                        v-if="row.days_expected !== null"
                                        class="text-faint"
                                        >/{{ row.days_expected }}</span
                                    >
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                    :class="
                                        row.days_absent
                                            ? 'text-brass'
                                            : 'text-muted'
                                    "
                                >
                                    {{ row.days_absent ?? '-' }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                    :class="
                                        row.days_grace
                                            ? 'text-brass'
                                            : 'text-muted'
                                    "
                                >
                                    {{ row.days_grace }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                    :class="
                                        row.days_late
                                            ? 'text-alert'
                                            : 'text-muted'
                                    "
                                >
                                    {{ row.days_late }}
                                    <span
                                        v-if="row.late_minutes > 0"
                                        class="text-faint"
                                    >
                                        ({{ duration(row.late_minutes) }})
                                    </span>
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px] text-muted"
                                >
                                    {{ hoursOf(row.worked_minutes) }}h
                                    <span
                                        v-if="row.open_days > 0"
                                        class="text-faint"
                                        :title="`${row.open_days} day(s) never clocked out`"
                                    >
                                        · {{ row.open_days }} open
                                    </span>
                                </td>
                                <td
                                    class="px-5 py-3 text-[12.5px] whitespace-nowrap text-muted"
                                >
                                    {{ row.last_seen ?? '-' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <StatusPill
                                        v-if="row.punctuality !== null"
                                        :tone="
                                            row.punctuality >= 90
                                                ? 'signal'
                                                : 'brass'
                                        "
                                    >
                                        {{ row.punctuality }}%
                                    </StatusPill>
                                    <span
                                        v-else
                                        class="text-[12.5px] text-faint"
                                    >
                                        No days
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <Pagination
                        :links="rows.links"
                        :from="rows.from"
                        :to="rows.to"
                        :total="rows.total"
                    />
                </div>

                <EmptyState
                    v-else
                    title="No staff match these filters"
                    message="Widen the date range, or clear the search and department filters, to see attendance again."
                />
            </Panel>
        </div>
    </AppLayout>
</template>
