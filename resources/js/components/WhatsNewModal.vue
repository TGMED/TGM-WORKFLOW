<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import WhatsNewNotes from '@/components/WhatsNewNotes.vue';
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
        <WhatsNewNotes :release="notes" />

        <template #footer>
            <Link
                href="/whats-new"
                class="mr-auto text-[13px] text-muted transition-colors hover:text-text"
                @click="open = false"
            >
                Every release
            </Link>
            <AppButton @click="dismiss">Got it</AppButton>
        </template>
    </ModalShell>
</template>
