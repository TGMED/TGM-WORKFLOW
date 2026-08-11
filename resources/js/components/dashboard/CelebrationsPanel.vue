<script setup lang="ts">
import { computed } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import Panel from '@/components/ui/Panel.vue';
import type { Celebrations } from '@/types';

const props = defineProps<{ celebrations: Celebrations }>();

type Group = {
    id: 'birthdays' | 'anniversaries';
    label: string;
    entries: Celebrations['birthdays'];
};

/**
 * Birthdays first, and a heading is only worth printing when there is
 * something under it.
 */
const groups = computed<Group[]>(() =>
    (
        [
            {
                id: 'birthdays',
                label: 'Birthdays',
                entries: props.celebrations.birthdays,
            },
            {
                id: 'anniversaries',
                label: 'Work anniversaries',
                entries: props.celebrations.anniversaries,
            },
        ] as Group[]
    ).filter((group) => group.entries.length > 0),
);

const anyToday = computed(
    () =>
        props.celebrations.birthdays.some((entry) => entry.is_today) ||
        props.celebrations.anniversaries.some((entry) => entry.is_today),
);

function note(
    group: Group['id'],
    entry: Celebrations['anniversaries'][number],
): string {
    if (group === 'birthdays') {
        return entry.is_today ? 'Turns a year older today' : 'Birthday';
    }

    const years = entry.years ?? 0;

    return `${years} year${years === 1 ? '' : 's'} with us`;
}
</script>

<template>
    <Panel
        eyebrow="Coming up"
        title="Celebrations"
        :subtitle="
            anyToday
                ? 'Someone is celebrating today. A word costs nothing.'
                : 'The next month, across the company.'
        "
    >
        <p v-if="!groups.length" class="text-[13px] text-muted">
            Nothing in the next month.
        </p>

        <div v-else class="space-y-5">
            <div v-for="group in groups" :key="group.id">
                <p class="eyebrow mb-2.5">{{ group.label }}</p>

                <ul class="space-y-2.5">
                    <li
                        v-for="entry in group.entries"
                        :key="`${group.id}-${entry.id}`"
                        class="flex items-center gap-3"
                    >
                        <Avatar
                            :initials="entry.initials"
                            :name="entry.name"
                            :src="entry.avatar_url"
                            size="sm"
                        />

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[13.5px] font-medium">
                                {{ entry.name }}
                            </p>
                            <p class="truncate text-[12px] text-muted">
                                {{ note(group.id, entry) }}
                                <span
                                    v-if="entry.department"
                                    class="text-faint"
                                >
                                    · {{ entry.department }}
                                </span>
                            </p>
                        </div>

                        <span
                            :class="[
                                'shrink-0 text-[12px] font-medium',
                                entry.is_today ? 'text-brass' : 'text-muted',
                            ]"
                        >
                            {{ entry.when }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </Panel>
</template>
