<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrgNode from '@/components/OrgNode.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrgPerson } from '@/types/organogram';

const props = defineProps<{
    nodes: OrgPerson[];
    roots: number[];
    totals: { people: number; roots: number; unplaced: number };
    you: number;
}>();

const search = ref('');

const childrenOf = computed(() => {
    const map: Record<number, OrgPerson[]> = {};

    for (const person of props.nodes) {
        if (person.manager_id === null) {
            continue;
        }

        (map[person.manager_id] ??= []).push(person);
    }

    return map;
});

const rootPeople = computed(() =>
    props.roots
        .map((id) => props.nodes.find((person) => person.id === id))
        .filter((person): person is OrgPerson => person !== undefined),
);

// Searching flattens the chart rather than filtering it: somebody looking for
// a name wants the name, and then the line above it.
const matches = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return [];
    }

    return props.nodes.filter(
        (person) =>
            person.name.toLowerCase().includes(term) ||
            (person.position ?? '').toLowerCase().includes(term) ||
            (person.department ?? '').toLowerCase().includes(term),
    );
});

const managerOf = (person: OrgPerson) =>
    props.nodes.find((candidate) => candidate.id === person.manager_id) ?? null;
</script>

<template>
    <Head title="Organogram" />

    <AppLayout
        heading="Organogram"
        lede="Who reports to whom, as each person's record has it."
    >
        <div class="space-y-6">
            <div class="stagger grid grid-cols-3 gap-3">
                <StatTile label="People" :value="totals.people" />
                <StatTile
                    label="At the top"
                    :value="totals.roots"
                    caption="Reporting to nobody"
                />
                <StatTile
                    label="Unplaced"
                    :value="totals.unplaced"
                    :tone="totals.unplaced > 0 ? 'brass' : 'default'"
                    caption="No manager on file"
                />
            </div>

            <div class="relative max-w-md">
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
                    placeholder="Find somebody"
                    class="h-11 w-full rounded-xl border border-line bg-panel pr-3.5 pl-10 text-sm transition-all duration-200 placeholder:text-faint focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                />
            </div>

            <Panel
                v-if="search.trim()"
                flush
                :title="
                    matches.length === 1
                        ? '1 person'
                        : `${matches.length} people`
                "
            >
                <EmptyState
                    v-if="matches.length === 0"
                    title="Nobody by that name"
                    message="Try a surname, a job title or a department."
                />

                <ul v-else class="divide-y divide-line-soft">
                    <li
                        v-for="person in matches"
                        :key="person.id"
                        class="px-5 py-3.5"
                    >
                        <p class="text-[13.5px] font-medium">
                            {{ person.name }}
                        </p>
                        <p class="text-[12px] text-muted">
                            {{
                                [person.position, person.department]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <p class="mt-0.5 text-[12px] text-faint">
                            <template v-if="managerOf(person)">
                                Reports to {{ managerOf(person)?.name }}
                            </template>
                            <template v-else>
                                Reports to nobody on file
                            </template>
                            <template v-if="person.reports">
                                · {{ person.reports }} reporting to them
                            </template>
                        </p>
                    </li>
                </ul>
            </Panel>

            <Panel v-else flush title="The chart">
                <EmptyState
                    v-if="rootPeople.length === 0"
                    title="Nothing to draw"
                    message="Nobody has a reporting line on their record yet. They are set on each person's staff page."
                />

                <ul v-else class="px-4 py-3">
                    <OrgNode
                        v-for="person in rootPeople"
                        :key="person.id"
                        :person="person"
                        :children-of="childrenOf"
                    />
                </ul>
            </Panel>
        </div>
    </AppLayout>
</template>
