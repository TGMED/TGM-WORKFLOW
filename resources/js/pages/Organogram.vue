<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrgNode from '@/components/OrgNode.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrgPerson, OrgUnit } from '@/types/organogram';

const props = defineProps<{
    nodes: OrgPerson[];
    roots: number[];
    totals: { people: number; roots: number; unplaced: number };
    you: number;
    /** Whether this reader may rearrange the chart, not merely read it. */
    can_manage: boolean;
    units: OrgUnit[];
    assignable: { value: number; label: string }[];
}>();

const search = ref('');

/** The person whose reporting line is being changed, if any. */
const moving = ref<OrgPerson | null>(null);

const managerForm = useForm({ manager_id: null as number | null });

/**
 * Who this person could report to: anybody but themselves and anybody already
 * below them. The server refuses a loop as well; this keeps the picker from
 * offering one in the first place.
 */
const managerOptions = computed(() => {
    const person = moving.value;

    if (person === null) {
        return props.assignable;
    }

    const below = new Set<number>([person.id]);
    let added = true;

    // The chart is small enough to close over by sweeping it: each pass picks
    // up anybody whose manager is already known to be below this person.
    while (added) {
        added = false;

        for (const candidate of props.nodes) {
            if (
                candidate.manager_id !== null &&
                below.has(candidate.manager_id) &&
                !below.has(candidate.id)
            ) {
                below.add(candidate.id);
                added = true;
            }
        }
    }

    return props.assignable.filter((option) => !below.has(option.value));
});

function startMove(person: OrgPerson) {
    managerForm.clearErrors();
    managerForm.defaults({ manager_id: person.manager_id });
    managerForm.reset();
    moving.value = person;
}

function saveManager() {
    if (moving.value === null) {
        return;
    }

    managerForm.put(`/admin/organogram/${moving.value.id}/manager`, {
        preserveScroll: true,
        onSuccess: () => {
            moving.value = null;
        },
    });
}

/**
 * The select hands back whatever the option carried, so the id is normalised
 * here rather than trusted. An empty pick means the job is left vacant.
 */
function person(value: string | number | null | undefined): number | null {
    return value === null || value === undefined || value === ''
        ? null
        : Number(value);
}

function setHead(unit: OrgUnit, value: string | number | null | undefined) {
    router.put(
        `/admin/organogram/departments/${unit.id}/head`,
        { head_user_id: person(value) },
        { preserveScroll: true },
    );
}

function setLead(teamId: number, value: string | number | null | undefined) {
    router.put(
        `/admin/organogram/teams/${teamId}/lead`,
        { lead_user_id: person(value) },
        { preserveScroll: true },
    );
}

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

                <!-- A chart drawn top-down gets wider than the panel long
                     before it gets taller than the screen, so it scrolls
                     sideways and sits centred while it still fits. -->
                <div v-else class="overflow-x-auto">
                    <ul class="mx-auto flex w-max items-start gap-8 px-6 py-8">
                        <OrgNode
                            v-for="person in rootPeople"
                            :key="person.id"
                            :person="person"
                            :children-of="childrenOf"
                            :on-manage="can_manage ? startMove : undefined"
                        />
                    </ul>
                </div>
            </Panel>

            <!-- The jobs the approval chain actually reads. Kept beside the
                 chart rather than on it: a head is not a rung of the picture,
                 and running the two together would suggest they move as one. -->
            <Panel
                v-if="can_manage && !search.trim()"
                title="Who leads what"
                subtitle="A head of department and a team lead are what a leave request is routed through. The chart above is the reporting line, which is a different question."
            >
                <EmptyState
                    v-if="units.length === 0"
                    title="No departments yet"
                    message="Departments and their teams are created on the departments page. Once they exist, you can name who runs them here."
                />

                <ul v-else class="space-y-5">
                    <li v-for="unit in units" :key="unit.id">
                        <div
                            class="flex flex-wrap items-end justify-between gap-3"
                        >
                            <p class="text-[14px] font-semibold tracking-tight">
                                {{ unit.name }}
                            </p>
                            <div class="w-full max-w-sm">
                                <SelectField
                                    :model-value="unit.head_user_id"
                                    label="Head of department"
                                    :options="assignable"
                                    @update:model-value="
                                        (value) => setHead(unit, value)
                                    "
                                >
                                    <option :value="null">
                                        Nobody for now
                                    </option>
                                </SelectField>
                            </div>
                        </div>

                        <ul
                            v-if="unit.teams.length"
                            class="mt-3 space-y-3 border-l border-line-soft pl-4"
                        >
                            <li
                                v-for="team in unit.teams"
                                :key="team.id"
                                class="flex flex-wrap items-end justify-between gap-3"
                            >
                                <p class="text-[13px] text-muted">
                                    {{ team.name }}
                                </p>
                                <div class="w-full max-w-sm">
                                    <SelectField
                                        :model-value="team.lead_user_id"
                                        label="Team lead"
                                        :options="assignable"
                                        @update:model-value="
                                            (value) => setLead(team.id, value)
                                        "
                                    >
                                        <option :value="null">
                                            Nobody for now
                                        </option>
                                    </SelectField>
                                </div>
                            </li>
                        </ul>

                        <p v-else class="mt-2 text-[12.5px] text-faint">
                            No teams in this department.
                        </p>
                    </li>
                </ul>
            </Panel>
        </div>

        <ModalShell
            :open="moving !== null"
            :title="moving ? `Who ${moving.name} reports to` : ''"
            subtitle="The reporting line on their record, which is what this chart draws. It does not change who heads their department."
            @close="moving = null"
        >
            <form class="space-y-4" @submit.prevent="saveManager">
                <SelectField
                    v-model="managerForm.manager_id"
                    label="Manager"
                    :options="managerOptions"
                    :error="managerForm.errors.manager_id"
                    hint="Anybody already below them on the chart is left out, since that would close a loop."
                >
                    <option :value="null">Nobody — top of the chart</option>
                </SelectField>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="moving = null">
                    Cancel
                </AppButton>
                <AppButton
                    :loading="managerForm.processing"
                    @click="saveManager"
                >
                    Save
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
