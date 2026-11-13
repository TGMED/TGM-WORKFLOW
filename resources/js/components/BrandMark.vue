<script setup lang="ts">
import ChristmasCap from '@/components/festive/ChristmasCap.vue';
import ChristmasLights from '@/components/festive/ChristmasLights.vue';
import { isFestive } from '@/lib/festive';

/**
 * Two artworks ship with the brand: dark ink for light surfaces and light ink
 * for dark ones. Swapping the two by theme lets the mark sit directly on the
 * surface rather than on a white plate. The wordmark is close to 10:1, so the
 * heights below stay small enough to keep it inside the 264px nav rail.
 */
withDefaults(
    defineProps<{
        size?: 'sm' | 'md' | 'lg';
        subtitle?: string;
    }>(),
    { size: 'md' },
);

const marks = {
    sm: 'h-5',
    md: 'h-6',
    lg: 'h-7',
} as const;

// The cap sits tipped over the "T", sized to the mark it is on.
const caps = {
    sm: '-left-2 -top-3 w-6',
    md: '-left-2.5 -top-3.5 w-7',
    lg: '-left-3 -top-4 w-8',
} as const;

const festive = isFestive();
</script>

<template>
    <div class="flex items-center gap-3">
        <span class="relative inline-flex shrink-0 items-center">
            <!-- The lights wind round the logo: the back strands are drawn
                 under it, the front strands over it. -->
            <ChristmasLights
                v-if="festive"
                layer="back"
                class="absolute inset-x-0 top-1/2 w-full -translate-y-1/2"
            />
            <img
                src="/images/tgm-logo.png"
                alt="TGM Education"
                :class="['relative w-auto dark:hidden', marks[size]]"
                width="932"
                height="98"
                decoding="async"
            />
            <img
                src="/images/tgm-logo-dark.png"
                alt=""
                aria-hidden="true"
                :class="['relative hidden w-auto dark:block', marks[size]]"
                width="932"
                height="98"
                decoding="async"
            />
            <ChristmasLights
                v-if="festive"
                layer="front"
                class="absolute inset-x-0 top-1/2 w-full -translate-y-1/2"
            />
            <ChristmasCap
                v-if="festive"
                :class="['absolute -rotate-[18deg]', caps[size]]"
            />
        </span>

        <span
            v-if="subtitle"
            class="h-6 w-px shrink-0 bg-line"
            aria-hidden="true"
        />
        <p v-if="subtitle" class="eyebrow truncate">{{ subtitle }}</p>
    </div>
</template>
