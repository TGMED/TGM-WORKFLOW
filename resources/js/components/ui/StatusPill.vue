<script setup lang="ts">
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        tone?: 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';
        dot?: boolean;
        pulse?: boolean;
    }>(),
    { tone: 'neutral', dot: false, pulse: false },
);

// The class attribute is consumed by hand: Vue would otherwise append it to
// the base list, and an override like "hidden" would lose to the "inline-flex"
// below on stylesheet order rather than winning as written.
defineOptions({ inheritAttrs: false });

const attrs = useAttrs();

const rest = computed(() => {
    const { class: _class, ...remaining } = attrs;

    return remaining;
});

const tones = {
    signal: 'bg-signal-soft text-signal',
    brass: 'bg-brass-soft text-brass',
    alert: 'bg-alert-soft text-alert',
    beacon: 'bg-beacon-soft text-beacon',
    neutral: 'bg-line-soft text-muted',
} as const;

const classes = computed(() =>
    cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1',
        'text-[11.5px] font-semibold tracking-tight whitespace-nowrap',
        tones[props.tone],
        attrs.class as string | undefined,
    ),
);
</script>

<template>
    <span v-bind="rest" :class="classes">
        <span v-if="dot" class="relative flex size-1.5">
            <span
                v-if="pulse"
                class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-70"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full bg-current"
            />
        </span>
        <slot />
    </span>
</template>
