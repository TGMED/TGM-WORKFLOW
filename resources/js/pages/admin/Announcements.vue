<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusFilter from '@/components/ui/StatusFilter.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import { usePaginated } from '@/composables/usePaginated';
import { useStatusFilter } from '@/composables/useStatusFilter';
import AppLayout from '@/layouts/AppLayout.vue';
import { relative, shortDate } from '@/lib/format';
import type { AnnouncementState, AnnouncementRow } from '@/types';

const props = defineProps<{
    announcements: AnnouncementRow[];
    audience: number;
}>();

const editing = ref<AnnouncementRow | null>(null);
const open = ref(false);

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    title: '',
    body: '',
    is_pinned: false,
    published_at: today as string | null,
    expires_at: null as string | null,
});

const tones: Record<AnnouncementState, 'signal' | 'brass' | 'neutral'> = {
    live: 'signal',
    scheduled: 'brass',
    draft: 'neutral',
    expired: 'neutral',
};

const labels: Record<AnnouncementState, string> = {
    live: 'Live',
    scheduled: 'Scheduled',
    draft: 'Draft',
    expired: 'Expired',
};

const live = computed(() =>
    props.announcements.filter((row) => row.state === 'live'),
);

const rest = computed(() =>
    props.announcements.filter((row) => row.state !== 'live'),
);

// Saving is what sends a notice, and only the first time it goes up, so the
// button says which of the two this will be.
const action = computed(() => {
    if (!form.published_at || form.published_at > today) {
        return 'Save';
    }

    if (editing.value?.notified_at) {
        return 'Save';
    }

    return `Publish and send to ${props.audience} ${props.audience === 1 ? 'person' : 'people'}`;
});

function compose() {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        title: '',
        body: '',
        is_pinned: false,
        published_at: today,
        expires_at: null,
    });
    form.reset();
    open.value = true;
}

function edit(row: AnnouncementRow) {
    editing.value = row;
    form.clearErrors();
    form.defaults({
        title: row.title,
        body: row.body,
        is_pinned: row.is_pinned,
        published_at: row.published_at,
        expires_at: row.expires_at,
    });
    form.reset();
    open.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(`/admin/announcements/${editing.value.id}`, options);

        return;
    }

    form.post('/admin/announcements', options);
}

function remove(row: AnnouncementRow) {
    router.delete(`/admin/announcements/${row.id}`, { preserveScroll: true });
}

const livePages = usePaginated(() => live.value);
const states = useStatusFilter(
    () => rest.value,
    (row) => ({ value: row.state, label: labels[row.state] }),
);
const restPages = usePaginated(() => states.rows, {
    resetOn: () => states.status,
});
</script>

