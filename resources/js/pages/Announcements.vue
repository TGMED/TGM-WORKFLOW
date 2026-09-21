<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
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
    per_page: number;
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
            <div class="overflow-x-auto">
                <table class="w-full text-[13.5px]">
                    <thead
                        class="border-b border-line-soft text-left text-[12px] text-faint"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Notice</th>
                            <th class="px-5 py-3 font-medium">Posted by</th>
                            <th class="px-5 py-3 font-medium">Published</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-line-soft">
                        <tr v-if="announcements.data.length === 0">
                            <td colspan="4">
                                <EmptyState
                                    title="Nothing to report"
                                    message="Company notices will appear here when there are any."
                                />
                            </td>
                        </tr>
                        <tr
                            v-for="notice in announcements.data"
                            :key="notice.id"
                            class="cursor-pointer transition-colors hover:bg-sunken/40"
                            @click="reading = notice"
                        >
                            <td class="px-5 py-3.5">
                                <p
                                    class="flex flex-wrap items-center gap-2 font-medium"
                                >
                                    {{ notice.title }}
                                    <StatusPill
                                        v-if="notice.is_pinned"
                                        tone="beacon"
                                    >
                                        Pinned
                                    </StatusPill>
                                </p>
                                <p
                                    class="max-w-[32rem] truncate text-[12px] text-faint"
                                    :title="notice.excerpt"
                                >
                                    {{ notice.excerpt }}
                                </p>
                            </td>
                            <td class="px-5 py-3.5 text-muted">
                                {{ notice.author ?? 'A former administrator' }}
                            </td>
                            <td
                                class="px-5 py-3.5 whitespace-nowrap text-muted"
                            >
                                {{ relative(notice.published_at) }}
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    @click.stop="reading = notice"
                                >
                                    Read
                                </AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination
                :links="announcements.links"
                :per-page="announcements.per_page"
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
