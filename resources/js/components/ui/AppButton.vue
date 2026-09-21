<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'sm' | 'md' | 'lg';
        loading?: boolean;
        disabled?: boolean;
        type?: 'button' | 'submit' | 'reset';
        block?: boolean;
        /**
         * Render an anchor rather than a button. For the handful of actions
         * that are really a URL — downloading a file, chiefly — where an
         * Inertia link would try to read a page out of the response.
         */
        href?: string;
    }>(),
    {
        variant: 'primary',
        size: 'md',
        loading: false,
        disabled: false,
        type: 'button',
        block: false,
    },
);

const variants = {
    primary:
        'bg-brand text-white hover:brightness-110 active:brightness-95 shadow-[0_1px_0_rgba(255,255,255,0.25)_inset]',
    secondary:
        'bg-panel-raised text-text border border-line hover:border-faint hover:bg-line-soft',
    ghost: 'text-muted hover:text-text hover:bg-line-soft',
    danger: 'bg-alert text-white hover:brightness-110 active:brightness-95',
} as const;

const sizes = {
    sm: 'h-8 px-3 text-[13px] gap-1.5 rounded-lg',
    md: 'h-10 px-4 text-sm gap-2 rounded-xl',
    lg: 'h-12 px-6 text-[15px] gap-2.5 rounded-xl',
} as const;

const classes = computed(() =>
    cn(
        'relative inline-flex items-center justify-center font-medium whitespace-nowrap',
        'transition-all duration-200 ease-out select-none',
        'disabled:pointer-events-none disabled:opacity-45',
        'active:scale-[0.98]',
        variants[props.variant],
        sizes[props.size],
        props.block && 'w-full',
    ),
);
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :type="href ? undefined : type"
        :href="href"
        :class="classes"
        :disabled="href ? undefined : disabled || loading"
        :aria-disabled="href && (disabled || loading) ? 'true' : undefined"
    >
        <span
            v-if="loading"
            class="absolute inset-0 grid place-items-center"
            aria-hidden="true"
        >
            <svg class="size-4 animate-sweep" viewBox="0 0 24 24" fill="none">
                <circle
                    cx="12"
                    cy="12"
                    r="9"
                    stroke="currentColor"
                    stroke-opacity="0.25"
                    stroke-width="2.5"
                />
                <path
                    d="M21 12a9 9 0 0 0-9-9"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                />
            </svg>
        </span>
        <span
            :class="[
                'inline-flex items-center gap-[inherit] transition-opacity',
                loading ? 'opacity-0' : 'opacity-100',
            ]"
        >
            <slot />
        </span>
    </component>
</template>
