<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusFilter from '@/components/ui/StatusFilter.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import { usePaginated } from '@/composables/usePaginated';
import { useStatusFilter } from '@/composables/useStatusFilter';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, fullDate } from '@/lib/format';
import type { ReportCategoryOption, ReportStatusTone } from '@/types';

type ReportRow = {
    id: number;
    category: string;
    category_label: string;
    subject: string;
    body: string;
    against: string | null;
    occurred_on: string | null;
    place: string | null;
    has_evidence: boolean;
    status: string;
    status_label: string;
    status_tone: ReportStatusTone;
    closed: boolean;
    created_at: string | null;
    handled_at: string | null;
};

const props = defineProps<{
    reports: ReportRow[];
    categories: ReportCategoryOption[];
    colleagues: Array<{ value: number; label: string }>;
    today: string;
    handler_count: number;
}>();

const modalOpen = ref(false);
const expanded = ref<number | null>(null);
const evidenceName = ref<string | null>(null);

// Naming a colleague is a separate choice to writing the report, so the field
// stays out of the way until it is asked for.
const namesSomeone = ref(false);

const form = useForm({
    category: props.categories[0]?.value ?? 'other',
    subject: '',
    body: '',
    subject_user_id: null as number | null,
    subject_name: '',
    occurred_on: null as string | null,
    place: '',
    evidence: null as File | null,
});

const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: category.value,
        label: category.label,
    })),
);

const chosen = computed(() =>
    props.categories.find((category) => category.value === form.category),
);

const open = computed(() => props.reports.filter((row) => !row.closed).length);

function start() {
    form.clearErrors();
    form.reset();
    namesSomeone.value = false;
    evidenceName.value = null;
    modalOpen.value = true;
}

function pickFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    form.evidence = file;
    evidenceName.value = file?.name ?? null;
}

function submit() {
    // Clearing the person here rather than on the checkbox keeps a mistaken
    // tick from wiping a name the reporter had already typed.
    if (!namesSomeone.value) {
        form.subject_user_id = null;
        form.subject_name = '';
    }

    form.post('/reports', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    });
}

const statuses = useStatusFilter(
    () => props.reports,
    (row) => ({ value: row.status, label: row.status_label }),
);
const pages = usePaginated(() => statuses.rows, {
    resetOn: () => statuses.status,
});
</script>

