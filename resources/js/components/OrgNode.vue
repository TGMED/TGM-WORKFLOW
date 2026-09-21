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
        /**
         * Handed down rather than emitted: the component recurses, and an
         * event would have to be re-raised at every rung to reach the page.
         */
        onManage?: (person: OrgPerson) => void;
    }>(),
    { depth: 0, openTo: 2 },
);

const children = computed(() => props.childrenOf[props.person.id] ?? []);

const open = ref(props.depth < props.openTo);

/*
 * A colour a rung, so the eye can tell the levels apart at a glance without
 * reading a word. Past the third rung they all share the quiet one: a chart
 * that deep is being read a branch at a time, not whole.
 */
const accents = ['var(--org-1)', 'var(--org-2)', 'var(--org-3)'] as const;

const accent = computed(() => accents[props.depth] ?? 'var(--text-faint)');

const size = computed(() => {
    if (props.depth === 0) {
        return 'lg' as const;
    }

    return props.depth === 1 ? ('md' as const) : ('sm' as const);
});

const nameSize = computed(() =>
    props.depth >= 2 ? 'text-[11.5px]' : 'text-[12.5px]',
);

const role = computed(
    () =>
        [props.person.position, props.person.team ?? props.person.department]
            .filter(Boolean)
            .join(' · ') || 'No job title on file',
);
</script>

<template>
    <li class="org-node" :style="{ '--org-accent': accent }">
        <div
            class="org-card"
            :class="[depth >= 2 ? 'w-[108px]' : 'w-[148px]']"
            :title="`${person.name} — ${role}`"
        >
            <span
                class="org-ring"
                :class="person.is_you ? 'org-ring--you' : ''"
            >
                <Avatar
                    :initials="person.initials"
                    :name="person.name"
                    :size="size"
                    circle
                />
            </span>

            <p
                class="mt-2 line-clamp-2 text-center leading-tight font-semibold"
                :class="nameSize"
            >
                {{ person.name }}
                <span v-if="person.is_you" class="font-normal text-brand">
                    (you)
                </span>
            </p>

            <p
                class="mt-0.5 line-clamp-2 text-center text-[10.5px] leading-tight"
                :style="{ color: 'var(--org-accent)' }"
            >
                {{ role }}
            </p>

            <div v-if="onManage || children.length" class="org-actions">
                <button
                    v-if="onManage"
                    type="button"
                    class="org-move"
                    :title="`Change who ${person.name} reports to`"
                    @click="onManage(person)"
                >
                    Move
                </button>

                <button
                    v-if="children.length"
                    type="button"
                    class="org-toggle"
                    :aria-expanded="open"
                    :aria-label="
                        open
                            ? `Hide the ${children.length} reporting to ${person.name}`
                            : `Show the ${children.length} reporting to ${person.name}`
                    "
                    :title="`${person.below} people below them in all`"
                    @click="open = !open"
                >
                    {{ open ? '−' : `+${children.length}` }}
                </button>
            </div>
        </div>

        <ul v-if="open && children.length" class="org-branch">
            <OrgNode
                v-for="child in children"
                :key="child.id"
                :person="child"
                :children-of="childrenOf"
                :depth="depth + 1"
                :open-to="openTo"
                :on-manage="onManage"
            />
        </ul>
    </li>
</template>

<style scoped>
/* The connectors are drawn in CSS rather than pulled in with a chart library:
   the shape is still a nested list, and the elbows are borders on the two
   pseudo-elements each row already has. */
.org-node {
    --org-gap: 1.5rem;
    --org-wire: 2px;

    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-inline: 0.4rem;
}

.org-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 1;
}

.org-ring {
    display: grid;
    place-items: center;
    padding: 0.25rem;
    border-radius: 9999px;
    background: var(--panel);
    box-shadow:
        0 0 0 2px var(--org-accent),
        var(--shadow-panel);
}

.org-ring--you {
    box-shadow:
        0 0 0 2px var(--org-accent),
        0 0 0 6px color-mix(in oklab, var(--org-accent) 22%, transparent);
}

.org-actions {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.4rem;
}

.org-toggle {
    min-width: 1.4rem;
    height: 1.25rem;
    padding-inline: 0.3rem;
    border-radius: 9999px;
    border: 1px solid var(--line);
    background: var(--panel);
    font-size: 10.5px;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: var(--text-muted);
    transition:
        background-color 150ms,
        border-color 150ms,
        color 150ms;
}

.org-toggle:hover {
    border-color: var(--org-accent);
    color: var(--org-accent);
}

.org-move {
    padding: 0.1rem 0.45rem;
    border-radius: 9999px;
    border: 1px dashed var(--line);
    background: transparent;
    font-size: 10px;
    line-height: 1.5;
    color: var(--text-faint);
    transition:
        border-color 150ms,
        color 150ms;
}

.org-move:hover {
    border-style: solid;
    border-color: var(--org-accent);
    color: var(--org-accent);
}

/* The children of a node: one stem down out of the card, a bar across them,
   then a stem down into each. */
.org-branch {
    /* Resolved here, in the parent's scope, so every wire below a card takes
       that card's colour rather than the colour of whoever hangs off it. */
    --org-wire-color: var(--org-accent);

    position: relative;
    display: flex;
    justify-content: center;
    padding-top: var(--org-gap);
}

.org-branch::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    width: var(--org-wire);
    height: var(--org-gap);
    transform: translateX(-50%);
    background: var(--org-wire-color);
}

.org-branch > .org-node {
    padding-top: var(--org-gap);
}

/* ::before is the left half of the bar, ::after the right half plus the drop
   into the card, so both halves and the elbow come out of one row. */
.org-branch > .org-node::before,
.org-branch > .org-node::after {
    content: '';
    position: absolute;
    top: 0;
    width: 50%;
    height: var(--org-gap);
    border-top: var(--org-wire) solid var(--org-wire-color);
}

.org-branch > .org-node::before {
    right: 50%;
}

.org-branch > .org-node::after {
    left: 50%;
    border-left: var(--org-wire) solid var(--org-wire-color);
}

/* The outermost children turn a corner instead of running on into nothing. */
.org-branch > .org-node:first-child::before {
    border-top-color: transparent;
}

.org-branch > .org-node:last-child::after {
    border-top-color: transparent;
}

.org-branch > .org-node:first-child::after {
    border-top-left-radius: 0.5rem;
}

.org-branch > .org-node:last-child::before {
    border-top-right-radius: 0.5rem;
}

/* An only child needs no bar at all, just the drop. */
.org-branch > .org-node:only-child::before {
    display: none;
}

@media (prefers-reduced-motion: reduce) {
    .org-toggle,
    .org-move {
        transition: none;
    }
}
</style>
