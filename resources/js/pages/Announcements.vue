<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { relative } from '@/lib/format';
import type { Announcement } from '@/types';

type Paginated = {
    data: Announcement[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

defineProps<{ announcements: Paginated }>();

// Notices are written to be read in full, so the list shows the opening and
// the whole thing opens in place.
const reading = ref<Announcement | null>(null);
</script>

<template>
    <Head title="Announcements" />

    <AppLayout
        heading="Announcements"
        lede="What the company has told everyone. Pinned notices stay at the top."
    >
        <Panel flush>
            <EmptyState
                v-if="announcements.data.length === 0"
                title="Nothing to report"
                message="Company notices will appear here when there are any."
            />

            <ul v-else class="divide-y divide-line-soft">
                <li v-for="notice in announcements.data" :key="notice.id">
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
                        <p
                            class="mt-0.5 text-[13px] leading-relaxed text-muted"
                        >
                            {{ notice.excerpt }}
                        </p>
                        <p class="mt-1 text-[12px] text-faint">
                            {{ notice.author ?? 'A former administrator' }} ·
                            {{ relative(notice.published_at) }}
                        </p>
                    </button>
                </li>
            </ul>

            <Pagination
                :links="announcements.links"
                :from="announcements.from"
                :to="announcements.to"
                :total="announcements.total"
            />
        </Panel>

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
    </AppLayout>
</template>
