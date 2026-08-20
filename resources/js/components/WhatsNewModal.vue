<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();

const notes = computed(() => page.props.whats_new);

const open = ref(false);

// Held back until the props say there is something unread, so a page that
// arrives without them does not flash an empty dialog.
watch(
    notes,
    (value) => {
        open.value = value !== null;
    },
    { immediate: true },
);

/**
 * Closing is the same as reading it: the server is told, which is what stops
 * it coming back on the next visit or the next device.
 */
function dismiss() {
    open.value = false;

    router.post('/whats-new/seen', {}, { preserveScroll: true });
}
</script>

<template>
    <ModalShell
        v-if="notes"
        :open="open"
        :title="notes.title"
        :subtitle="notes.lede"
        width="xl"
        @close="dismiss"
    >
        <ul class="space-y-4">
            <li
                v-for="feature in notes.features"
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
                    <p class="mt-0.5 text-[13px] leading-relaxed text-muted">
                        {{ feature.description }}
                    </p>
                </div>
            </li>
        </ul>

        <template #footer>
            <AppButton @click="dismiss">Got it</AppButton>
        </template>
    </ModalShell>
</template>