<template>
    <Head title="Announcements" />

    <AppLayout heading="Announcements" lede="Notices to the whole company">
        <template #toolbar>
            <AppButton size="sm" @click="compose">Write a notice</AppButton>
        </template>

        <div class="space-y-6">
            <Panel
                eyebrow="On the dashboard"
                title="Live now"
                :subtitle="`Everyone active — ${audience} ${audience === 1 ? 'person' : 'people'} — is written to when a notice first goes up.`"
                flush
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Notice</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Author</th>
                                <th class="px-5 py-3 font-medium">Timing</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="live.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        title="Nothing live"
                                        message="Notices you publish appear on everyone's dashboard and land in their inbox."
                                    >
                                        <template #action>
                                            <AppButton
                                                size="sm"
                                                @click="compose"
                                            >
                                                Write a notice
                                            </AppButton>
                                        </template>
                                    </EmptyState>
                                </td>
                            </tr>
                            <tr
                                v-for="row in livePages.paged"
                                :key="row.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">
                                        {{ row.title }}
                                    </p>
                                    <p
                                        class="max-w-[26rem] truncate text-[12px] text-faint"
                                        :title="row.excerpt"
                                    >
                                        {{ row.excerpt }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        v-if="row.is_pinned"
                                        tone="beacon"
                                    >
                                        Pinned
                                    </StatusPill>
                                    <StatusPill v-else tone="signal">
                                        Live
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ row.author ?? 'A former administrator' }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] text-muted"
                                >
                                    <p>
                                        Up
                                        {{
                                            relative(
                                                row.notified_at ??
                                                    row.created_at,
                                            )
                                        }}
                                    </p>
                                    <p v-if="row.expires_at" class="text-faint">
                                        Comes down
                                        {{ shortDate(row.expires_at) }}
                                    </p>
                                    <p
                                        v-if="!row.notified_at"
                                        class="text-faint"
                                    >
                                        Not sent
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex justify-end gap-1">
                                        <AppButton
                                            size="sm"
                                            variant="ghost"
                                            @click="edit(row)"
                                        >
                                            Edit
                                        </AppButton>
                                        <AppButton
                                            size="sm"
                                            variant="ghost"
                                            @click="remove(row)"
                                        >
                                            Delete
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="livePages.page"
                    v-model:per-page="livePages.perPage"
                    :last-page="livePages.lastPage"
                    :from="livePages.from"
                    :to="livePages.to"
                    :total="livePages.total"
                />
            </Panel>

            <Panel
                eyebrow="Not on the dashboard"
                title="Drafts, scheduled and expired"
                flush
            >
                <template v-if="states.useful" #action>
                    <StatusFilter v-model="states.status" :filter="states" />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Notice</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Author</th>
                                <th class="px-5 py-3 font-medium">Timing</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="rest.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        :title="'Nothing waiting'"
                                        message="Drafts, notices scheduled for later and ones that have come down are kept here."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in restPages.paged"
                                :key="row.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ row.title }}</p>
                                    <p
                                        class="max-w-[26rem] truncate text-[12px] text-faint"
                                        :title="row.excerpt"
                                    >
                                        {{ row.excerpt }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill :tone="tones[row.state]">
                                        {{ labels[row.state] }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ row.author ?? 'A former administrator' }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] text-muted"
                                >
                                    <p v-if="row.state === 'scheduled'">
                                        Goes up
                                        {{ shortDate(row.published_at) }}
                                    </p>
                                    <p v-else-if="row.state === 'expired'">
                                        Came down
                                        {{ shortDate(row.expires_at) }}
                                    </p>
                                    <p v-else>
                                        Written {{ relative(row.created_at) }}
                                    </p>
                                    <p
                                        v-if="row.notified_at"
                                        class="text-faint"
                                    >
                                        Already sent
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex justify-end gap-1">
                                        <AppButton
                                            size="sm"
                                            variant="ghost"
                                            @click="edit(row)"
                                        >
                                            Edit
                                        </AppButton>
                                        <AppButton
                                            size="sm"
                                            variant="ghost"
                                            @click="remove(row)"
                                        >
                                            Delete
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="restPages.page"
                    v-model:per-page="restPages.perPage"
                    :last-page="restPages.lastPage"
                    :from="restPages.from"
                    :to="restPages.to"
                    :total="restPages.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="open"
            :title="editing ? 'Edit notice' : 'Write a notice'"
            subtitle="A notice goes out by email and push the first time it goes up."
            width="xl"
            @close="open = false"
        >
            <form id="announcement" class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.title"
                    label="Heading"
                    required
                    placeholder="Office closed on Friday"
                    :error="form.errors.title"
                />

                <TextareaField
                    v-model="form.body"
                    label="Notice"
                    :rows="8"
                    required
                    placeholder="Write it as you would say it. Leave a blank line between paragraphs."
                    :error="form.errors.body"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.published_at"
                        label="Goes up"
                        type="date"
                        hint="Leave blank to keep it a draft."
                        :error="form.errors.published_at"
                    />
                    <TextField
                        v-model="form.expires_at"
                        label="Comes down"
                        type="date"
                        hint="Optional. It stays up until then."
                        :error="form.errors.expires_at"
                    />
                </div>

                <label class="flex items-center gap-2 text-[13px] text-muted">
                    <input
                        v-model="form.is_pinned"
                        type="checkbox"
                        class="size-4 rounded border-line accent-brand"
                    />
                    Pin to the top of the dashboard
                </label>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="open = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="announcement"
                    :loading="form.processing"
                >
                    {{ action }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
