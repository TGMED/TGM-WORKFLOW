<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Choosing a group of people. Used wherever naming a job also means naming who
 * it covers: the members of a department, the members of a team.
 *
 * Each person carries where they already sit, so the list can say plainly that
 * choosing them moves them out of somewhere else rather than letting that
 * happen quietly on save.
 */
type Person = {
    id: number;
    name: string;
    position: string | null;
    department?: string | null;
};

const props = withDefaults(
    defineProps<{
        people: Person[];
        label?: string;
        error?: string;
        hint?: string;
        /** Named so the warning can say which department somebody is leaving. */
        currentDepartment?: string | null;
        emptyMessage?: string;
    }>(),
    { emptyMessage: 'Nobody to choose from.' },
);

const model = defineModel<number[]>({ default: () => [] });

const search = ref('');

const chosen = computed(() => new Set(model.value ?? []));

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return props.people;
    }

    return props.people.filter(
        (person) =>
            person.name.toLowerCase().includes(term) ||
            (person.position ?? '').toLowerCase().includes(term) ||
            (person.department ?? '').toLowerCase().includes(term),
    );
});

function toggle(id: number): void {
    const next = new Set(model.value ?? []);

    next.has(id) ? next.delete(id) : next.add(id);

    model.value = props.people
        .map((person) => person.id)
        .filter((id) => next.has(id));
}

/** Somebody already placed somewhere else, who choosing would move. */
function movesFrom(person: Person): string | null {
    if (!person.department || person.department === props.currentDepartment) {
        return null;
    }

    return person.department;
}
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-baseline justify-between gap-3">
            <span v-if="label" class="text-[13px] font-medium text-muted">
                {{ label }}
            </span>
            <span class="tabular text-[12px] text-faint">
                {{ (model ?? []).length }} chosen
            </span>
        </div>

        <div
            :class="[
                'overflow-hidden rounded-xl border bg-panel-raised',
                error ? 'border-alert' : 'border-line',
            ]"
        >
            <div class="border-b border-line-soft p-2">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by name, job title or department"
                    class="h-9 w-full rounded-lg border border-line bg-panel px-3 text-[13px] text-text focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                />
            </div>

            <div class="max-h-64 divide-y divide-line-soft overflow-y-auto">
                <label
                    v-for="person in visible"
                    :key="person.id"
                    class="flex cursor-pointer items-start gap-3 px-3.5 py-2.5 transition-colors hover:bg-panel"
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0 rounded border-line text-beacon focus:ring-2 focus:ring-beacon/30"
                        :checked="chosen.has(person.id)"
                        @change="toggle(person.id)"
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-[13.5px] font-medium text-text"
                        >
                            {{ person.name }}
                        </span>
                        <span class="block truncate text-[12.5px] text-faint">
                            {{ person.position ?? 'No job title' }}
                            <template v-if="movesFrom(person)">
                                · currently in {{ movesFrom(person) }}
                            </template>
                        </span>
                    </span>
                </label>

                <p
                    v-if="!visible.length"
                    class="px-3.5 py-6 text-center text-[13px] text-faint"
                >
                    {{ search ? 'Nobody matches that.' : emptyMessage }}
                </p>
            </div>
        </div>

        <p v-if="error" class="animate-fade-in text-[13px] text-alert">
            {{ error }}
        </p>
        <p v-else-if="hint" class="text-[13px] text-faint">{{ hint }}</p>
    </div>
</template>
