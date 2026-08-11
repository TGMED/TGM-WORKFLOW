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
    }>(),
    { size: 'md', muted: false },
);

const sizes = {
    sm: 'size-8 text-[11px]',
    md: 'size-10 text-[13px]',
    lg: 'size-14 text-base',
    xl: 'size-20 text-2xl',
} as const;

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
        :class="[
            'shrink-0 rounded-xl object-cover ring-1 ring-line',
            sizes[size].split(' ')[0],
        ]"
        :title="name"
    />

    <span
        v-else
        :class="[
            'inline-grid shrink-0 place-items-center rounded-xl font-semibold tracking-tight select-none',
            'ring-1 ring-inset',
            sizes[size],
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
