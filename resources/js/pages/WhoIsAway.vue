<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { usePaginated } from '@/composables/usePaginated';
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

type Elsewhere = {
    id: number;
    user: Person['user'];
    kind: string;
    kind_label: string;
    kind_tone: 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';
    range_label: string;
    days: number;
    destination: string | null;
    contact_number: string | null;
    is_out_now: boolean;
};

const props = defineProps<{
    filters: { window: string; search: string; location: string };
    today: string;
    people: Person[];
    elsewhere: Elsewhere[];
    stats: {
        away_today: number;
        away_this_week: number;
        back_tomorrow: number;
        pending: number;
        working_elsewhere_today: number;
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

const awayPages = usePaginated(() => awayNow.value);
const upcomingPages = usePaginated(() => upcoming.value);
const elsewherePages = usePaginated(() => props.elsewhere);
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
                    label="Working elsewhere"
                    :value="stats.working_elsewhere_today"
                    caption="At work, out of the office"
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
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 font-medium">Leave</th>
                                <th class="px-5 py-3 font-medium">Dates</th>
                                <th class="px-5 py-3 font-medium">Cover</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Back
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="awayNow.length === 0">
                                <td colspan="5">
                                    <EmptyState
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
                                </td>
                            </tr>
                            <tr
                                v-for="person in awayPages.paged"
                                :key="person.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <component
                                        :is="isAdmin ? Link : 'div'"
                                        :href="
                                            isAdmin
                                                ? `/admin/staff/${person.user.id}`
                                                : undefined
                                        "
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :initials="person.user.initials"
                                            :name="person.user.name"
                                            size="sm"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate font-medium"
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
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill tone="brass" dot>
                                        {{ person.type }}
                                    </StatusPill>
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-muted"
                                >
                                    {{ person.range_label }}
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ person.relief_officer ?? '-' }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <p class="font-medium">
                                        {{ backLabel(person) }}
                                    </p>
                                    <p
                                        class="tabular font-mono text-[11.5px] text-faint"
                                    >
                                        {{ person.days_left }} of
                                        {{ person.days }} working day{{
                                            person.days === 1 ? '' : 's'
                                        }}
                                        left
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="awayPages.page"
                    v-model:per-page="awayPages.perPage"
                    :last-page="awayPages.lastPage"
                    :from="awayPages.from"
                    :to="awayPages.to"
                    :total="awayPages.total"
                />
            </Panel>

            <!-- Booked, but not started yet. Only shown once the window reaches
                 past today. -->
            <Panel
                flush
                eyebrow="Booked ahead"
                :title="`${upcoming.length} more in this window`"
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 font-medium">Leave</th>
                                <th class="px-5 py-3 font-medium">Dates</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Starts
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="upcoming.length === 0">
                                <td colspan="4">
                                    <EmptyState
                                        :title="'Nothing booked ahead'"
                                        message="Nobody has approved leave starting later in this window. Widen it to look further out."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="person in upcomingPages.paged"
                                :key="person.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <component
                                        :is="isAdmin ? Link : 'div'"
                                        :href="
                                            isAdmin
                                                ? `/admin/staff/${person.user.id}`
                                                : undefined
                                        "
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :initials="person.user.initials"
                                            :name="person.user.name"
                                            size="sm"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate font-medium"
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
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ person.type }} ·
                                    {{ person.days }} working day{{
                                        person.days === 1 ? '' : 's'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-muted"
                                >
                                    {{ person.range_label }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono text-[12px] text-faint"
                                >
                                    in {{ person.starts_in }} day{{
                                        person.starts_in === 1 ? '' : 's'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="upcomingPages.page"
                    v-model:per-page="upcomingPages.perPage"
                    :last-page="upcomingPages.lastPage"
                    :from="upcomingPages.from"
                    :to="upcomingPages.to"
                    :total="upcomingPages.total"
                />
            </Panel>
            <!-- At work, elsewhere. Kept apart from the away lists above: a
                 day at a client site is not a day off, and nobody should be
                 chasing cover for it. -->
            <Panel
                flush
                eyebrow="Out of the office"
                :title="`${elsewhere.length} working elsewhere`"
                subtitle="Still at work, and still reachable."
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 font-medium">Dates</th>
                                <th class="px-5 py-3 font-medium">Where</th>
                                <th class="px-5 py-3 font-medium">Contact</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Kind
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="elsewhere.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        :title="'Nobody working elsewhere'"
                                        message="Nobody is working from home or away on company business in this window."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="person in elsewherePages.paged"
                                :key="person.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <component
                                        :is="isAdmin ? Link : 'div'"
                                        :href="
                                            isAdmin
                                                ? `/admin/staff/${person.user.id}`
                                                : undefined
                                        "
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :initials="person.user.initials"
                                            :name="person.user.name"
                                            size="sm"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate font-medium"
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
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-muted"
                                >
                                    {{ person.range_label }}
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ person.destination ?? '-' }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 font-mono text-[12px] text-muted"
                                >
                                    {{ person.contact_number ?? '-' }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <StatusPill
                                        :tone="person.kind_tone"
                                        :dot="person.is_out_now"
                                    >
                                        {{ person.kind_label }}
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="elsewherePages.page"
                    v-model:per-page="elsewherePages.perPage"
                    :last-page="elsewherePages.lastPage"
                    :from="elsewherePages.from"
                    :to="elsewherePages.to"
                    :total="elsewherePages.total"
                />
            </Panel>
        </div>
    </AppLayout>
</template>
