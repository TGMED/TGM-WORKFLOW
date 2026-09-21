<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        initials: string;
        name?: string;
        size?: 'sm' | 'md' | 'lg' | 'xl';
        muted?: boolean;
        /** A photo, if this person has uploaded one. Falls back to initials. */
        src?: string | null;
        /**
         * Round rather than the usual squircle. A circle has no corners to
         * spend, so the initials are set larger to fill the same optical area.
         */
        circle?: boolean;
    }>(),
    { size: 'md', muted: false, circle: false },
);

const boxes = {
    sm: 'size-8',
    md: 'size-10',
    lg: 'size-14',
    xl: 'size-20',
} as const;

/*
 * Type scaled to the box rather than picked per size, so initials sit at the
 * same weight in the frame whichever avatar you are looking at. The round
 * variant runs larger: the corners a squircle uses are not there to be read
 * into, so the same glyph looks smaller inside a circle than inside a square.
 */
const text = {
    sm: ['text-[11px]', 'text-[12.5px]'],
    md: ['text-[13px]', 'text-[15.5px]'],
    lg: ['text-base', 'text-[21px]'],
    xl: ['text-2xl', 'text-[30px]'],
} as const;

const glyph = computed(() => text[props.size][props.circle ? 1 : 0]);

const rounding = computed(() => (props.circle ? 'rounded-full' : 'rounded-xl'));

/**
 * Deterministic hue per person so the same face keeps the same colour
 * everywhere in the app.
 */
const hue = computed(() => {
    const source = props.name ?? props.initials;
    let total = 0;

    for (let i = 0; i < source.length; i++) {
        total = (total * 31 + source.charCodeAt(i)) % 360;
    }

    return total;
});
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :alt="name ?? ''"
        :class="['shrink-0 object-cover ring-1 ring-line', rounding, boxes[size]]"
        :title="name"
    />

    <span
        v-else
        :class="[
            'inline-grid shrink-0 place-items-center font-semibold tracking-tight select-none',
            'ring-1 ring-inset',
            // Without this the line box is taller than the capitals and
            // centring the box leaves the letters sitting high in it.
            'leading-none',
            rounding,
            boxes[size],
            glyph,
        ]"
        :style="
            muted
                ? undefined
                : {
                      backgroundColor: `oklch(0.32 0.06 ${hue})`,
                      color: `oklch(0.9 0.11 ${hue})`,
                      '--tw-ring-color': `oklch(0.45 0.08 ${hue} / 0.4)`,
                  }
        "
        :title="name"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
