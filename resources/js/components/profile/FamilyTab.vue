<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue';
import Panel from '@/components/ui/Panel.vue';
import type { EmployeeRelation, ProfileOptions, RelationKind } from '@/types';

defineProps<{
    relations: Record<RelationKind, EmployeeRelation[]>;
    options: ProfileOptions;
}>();

const emit = defineEmits<{
    add: [RelationKind];
    edit: [EmployeeRelation];
}>();

// Dependants and family members sit side by side, next of kin below, matching
// how the three are weighted: one you must have, two you might.
const layout: Record<RelationKind, string> = {
    dependant: 'lg:col-start-1',
    family_member: 'lg:col-start-2 lg:row-start-1',
    next_of_kin: 'lg:col-start-1 lg:row-start-2',
};
</script>

<template>
    <div class="grid items-start gap-5 lg:grid-cols-2">
        <Panel
            v-for="kind in options.relation_kinds"
            :key="kind.value"
            :title="kind.plural"
            :class="layout[kind.value]"
        >
            <template #action>
                <AppButton size="sm" @click="emit('add', kind.value)">
                    + Add {{ kind.label.toLowerCase() }}
                </AppButton>
            </template>

            <p
                v-if="!relations[kind.value].length"
                class="text-[13px] text-muted"
            >
                Nobody recorded here yet.
            </p>

            <ul v-else class="space-y-2">
                <li
                    v-for="person in relations[kind.value]"
                    :key="person.id"
                    class="rounded-xl border border-line-soft bg-sunken/40 px-4 py-3"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ person.name }}
                            </p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                Relationship : {{ person.relationship }}
                            </p>
                            <p
                                v-if="person.phone"
                                class="mt-0.5 text-[13px] text-faint"
                            >
                                {{ person.phone }}
                            </p>
                        </div>

                        <!-- Removal lives inside the editor, so a stray click
                             on a crowded card cannot delete anyone. -->
                        <button
                            type="button"
                            :aria-label="`Edit ${person.name}`"
                            class="grid size-8 shrink-0 place-items-center rounded-lg text-faint transition-colors hover:bg-line-soft hover:text-text"
                            @click="emit('edit', person)"
                        >
                            <svg
                                class="size-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path
                                    d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3ZM14.5 6.5l3 3"
                                />
                            </svg>
                        </button>
                    </div>
                </li>
            </ul>
        </Panel>
    </div>
</template>
