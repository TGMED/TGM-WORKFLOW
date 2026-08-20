<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
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
                <EmptyState
                    v-if="live.length === 0"
                    title="Nothing live"
                    message="Notices you publish appear on everyone's dashboard and land in their inbox."
                >
                    <template #action>
                        <AppButton size="sm" @click="compose">
                            Write a notice
                        </AppButton>
                    </template>
                </EmptyState>

                <ul v-else class="divide-y divide-line-soft">
                    <li
                        v-for="row in live"
                        :key="row.id"
                        class="flex items-start gap-4 px-5 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex flex-wrap items-center gap-2 text-[14px] font-semibold tracking-tight"
                            >
                                {{ row.title }}
                                <StatusPill v-if="row.is_pinned" tone="beacon">
                                    Pinned
                                </StatusPill>
                            </p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                {{ row.excerpt }}
                            </p>
                            <p class="mt-1 text-[12px] text-faint">
                                {{ row.author ?? 'A former administrator' }} ·
                                up
                                {{
                                    relative(row.notified_at ?? row.created_at)
                                }}
                                <template v-if="row.expires_at">
                                    · comes down
                                    {{ shortDate(row.expires_at) }}
                                </template>
                                <template v-if="!row.notified_at">
                                    · not sent
                                </template>
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1">
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
                    </li>
                </ul>
            </Panel>

            <Panel
                v-if="rest.length"
                eyebrow="Not on the dashboard"
                title="Drafts, scheduled and expired"
                flush
            >
                <ul class="divide-y divide-line-soft">
                    <li
                        v-for="row in rest"
                        :key="row.id"
                        class="flex items-start gap-4 px-5 py-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex flex-wrap items-center gap-2 text-[14px] font-semibold tracking-tight"
                            >
                                {{ row.title }}
                                <StatusPill :tone="tones[row.state]">
                                    {{ labels[row.state] }}
                                </StatusPill>
                            </p>
                            <p class="mt-0.5 text-[13px] text-muted">
                                {{ row.excerpt }}
                            </p>
                            <p class="mt-1 text-[12px] text-faint">
                                {{ row.author ?? 'A former administrator' }}
                                <template v-if="row.state === 'scheduled'">
                                    · goes up
                                    {{ shortDate(row.published_at) }}
                                </template>
                                <template v-else-if="row.state === 'expired'">
                                    · came down
                                    {{ shortDate(row.expires_at) }}
                                </template>
                                <template v-else>
                                    · written {{ relative(row.created_at) }}
                                </template>
                                <template v-if="row.notified_at">
                                    · already sent
                                </template>
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1">
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
                    </li>
                </ul>
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
