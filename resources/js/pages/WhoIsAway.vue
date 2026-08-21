<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { fullDate, shortDate } from '@/lib/format';
import type { SharedProps } from '@/types';

type Person = {
    id: number;
    user: {
        id: number;
        name: string;
        initials: string;
        employee_id: string | null;
        department: string | null;
        position: string | null;
        location: string | null;
    };
    type: string;
    start_date: string;
    end_date: string;
    range_label: string;
    days: number;
    is_away: boolean;
    returns_on: string;
    days_left: number | null;
    starts_in: number | null;
    relief_officer: string | null;
};

const props = defineProps<{
    filters: { window: string; search: string; location: string };
    today: string;
    people: Person[];
    stats: {
        away_today: number;
        away_this_week: number;
        back_tomorrow: number;
        pending: number;
        active_staff: number;
    };
    locations: Array<{ value: string; label: string }>;
}>();

const page = usePage<SharedProps>();
const isAdmin = computed(() => page.props.auth.user?.is_super_admin === true);

const search = ref(props.filters.search);
const window_ = ref(props.filters.window);
const site = ref(props.filters.location);

let debounce: number | undefined;

function applyFilters(immediate = false) {
    window.clearTimeout(debounce);

    const run = () =>
        router.get(
            '/away',
            {
                search: search.value || undefined,
                window: window_.value,
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
watch([window_, site], () => applyFilters(true));

const windowOptions = [
    { value: 'today', label: 'Away today' },
    { value: '7d', label: 'Next 7 days' },
    { value: '30d', label: 'Next 30 days' },
];

// Out now sits above booked for later, each group in date order.
const awayNow = computed(() => props.people.filter((person) => person.is_away));
const upcoming = computed(() =>
    props.people.filter((person) => !person.is_away),
);

// Share of the workforce out today, which is the number worth watching.
const coverage = computed(() =>
    props.stats.active_staff > 0
        ? Math.round((props.stats.away_today / props.stats.active_staff) * 100)
        : 0,
);

function backLabel(person: Person): string {
    if (!person.is_away) {
        return `Starts ${shortDate(person.start_date)}`;
    }

    if (person.days_left === 1) {
        return 'Back tomorrow';
    }

    return `Back ${shortDate(person.returns_on)}`;
}
</script>

<template>
    <Head title="Who's away" />

    <AppLayout
        heading="Who's away"
        lede="Approved leave across the company, and when each person is back"
    >
        <div class="space-y-5">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="On leave today"
                    :value="stats.away_today"
                    :caption="`${coverage}% of active staff`"
                    :tone="stats.away_today > 0 ? 'brass' : 'default'"
                />
                <StatTile
                    label="Away this week"
                    :value="stats.away_this_week"
                    caption="Today and the next six days"
                />
                <StatTile
                    label="Back tomorrow"
                    :value="stats.back_tomorrow"
                    tone="signal"
                    caption="Last day of leave is today"
                />
                <StatTile
                    label="Not yet decided"
                    :value="stats.pending"
                    caption="Requests still with an approver"
                />
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
                        placeholder="Search by name, ID or department"
                        class="h-11 w-full rounded-xl border border-line bg-panel pr-3.5 pl-10 text-sm transition-all duration-200 placeholder:text-faint focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                </div>

                <SelectField v-model="window_" :options="windowOptions" />

                <SelectField v-model="site" :options="locations">
                    <option value="">All locations</option>
                </SelectField>
            </div>

            <Panel
                flush
                :eyebrow="fullDate(today)"
                :title="
                    awayNow.length === 1
                        ? '1 person is out'
                        : `${awayNow.length} people are out`
                "
            >
                <ul v-if="awayNow.length" class="divide-y divide-line-soft">
                    <li
                        v-for="person in awayNow"
                        :key="person.id"
                        class="flex flex-wrap items-start gap-x-4 gap-y-3 px-5 py-4 transition-colors hover:bg-line-soft/40"
                    >
                        <component
                            :is="isAdmin ? Link : 'div'"
                            :href="
                                isAdmin
                                    ? `/admin/staff/${person.user.id}`
                                    : undefined
                            "
                            class="flex min-w-[200px] flex-1 items-start gap-3"
                        >
                            <Avatar
                                :initials="person.user.initials"
                                :name="person.user.name"
                                size="sm"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[13.5px] font-medium"
                                >
                                    {{ person.user.name }}
                                </span>
                                <span
                                    class="block truncate text-[11.5px] text-faint"
                                >
                                    {{
                                        person.user.department ??
                                        person.user.position ??
                                        person.user.location ??
                                        '-'
                                    }}
                                </span>
                            </span>
                        </component>

                        <div class="min-w-[200px] flex-[2]">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusPill tone="brass" dot>
                                    {{ person.type }}
                                </StatusPill>
                                <span
                                    class="text-[12.5px] font-medium text-muted"
                                >
                                    {{ person.range_label }}
                                </span>
                            </div>
                            <p
                                v-if="person.relief_officer"
                                class="mt-1 text-[12.5px] text-faint"
                            >
                                Covered by {{ person.relief_officer }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-[13px] font-medium">
                                {{ backLabel(person) }}
                            </p>
                            <p
                                class="tabular mt-0.5 font-mono text-[11.5px] text-faint"
                            >
                                {{ person.days_left }} of
                                {{ person.days }} day{{
                                    person.days === 1 ? '' : 's'
                                }}
                                left
                            </p>
                        </div>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    title="Everybody is in"
                    message="Nobody has approved leave covering today. Widen the window to see what is booked ahead."
                >
                    <template #icon>
                        <svg
                            class="size-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM16 12l2 2 4-4"
                            />
                        </svg>
                    </template>
                </EmptyState>
            </Panel>

            <!-- Booked, but not started yet. Only shown once the window reaches
                 past today. -->
            <Panel
                v-if="upcoming.length"
                flush
                eyebrow="Booked ahead"
                :title="`${upcoming.length} more in this window`"
            >
                <ul class="divide-y divide-line-soft">
                    <li
                        v-for="person in upcoming"
                        :key="person.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition-colors hover:bg-line-soft/40"
                    >
                        <div
                            class="flex min-w-[200px] flex-1 items-center gap-3"
                        >
                            <Avatar
                                :initials="person.user.initials"
                                :name="person.user.name"
                                size="sm"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[13.5px] font-medium"
                                >
                                    {{ person.user.name }}
                                </span>
                                <span
                                    class="block truncate text-[11.5px] text-faint"
                                >
                                    {{ person.type }} · {{ person.days }} day{{
                                        person.days === 1 ? '' : 's'
                                    }}
                                </span>
                            </span>
                        </div>

                        <p class="text-[12.5px] text-muted">
                            {{ person.range_label }}
                        </p>

                        <p
                            class="tabular ml-auto font-mono text-[11.5px] text-faint"
                        >
                            in {{ person.starts_in }} day{{
                                person.starts_in === 1 ? '' : 's'
                            }}
                        </p>
                    </li>
                </ul>
            </Panel>
        </div>
    </AppLayout>
</template>
