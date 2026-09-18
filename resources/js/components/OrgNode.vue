<script setup lang="ts">
import { computed, ref } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import type { OrgPerson } from '@/types/organogram';

const props = withDefaults(
    defineProps<{
        person: OrgPerson;
        childrenOf: Record<number, OrgPerson[]>;
        depth?: number;
        /** Collapsed by default below this depth, so a large chart opens legibly. */
        openTo?: number;
    }>(),
    { depth: 0, openTo: 2 },
);

const children = computed(() => props.childrenOf[props.person.id] ?? []);

const open = ref(props.depth < props.openTo);
</script>

<template>
    <li class="org-node">
        <div class="flex items-start gap-2">
            <button
                v-if="children.length"
                type="button"
                class="mt-3 flex size-5 shrink-0 items-center justify-center rounded-md border border-line text-[11px] text-muted transition-colors hover:bg-line-soft"
                :aria-expanded="open"
                :aria-label="open ? 'Collapse' : 'Expand'"
                @click="open = !open"
            >
                {{ open ? '−' : '+' }}
            </button>
            <span v-else class="mt-3 size-5 shrink-0" aria-hidden="true" />

            <div
                class="flex min-w-0 flex-1 items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors"
                :class="
                    person.is_you
                        ? 'border-brand/50 bg-brand/5'
                        : 'border-line-soft hover:bg-line-soft/40'
                "
            >
                <Avatar
                    :initials="person.initials"
                    :name="person.name"
                    size="sm"
                />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13.5px] font-medium">
                        {{ person.name }}
                        <span
                            v-if="person.is_you"
                            class="ml-1 text-[11px] font-normal text-brand"
                        >
                            you
                        </span>
                    </p>
                    <p class="truncate text-[11.5px] text-faint">
                        {{
                            [person.position, person.team ?? person.department]
                                .filter(Boolean)
                                .join(' · ') || 'No job title on file'
                        }}
                    </p>
                </div>

                <p
                    v-if="person.reports"
                    class="tabular shrink-0 font-mono text-[11.5px] text-muted"
                    :title="`${person.below} people below them in all`"
                >
                    {{ person.reports }}
                    <span class="text-faint">direct</span>
                </p>
            </div>
        </div>

        <ul v-if="open && children.length" class="org-children">
            <OrgNode
                v-for="child in children"
                :key="child.id"
                :person="child"
                :children-of="childrenOf"
                :depth="depth + 1"
                :open-to="openTo"
            />
        </ul>
    </li>
</template>

<style scoped>
/* The connectors are drawn in CSS rather than pulled in with a chart
   library: the shape is a nested list, and that is what it stays. */
.org-children {
    margin-left: 1.55rem;
    padding-left: 1.1rem;
    border-left: 1px solid var(--color-line-soft, rgba(128, 128, 128, 0.25));
}

.org-node {
    position: relative;
    padding-block: 0.25rem;
}
</style>