<template>
    <Head title="Report an incident" />

    <AppLayout
        heading="Report an incident"
        lede="Raise something that happened to you, something you saw, or the conduct of a colleague."
    >
        <template #toolbar>
            <AppButton size="sm" @click="start"> Make a report </AppButton>
        </template>

        <div class="space-y-6">
            <section
                class="rounded-2xl border border-beacon/25 bg-beacon-soft/40 p-5"
            >
                <div class="flex items-start gap-3">
                    <svg
                        class="mt-0.5 size-5 shrink-0 text-beacon"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path
                            d="M12 3.5 5 6v5.5c0 4.2 2.9 7.6 7 8.9 4.1-1.3 7-4.7 7-8.9V6l-7-2.5Z"
                        />
                        <path d="M12 9v3.5m0 2.5h.01" />
                    </svg>

                    <div class="min-w-0 space-y-2">
                        <h2
                            class="font-display text-[15px] font-semibold tracking-tight"
                        >
                            This is confidential
                        </h2>

                        <ul
                            class="space-y-1.5 text-[13px] leading-relaxed text-muted"
                        >
                            <li>
                                Your name is
                                <strong class="font-semibold text-text"
                                    >never shown</strong
                                >
                                to the person you report, to your manager, or to
                                anyone else in the company.
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <Panel
                title="Your reports"
                :subtitle="
                    reports.length === 0
                        ? 'Nothing raised yet.'
                        : `${open} still open · ${reports.length} in total`
                "
                flush
            >
                <template v-if="statuses.useful" #action>
                    <StatusFilter
                        v-model="statuses.status"
                        :filter="statuses"
                    />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Subject</th>
                                <th class="px-5 py-3 font-medium">Category</th>
                                <th class="px-5 py-3 font-medium">Happened</th>
                                <th class="px-5 py-3 font-medium">Filed</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="reports.length === 0">
                                <td colspan="6">
                                    <EmptyState
                                        title="You have not reported anything"
                                        message="If something has happened, raise it. Every report is read, and you will see here when it has been picked up."
                                    >
                                        <template #action>
                                            <AppButton size="sm" @click="start">
                                                Make a report
                                            </AppButton>
                                        </template>
                                    </EmptyState>
                                </td>
                            </tr>
                            <template v-for="row in pages.paged" :key="row.id">
                                <tr
                                    class="transition-colors hover:bg-sunken/40"
                                >
                                    <td class="px-5 py-3.5">
                                        <p class="font-medium">
                                            {{ row.subject }}
                                        </p>
                                        <p
                                            v-if="row.against"
                                            class="text-[12px] text-faint"
                                        >
                                            About {{ row.against }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-muted">
                                        {{ row.category_label }}
                                    </td>
                                    <td
                                        class="px-5 py-3.5 whitespace-nowrap text-muted"
                                    >
                                        {{
                                            row.occurred_on
                                                ? fullDate(row.occurred_on)
                                                : '-'
                                        }}
                                    </td>
                                    <td
                                        class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                                    >
                                        {{ dateTime(row.created_at) }}
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <StatusPill :tone="row.status_tone" dot>
                                            {{ row.status_label }}
                                        </StatusPill>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <div
                                            class="flex items-center justify-end gap-1"
                                        >
                                            <a
                                                v-if="row.has_evidence"
                                                :href="`/reports/${row.id}/evidence`"
                                                class="px-2 text-[12.5px] font-medium text-beacon hover:underline"
                                            >
                                                Attachment
                                            </a>
                                            <AppButton
                                                variant="ghost"
                                                size="sm"
                                                @click="
                                                    expanded =
                                                        expanded === row.id
                                                            ? null
                                                            : row.id
                                                "
                                            >
                                                {{
                                                    expanded === row.id
                                                        ? 'Hide'
                                                        : 'Read'
                                                }}
                                            </AppButton>
                                        </div>
                                    </td>
                                </tr>

                                <tr v-if="expanded === row.id">
                                    <td
                                        colspan="6"
                                        class="bg-sunken/40 px-5 py-3"
                                    >
                                        <p
                                            class="text-[13px] leading-relaxed whitespace-pre-line text-muted"
                                        >
                                            {{ row.body }}
                                        </p>
                                        <p
                                            v-if="row.place"
                                            class="mt-2 text-[12px] text-faint"
                                        >
                                            Where: {{ row.place }}
                                        </p>
                                        <p
                                            v-if="row.handled_at"
                                            class="mt-2 text-[12px] text-faint"
                                        >
                                            Closed
                                            {{ dateTime(row.handled_at) }}
                                        </p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="pages.page"
                    v-model:per-page="pages.perPage"
                    :last-page="pages.lastPage"
                    :from="pages.from"
                    :to="pages.to"
                    :total="pages.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            title="Make a report"
            subtitle="Read by the people team only. Your name goes with it so they can come back to you."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <SelectField
                    v-model="form.category"
                    label="What is this about"
                    required
                    :options="categoryOptions"
                    :hint="chosen?.description"
                    :error="form.errors.category"
                />

                <TextField
                    v-model="form.subject"
                    label="In one line"
                    required
                    placeholder="What happened, briefly."
                    :error="form.errors.subject"
                />

                <TextareaField
                    v-model="form.body"
                    label="What happened"
                    required
                    :rows="6"
                    placeholder="Dates, times, what was said or done, and anyone who saw it. Write as much as you can — it is easier to act on a full account."
                    :error="form.errors.body"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.occurred_on"
                        label="When it happened"
                        type="date"
                        :max="today"
                        hint="Leave blank if it is ongoing."
                        :error="form.errors.occurred_on"
                    />
                    <TextField
                        v-model="form.place"
                        label="Where"
                        placeholder="Site, floor, or online"
                        :error="form.errors.place"
                    />
                </div>

                <div class="rounded-xl border border-line bg-sunken/40 p-3.5">
                    <label
                        class="flex cursor-pointer items-start gap-2.5 text-[13px]"
                    >
                        <input
                            v-model="namesSomeone"
                            type="checkbox"
                            class="mt-0.5 size-4 rounded border-line text-brand focus:ring-beacon/30"
                        />
                        <span>
                            <span class="font-medium">
                                This is about a particular person
                            </span>
                            <span class="mt-0.5 block text-[12.5px] text-faint">
                                They are not told that a report names them
                                unless and until the people team decides to act.
                            </span>
                        </span>
                    </label>

                    <div v-if="namesSomeone" class="mt-3.5 space-y-3">
                        <SelectField
                            v-model="form.subject_user_id"
                            label="Who"
                            :options="colleagues"
                            :error="form.errors.subject_user_id"
                        >
                            <option :value="null">
                                Not in this list — I will type a name
                            </option>
                        </SelectField>

                        <TextField
                            v-if="form.subject_user_id === null"
                            v-model="form.subject_name"
                            label="Their name"
                            placeholder="A contractor, a visitor, or how you know them"
                            :error="form.errors.subject_name"
                        />
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-[13px] font-medium text-muted">
                        Evidence
                        <span class="font-normal text-faint">· optional</span>
                    </p>
                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.mp4,.m4a,.mp3"
                        class="block w-full cursor-pointer rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-line-soft file:px-3 file:py-1.5 file:text-[12.5px] file:font-medium file:text-text"
                        @change="pickFile"
                    />
                    <p class="text-[12px] text-faint">
                        A photo, screenshot, document or recording. Up to 20 MB.
                        Attach nothing if you have nothing — a report without
                        evidence is still taken seriously.
                    </p>
                    <p v-if="evidenceName" class="text-[12px] text-muted">
                        Attached: {{ evidenceName }}
                    </p>
                    <p
                        v-if="form.errors.evidence"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.evidence }}
                    </p>
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Send report
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
