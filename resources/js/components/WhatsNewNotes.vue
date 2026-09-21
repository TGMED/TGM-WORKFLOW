<script setup lang="ts">
import type { WhatsNewRelease } from '@/types';

/**
 * One release's notes, new things first and fixes after them, shared by the
 * popup and the page so the two read the same.
 */
defineProps<{ release: WhatsNewRelease }>();
</script>

<template>
    <div class="space-y-6">
        <section v-if="release.features.length > 0">
            <p class="eyebrow mb-3">New</p>
            <ul class="space-y-4">
                <li
                    v-for="feature in release.features"
                    :key="feature.title"
                    class="flex gap-3"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full bg-brand"
                        aria-hidden="true"
                    />
                    <div class="min-w-0">
                        <p class="text-[14px] font-semibold tracking-tight">
                            {{ feature.title }}
                        </p>
                        <p
                            class="mt-0.5 text-[13px] leading-relaxed text-muted"
                        >
                            {{ feature.description }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="release.fixes.length > 0">
            <p class="eyebrow mb-3">Fixed</p>
            <ul class="space-y-4">
                <li
                    v-for="fix in release.fixes"
                    :key="fix.title"
                    class="flex gap-3"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full bg-signal"
                        aria-hidden="true"
                    />
                    <div class="min-w-0">
                        <p class="text-[14px] font-semibold tracking-tight">
                            {{ fix.title }}
                        </p>
                        <p
                            class="mt-0.5 text-[13px] leading-relaxed text-muted"
                        >
                            {{ fix.description }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
