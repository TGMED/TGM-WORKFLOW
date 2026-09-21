<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { currentPerPage } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, distance } from '@/lib/format';

type Attempt = {
    id: number;
    user: {
        id: number;
        name: string;
        initials: string;
        employee_id: string | null;
        department: string | null;
    };
    type: string;
    type_label: string;
    result: string;
    result_label: string;
    message: string | null;
    latitude: number | null;
    longitude: number | null;
    accuracy_meters: number | null;
    distance_meters: number | null;
    ip_address: string | null;
    location: string | null;
    created_at: string;
};

const props = defineProps<{
    attempts: {
        data: Attempt[];
        per_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        result: string;
        search: string;
        range: string;
        location: string;
    };
    results: Array<{ value: string; label: string }>;
    counts: {
        total: number;
        success: number;
        rejected: number;
        out_of_range: number;
    };
    locations: Array<{ value: string; label: string }>;
}>();

const search = ref(props.filters.search);
const result = ref(props.filters.result);
const range = ref(props.filters.range);
const site = ref(props.filters.location);

let debounce: number | undefined;

function applyFilters(immediate = false) {
    window.clearTimeout(debounce);

    const run = () =>
        router.get(
            '/admin/clock-attempts',
            {
                per_page: currentPerPage(),
                search: search.value || undefined,
                result: result.value === 'all' ? undefined : result.value,
                range: range.value,
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
watch([result, range, site], () => applyFilters(true));

const rangeOptions = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'all', label: 'All time' },
];

const resultOptions = [
    { value: 'all', label: 'All outcomes' },
    { value: 'rejected', label: 'Rejected only' },
    ...props.results,
];

const resultTone = (value: string) =>
    value === 'success'
        ? ('signal' as const)
        : value === 'out_of_range'
          ? ('alert' as const)
          : ('brass' as const);

function mapLink(attempt: Attempt): string | null {
    if (attempt.latitude === null || attempt.longitude === null) {
        return null;
    }

    return `https://www.openstreetmap.org/?mlat=${attempt.latitude}&mlon=${attempt.longitude}#map=17/${attempt.latitude}/${attempt.longitude}`;
}
</script>

<template>
    <Head title="Clock attempts" />

    <AppLayout
        heading="Clock attempts"
        lede="Every punch tried at any site, accepted or not"
    >
        <div class="space-y-5">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    label="Attempts"
                    :value="counts.total"
                    caption="In range of filter"
                />
                <StatTile
                    label="Accepted"
                    :value="counts.success"
                    tone="signal"
                />
                <StatTile
                    label="Rejected"
                    :value="counts.rejected"
                    :tone="counts.rejected > 0 ? 'brass' : 'default'"
                />
                <StatTile
                    label="Outside the fence"
                    :value="counts.out_of_range"
                    :tone="counts.out_of_range > 0 ? 'alert' : 'default'"
                    caption="Wrong site or too far"
                />
            </div>

            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_190px_170px_180px]"
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

                <SelectField v-model="result" :options="resultOptions" />
                <SelectField v-model="range" :options="rangeOptions" />

                <SelectField v-model="site" :options="locations">
                    <option value="">All locations</option>
                </SelectField>
            </div>

            <Panel flush>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 font-medium">Result</th>
                                <th class="px-5 py-3 font-medium">Attempt</th>
                                <th class="px-5 py-3 font-medium">Detail</th>
                                <th class="px-5 py-3 font-medium">When</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Distance
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="attempts.data.length === 0">
                                <td colspan="6">
                                    <EmptyState
                                        title="No attempts in this window"
                                        message="Widen the date range or clear the outcome filter to see more of the log."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="attempt in attempts.data"
                                :key="attempt.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <Link
                                        :href="`/admin/staff/${attempt.user.id}`"
                                        class="flex items-center gap-3"
                                    >
                                        <Avatar
                                            :initials="attempt.user.initials"
                                            :name="attempt.user.name"
                                            size="sm"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate font-medium"
                                            >
                                                {{ attempt.user.name }}
                                            </span>
                                            <span
                                                class="block truncate text-[11.5px] text-faint"
                                            >
                                                {{
                                                    attempt.user.employee_id ??
                                                    attempt.user.department ??
                                                    '-'
                                                }}
                                            </span>
                                        </span>
                                    </Link>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        :tone="resultTone(attempt.result)"
                                    >
                                        {{ attempt.result_label }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5 font-medium text-muted">
                                    {{ attempt.type_label }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] leading-snug text-muted"
                                >
                                    {{ attempt.message ?? '-' }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 font-mono text-[12px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(attempt.created_at) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono text-[11.5px] whitespace-nowrap text-faint"
                                >
                                    <p>
                                        <template
                                            v-if="
                                                attempt.distance_meters !== null
                                            "
                                        >
                                            {{
                                                distance(
                                                    attempt.distance_meters,
                                                )
                                            }}
                                            out
                                        </template>
                                        <template v-else>No fix</template>
                                        <template
                                            v-if="
                                                attempt.accuracy_meters !== null
                                            "
                                        >
                                            · ±{{ attempt.accuracy_meters }}m
                                        </template>
                                    </p>
                                    <a
                                        v-if="mapLink(attempt)"
                                        :href="mapLink(attempt)!"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-1 inline-block text-beacon transition-opacity hover:opacity-75"
                                    >
                                        View on map
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :links="attempts.links"
                    :per-page="attempts.per_page"
                    :from="attempts.from"
                    :to="attempts.to"
                    :total="attempts.total"
                />
            </Panel>
        </div>
    </AppLayout>
</template>
