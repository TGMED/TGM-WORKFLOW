<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import WhatsNewNotes from '@/components/WhatsNewNotes.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { WhatsNewRelease } from '@/types';

defineProps<{ releases: WhatsNewRelease[] }>();

// A bare Y-m-d would be read as midnight UTC and can land on the day before
// west of Greenwich, so it is pinned to local midnight first.
function released(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="What's new" />

    <AppLayout
        heading="What's new"
        lede="Everything that has landed, newest first, and what has been put right along the way."
    >
        <EmptyState
            v-if="releases.length === 0"
            title="Nothing yet"
            message="Release notes will appear here as things ship."
        />

        <div v-else class="space-y-5">
            <Panel
                v-for="(release, index) in releases"
                :key="release.version"
                :eyebrow="`Version ${release.version}`"
                :title="released(release.date)"
            >
                <template v-if="index === 0" #action>
                    <StatusPill tone="signal">Latest</StatusPill>
                </template>

                <WhatsNewNotes :release="release" />
            </Panel>
        </div>
    </AppLayout>
</template>
