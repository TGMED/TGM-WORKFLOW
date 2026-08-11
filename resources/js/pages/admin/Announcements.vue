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

type AnnouncementState = 'draft' | 'scheduled' | 'live' | 'expired';

type AnnouncementRow = {
    id: number;
    title: string;
    body: string;
    is_pinned: boolean;
    published_at: string | null;
    expires_at: string | null;
    state: AnnouncementState;
    author: string | null;
    created_at: string | null;
};

const props = defineProps<{ announcements: AnnouncementRow[] }>();

const states: Record<
    AnnouncementState,
    { label: string; tone: 'signal' | 'brass' | 'neutral' }
> = {
    live: { label: 'On the dashboard', tone: 'signal' },
    scheduled: { label: 'Scheduled', tone: 'brass' },
    draft: { label: 'Draft', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'neutral' },
};

const liveCount = computed(
    () => props.announcements.filter((row) => row.state === 'live').length,
);

const today = new Date().toISOString().slice(0, 10);

const editing = ref<AnnouncementRow | null>(null);
const modalOpen = ref(false);
const deleting = ref<AnnouncementRow | null>(null);
const removing = ref(false);

const form = useForm({
    title: '',
    body: '',
    is_pinned: false,
    published_at: today as string | null,
    expires_at: null as string | null,
});

function open(row: AnnouncementRow | null) {
    editing.value = row;
    form.clearErrors();

    form.defaults({
        title: row?.title ?? '',
        body: row?.body ?? '',
        is_pinned: row?.is_pinned ?? false,
        // A new notice goes up today unless they say otherwise; clearing the
        // date is how you keep one back as a draft.
        published_at: row ? row.published_at : today,
        expires_at: row?.expires_at ?? null,
    });
    form.reset();

    modalOpen.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    };

    if (editing.value) {
        form.put(`/admin/announcements/${editing.value.id}`, options);
    } else {
        form.post('/admin/announcements', options);
    }
}

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    removing.value = true;

    router.delete(`/admin/announcements/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = false;
            deleting.value = null;
        },
    });
}

function dateLabel(value: string | null): string {
    return value
        ? new Date(`${value}T00:00:00`).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}
</script>

<template>
    <Head title="Announcements" />

    <AppLayout
        heading="Announcements"
        lede="Notices everyone sees on their dashboard"
    >
        <template #toolbar>
            <AppButton @click="open(null)">+ New announcement</AppButton>
        </template>

        <Panel
            :eyebrow="`${announcements.length} in total`"
            :title="
                liveCount === 1
                    ? '1 notice is up right now'
                    : `${liveCount} notices are up right now`
            "
            subtitle="Pinned notices sit at the top. Anything past its expiry date comes down on its own."
            flush
        >
            <EmptyState
                v-if="!announcements.length"
                title="Nothing posted yet"
                message="Announcements appear on every dashboard in the company, above the celebrations. Post the first one when there is something worth saying."
            >
                <template #action>
                    <AppButton @click="open(null)">
                        Write an announcement
                    </AppButton>
                </template>
            </EmptyState>

            <ul v-else class="divide-y divide-line-soft">
                <li
                    v-for="row in announcements"
                    :key="row.id"
                    class="px-5 py-4"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[14px] font-semibold">
                                    {{ row.title }}
                                </p>
                                <StatusPill :tone="states[row.state].tone">
                                    {{ states[row.state].label }}
                                </StatusPill>
                                <StatusPill v-if="row.is_pinned" tone="brass">
                                    Pinned
                                </StatusPill>
                            </div>

                            <p
                                class="mt-1 line-clamp-2 text-[13px] leading-relaxed whitespace-pre-line text-muted"
                            >
                                {{ row.body }}
                            </p>

                            <p class="mt-1.5 text-[12px] text-faint">
                                {{ row.author ?? 'Author no longer here' }} · up
                                {{ dateLabel(row.published_at) }} · down
                                {{
                                    row.expires_at
                                        ? dateLabel(row.expires_at)
                                        : 'when you take it down'
                                }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="open(row)"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="deleting = row"
                            >
                                Delete
                            </AppButton>
                        </div>
                    </div>
                </li>
            </ul>
        </Panel>

        <ModalShell
            :open="modalOpen"
            :title="editing ? 'Edit announcement' : 'New announcement'"
            subtitle="Everyone in the company sees this on their dashboard."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.title"
                    label="Title"
                    required
                    placeholder="e.g. Office closed on Monday"
                    :error="form.errors.title"
                />

                <TextareaField
                    v-model="form.body"
                    label="Announcement"
                    required
                    :rows="6"
                    hint="Line breaks are kept as you type them."
                    :error="form.errors.body"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.published_at"
                        label="Goes up"
                        type="date"
                        hint="Leave blank to keep it as a draft."
                        :error="form.errors.published_at"
                    />

                    <TextField
                        v-model="form.expires_at"
                        label="Comes down"
                        type="date"
                        hint="Blank means it stays until you remove it."
                        :error="form.errors.expires_at"
                    />
                </div>

                <label class="flex items-center gap-2.5">
                    <input
                        v-model="form.is_pinned"
                        type="checkbox"
                        class="size-4 rounded border-line text-brand focus:ring-brand/30"
                    />
                    <span class="text-[13.5px]">
                        Pin to the top
                        <span class="text-faint">
                            · sits above the others whatever its date
                        </span>
                    </span>
                </label>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Post announcement' }}
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="deleting !== null"
            width="md"
            title="Delete this announcement?"
            :subtitle="deleting?.title"
            @close="deleting = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                It comes off every dashboard straight away and there is no
                undoing it. To take it down without losing the wording, set a
                date in the past for when it comes down instead.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="deleting = null">
                    Cancel
                </AppButton>
                <AppButton
                    variant="danger"
                    :loading="removing"
                    @click="confirmDelete"
                >
                    Delete
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
