<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import type { RequestTrail } from '@/types';

type Row = {
    id: number;
    person: {
        id: number;
        name: string;
        initials: string;
        employee_id: string | null;
        department: string | null;
    };
    type: string;
    start_date: string;
    end_date: string;
    range_label: string;
    days: number;
    reason: string | null;
    relief_officer: string | null;
    supervisor: string | null;
    status: string;
    status_label: string;
    status_tone: 'signal' | 'brass' | 'alert' | 'neutral';
    /** Where an open request has reached. Null once it is settled. */
    stage_label: string | null;
    has_evidence: boolean;
    filed_at: string | null;
    decided_at: string | null;
    trail: RequestTrail[];
};

type Option = { value: string | number; label: string };

const props = defineProps<{
    rows: {
        data: Row[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        department: string;
        type: string;
        status: string;
        year: number;
    };
    departments: Option[];
    types: Option[];
    statuses: Option[];
    years: number[];
    summary: {
        requests: number;
        days: number;
        pending: number;
        returned: number;
        people: number;
    };
}>();

const search = ref(props.filters.search);
const department = ref(props.filters.department);
const type = ref(props.filters.type);
const status = ref(props.filters.status);
const year = ref(props.filters.year);

/** The request whose decision trail is open, if any. */
const expanded = ref<number | null>(null);

let debounce: number | undefined;

/*
 * Filtering is a round trip, not a sweep of the rows in hand: this is the
 * whole company over a year, and only a page of it was ever sent. Typing is
 * debounced so a search does not fire a query per keystroke.
 */
function applyFilters(immediate = false) {
    window.clearTimeout(debounce);

    const run = () =>
        router.get(
            '/admin/leave',
            {
                search: search.value || undefined,
                department: department.value || undefined,
                type: type.value || undefined,
                status: status.value || undefined,
                year: year.value,
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
watch([department, type, status, year], () => applyFilters(true));

function clearFilters() {
    search.value = '';
    department.value = '';
    type.value = '';
    status.value = '';
}

function toggleTrail(row: Row) {
    expanded.value = expanded.value === row.id ? null : row.id;
}

/** The download follows whatever is on screen, so the two always agree. */
function exportUrl() {
    const query = new URLSearchParams({ year: String(year.value) });

    if (search.value) {
        query.set('search', search.value);
    }

    if (department.value) {
        query.set('department', department.value);
    }

    if (type.value) {
        query.set('type', type.value);
    }

    if (status.value) {
        query.set('status', status.value);
    }

    return `/admin/leave/export?${query.toString()}`;
}
</script>

<template>
    <Head title="Leave register" />

    <AppLayout
        heading="Leave register"
        lede="Every leave request in the company, and where each one has reached."
    >
        <template #toolbar>
            <AppButton size="sm" variant="secondary" :href="exportUrl()">
                Download CSV
            </AppButton>
        </template>

        <div class="space-y-6">
            <div class="stagger grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <StatTile label="Requests" :value="summary.requests" />
                <StatTile
                    label="People"
                    :value="summary.people"
                    caption="With leave on file"
                />
                <StatTile
                    label="Approved days"
                    :value="summary.days"
                    caption="Working days granted"
                />
                <StatTile
                    label="Awaiting a decision"
                    :value="summary.pending"
                    :tone="summary.pending > 0 ? 'brass' : 'default'"
                />
                <StatTile
                    label="Sent back"
                    :value="summary.returned"
                    :tone="summary.returned > 0 ? 'brass' : 'default'"
                    caption="With the requester to redo"
                />
            </div>

            <Panel :title="`Leave in ${filters.year}`" flush>
                <div
                    class="flex flex-wrap items-end gap-3 border-b border-line-soft px-5 py-4"
                >
                    <div class="min-w-[12rem] flex-1">
                        <TextField
                            v-model="search"
                            label="Person"
                            placeholder="Name or staff ID"
                        />
                    </div>
                    <div class="w-44">
                        <SelectField
                            v-model="status"
                            label="Status"
                            :options="statuses"
                        >
                            <option value="">Every status</option>
                        </SelectField>
                    </div>
                    <div class="w-44">
                        <SelectField
                            v-model="type"
                            label="Leave type"
                            :options="types"
                        >
                            <option value="">Every type</option>
                        </SelectField>
                    </div>
                    <div class="w-48">
                        <SelectField
                            v-model="department"
                            label="Department"
                            :options="departments"
                        >
                            <option value="">Every department</option>
                        </SelectField>
                    </div>
                    <div class="w-28">
                        <SelectField
                            v-model="year"
                            label="Year"
                            :options="
                                years.map((option) => ({
                                    value: option,
                                    label: String(option),
                                }))
                            "
                        />
                    </div>
                    <AppButton
                        v-if="search || status || type || department"
                        variant="ghost"
                        size="sm"
                        @click="clearFilters"
                    >
                        Clear
                    </AppButton>
                </div>

                <EmptyState
                    v-if="rows.data.length === 0"
                    title="Nothing matches"
                    :message="`No leave in ${filters.year} under those filters. Widen them, or pick another year.`"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Dates</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Days
                                </th>
                                <th class="px-5 py-3 font-medium">
                                    Cover and approver
                                </th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-line-soft">
                            <template v-for="row in rows.data" :key="row.id">
                                <tr
                                    class="transition-colors hover:bg-sunken/40"
                                >
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-2.5">
                                            <Avatar
                                                :initials="row.person.initials"
                                                :name="row.person.name"
                                                size="sm"
                                            />
                                            <div class="min-w-0">
                                                <p class="truncate font-medium">
                                                    {{ row.person.name }}
                                                </p>
                                                <p
                                                    class="truncate text-[12px] text-faint"
                                                >
                                                    {{
                                                        row.person
                                                            .employee_id ?? '—'
                                                    }}
                                                    <template
                                                        v-if="
                                                            row.person
                                                                .department
                                                        "
                                                    >
                                                        ·
                                                        {{
                                                            row.person
                                                                .department
                                                        }}
                                                    </template>
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-5 py-3.5">
                                        <p>{{ row.type }}</p>
                                        <p
                                            v-if="row.reason"
                                            class="max-w-[18rem] truncate text-[12px] text-faint"
                                            :title="row.reason"
                                        >
                                            {{ row.reason }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-3.5 text-muted">
                                        {{ row.range_label }}
                                    </td>

                                    <td
                                        class="tabular px-5 py-3.5 text-right font-mono"
                                    >
                                        {{ row.days }}
                                    </td>

                                    <td
                                        class="px-5 py-3.5 text-[12.5px] text-muted"
                                    >
                                        {{ row.relief_officer ?? '—' }}
                                        <span class="text-faint">/</span>
                                        {{ row.supervisor ?? '—' }}
                                    </td>

                                    <td class="px-5 py-3.5">
                                        <StatusPill :tone="row.status_tone" dot>
                                            {{ row.status_label }}
                                        </StatusPill>
                                        <p
                                            v-if="row.stage_label"
                                            class="mt-1 text-[11.5px] text-faint"
                                        >
                                            {{ row.stage_label }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-3.5 text-right">
                                        <AppButton
                                            v-if="row.trail.length"
                                            variant="ghost"
                                            size="sm"
                                            @click="toggleTrail(row)"
                                        >
                                            {{
                                                expanded === row.id
                                                    ? 'Hide'
                                                    : 'Trail'
                                            }}
                                        </AppButton>
                                    </td>
                                </tr>

                                <tr v-if="expanded === row.id">
                                    <td
                                        colspan="7"
                                        class="bg-sunken/40 px-5 py-3"
                                    >
                                        <ul class="space-y-2">
                                            <li
                                                v-for="step in row.trail"
                                                :key="step.id"
                                                class="text-[12.5px]"
                                            >
                                                <span class="font-medium">
                                                    {{ step.approver }}
                                                </span>
                                                <span class="text-muted">
                                                    ({{
                                                        step.stage_label.toLowerCase()
                                                    }})
                                                    {{
                                                        step.decision_label.toLowerCase()
                                                    }}
                                                    on
                                                    {{
                                                        dateTime(
                                                            step.decided_at,
                                                        )
                                                    }}
                                                </span>
                                                <span
                                                    v-if="step.superseded"
                                                    class="text-faint"
                                                >
                                                    · on an earlier version
                                                </span>
                                                <p
                                                    v-if="step.comment"
                                                    class="text-faint"
                                                >
                                                    “{{ step.comment }}”
                                                </p>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <Pagination
                        :links="rows.links"
                        :from="rows.from"
                        :to="rows.to"
                        :total="rows.total"
                    />
                </div>
            </Panel>
        </div>
    </AppLayout>
</template>
