<script setup lang="ts">
withDefaults(
    defineProps<{
        eyebrow?: string;
        title?: string;
        subtitle?: string;
        flush?: boolean;
    }>(),
    { flush: false },
);
</script>

<template>
    <section
        class="min-w-0 overflow-hidden rounded-2xl border border-line bg-panel shadow-panel"
    >
        <header
            v-if="eyebrow || title || $slots.action"
            class="flex items-start justify-between gap-4 border-b border-line-soft px-5 py-4"
        >
            <div class="min-w-0">
                <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
                <h2
                    v-if="title"
                    class="mt-1 truncate font-display text-[17px] font-semibold tracking-tight"
                >
                    {{ title }}
                </h2>
                <p v-if="subtitle" class="mt-0.5 text-[13px] text-muted">
                    {{ subtitle }}
                </p>
            </div>

            <div class="shrink-0"><slot name="action" /></div>
        </header>

        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
