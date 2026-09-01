<script setup lang="ts">
import { ref } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { relative } from '@/lib/format';
import type { Announcement } from '@/types';

defineProps<{ announcements: Announcement[] }>();

// Notices are written to be read in full, so the panel shows the opening and
// the whole thing opens in place.
const reading = ref<Announcement | null>(null);
</script>

<template>
    <Panel eyebrow="Notices" title="From the company" flush>
        <EmptyState
            v-if="announcements.length === 0"
            title="Nothing to report"
            message="Company notices will appear here when there are any."
        />

        <ul v-else class="divide-y divide-line-soft">
            <li v-for="notice in announcements" :key="notice.id">
                <button
                    type="button"
                    class="w-full px-5 py-4 text-left transition-colors hover:bg-line-soft/50"
                    @click="reading = notice"
                >
                    <p
                        class="flex flex-wrap items-center gap-2 text-[14px] font-semibold tracking-tight"
                    >
                        {{ notice.title }}
                        <StatusPill v-if="notice.is_pinned" tone="beacon">
                            Pinned
                        </StatusPill>
                    </p>
                    <p class="mt-0.5 text-[13px] leading-relaxed text-muted">
                        {{ notice.excerpt }}
                    </p>
                    <p class="mt-1 text-[12px] text-faint">
                        {{ notice.author ?? 'A former administrator' }} ·
                        {{ relative(notice.published_at) }}
                    </p>
                </button>
            </li>
        </ul>

        <ModalShell
            :open="reading !== null"
            :title="reading?.title ?? ''"
            :subtitle="
                reading
                    ? `${reading.author ?? 'A former administrator'} · ${relative(reading.published_at)}`
                    : undefined
            "
            @close="reading = null"
        >
            <p
                class="text-[14px] leading-relaxed whitespace-pre-line text-muted"
            >
                {{ reading?.body }}
            </p>
        </ModalShell>
    </Panel>
</template>
