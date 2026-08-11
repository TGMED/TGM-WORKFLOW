<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Panel from '@/components/ui/Panel.vue';
import type { DashboardAnnouncement } from '@/types';

defineProps<{
    announcements: DashboardAnnouncement[];
    canManage: boolean;
}>();
</script>

<template>
    <Panel eyebrow="From the people team" title="Announcements">
        <template v-if="canManage" #action>
            <Link
                href="/admin/announcements"
                class="text-[12.5px] font-medium text-muted transition-colors hover:text-text"
            >
                Manage →
            </Link>
        </template>

        <p v-if="!announcements.length" class="text-[13px] text-muted">
            Nothing posted at the moment.
        </p>

        <ul v-else class="space-y-4">
            <li
                v-for="announcement in announcements"
                :key="announcement.id"
                class="border-l-2 pl-3.5"
                :class="
                    announcement.is_pinned ? 'border-brass' : 'border-line-soft'
                "
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="min-w-0 text-[13.5px] font-semibold">
                        {{ announcement.title }}
                    </p>
                    <span
                        v-if="announcement.is_pinned"
                        class="shrink-0 text-[11px] font-medium tracking-wide text-brass uppercase"
                    >
                        Pinned
                    </span>
                </div>

                <!-- Kept as typed, line breaks and all, without letting any
                     markup through. -->
                <p
                    class="mt-1 text-[13px] leading-relaxed whitespace-pre-line text-muted"
                >
                    {{ announcement.body }}
                </p>

                <p class="mt-1.5 text-[12px] text-faint">
                    {{ announcement.author ?? 'The people team' }}
                    <template v-if="announcement.published_label">
                        · {{ announcement.published_label }}
                    </template>
                </p>
            </li>
        </ul>
    </Panel>
</template>
