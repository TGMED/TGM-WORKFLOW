<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, fullDate } from '@/lib/format';
import type { ReportCategoryOption, ReportStatusTone } from '@/types';

type Person = {
    id: number;
    name: string;
    employee_id: string | null;
    department: string | null;
};

type ReportRow = {
    id: number;
    category: string;
    category_label: string;
    urgent: boolean;
    subject: string;
    body: string;
    excerpt: string;
    reporter: Person | null;
    against: string | null;
    against_user_id: number | null;
    occurred_on: string | null;
    place: string | null;
    has_evidence: boolean;
    status: string;
    status_label: string;
    status_tone: ReportStatusTone;
    open: boolean;
    handled_by: string | null;
    handled_at: string | null;
    resolution_note: string | null;
    created_at: string | null;
};

const props = defineProps<{
    reports: {
        data: ReportRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { status: string; category: string };
    statuses: Array<{ value: string; label: string }>;
    categories: ReportCategoryOption[];
    counts: {
        open: number;
        submitted: number;
        under_review: number;
        resolved: number;
        dismissed: number;
    };
}>();

const viewing = ref<ReportRow | null>(null);

const status = ref(props.filters.status);
const category = ref(props.filters.category);

const statusOptions = computed(() => [
    { value: 'open', label: 'Open cases' },
    { value: 'all', label: 'Everything' },
    ...props.statuses.map((option) => ({
        value: option.value,
        label: option.label,
    })),
]);

const categoryOptions = computed(() => [
    { value: '', label: 'Every category' },
    ...props.categories.map((option) => ({
        value: option.value,
        label: option.label,
    })),
]);

const form = useForm({
    status: 'under_review',
    resolution_note: '',
});

// Closing a case asks for a note; picking one up does not.
const needsNote = computed(
    () => form.status === 'resolved' || form.status === 'dismissed',
);

watch([status, category], () => {
    router.get(
        '/admin/reports',
        { status: status.value, category: category.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

function view(row: ReportRow) {
    form.clearErrors();
    form.defaults({
        status: row.status,
        resolution_note: row.resolution_note ?? '',
    });
    form.reset();
    viewing.value = row;
}

function save() {
    if (!viewing.value) {
        return;
    }

    form.put(`/admin/reports/${viewing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            viewing.value = null;
        },
    });
}
</script>

<template>
    <Head title="Reports desk" />

    <AppLayout
        heading="Reports desk"
        lede="Incidents staff have raised. Everything here is confidential, and the reporter's name goes no further than this page."
    >
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Open"
                    :value="counts.open"
                    :tone="counts.open > 0 ? 'alert' : 'default'"
                    caption="Waiting on someone"
                />
                <StatTile
                    label="Not yet opened"
                    :value="counts.submitted"
                    tone="brass"
                    caption="Nobody has picked these up"
                />
                <StatTile
                    label="Resolved"
                    :value="counts.resolved"
                    tone="signal"
                />
                <StatTile label="Closed, no action" :value="counts.dismissed" />
            </div>

            <Panel flush>
                <template #action>
                    <div class="flex flex-wrap items-center gap-2">
                        <SelectField
                            v-model="status"
                            :options="statusOptions"
                        />
                        <SelectField
                            v-model="category"
                            :options="categoryOptions"
                        />
                    </div>
                </template>

                <EmptyState
                    v-if="reports.data.length === 0"
                    title="Nothing here"
                    message="No report matches this filter. An empty open list is the one to hope for."
                />

                <ul v-else class="divide-y divide-line-soft">
                    <li
                        v-for="row in reports.data"
                        :key="row.id"
                        class="cursor-pointer px-5 py-4 transition-colors hover:bg-sunken/40"
                        @click="view(row)"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex items-center gap-2 text-[14px] font-semibold tracking-tight"
                                >
                                    <span class="truncate">
                                        {{ row.subject }}
                                    </span>
                                    <StatusPill
                                        v-if="row.urgent && row.open"
                                        tone="alert"
                                    >
                                        Priority
                                    </StatusPill>
                                </p>

                                <p class="mt-0.5 text-[12.5px] text-muted">
                                    {{ row.category_label }} ·
                                    {{
                                        row.reporter?.name ?? 'Former employee'
                                    }}
                                    <template v-if="row.against">
                                        · about {{ row.against }}
                                    </template>
                                </p>

                                <p
                                    class="mt-1.5 max-w-prose text-[13px] leading-relaxed text-faint"
                                >
                                    {{ row.excerpt }}
                                </p>
                            </div>

                            <div
                                class="flex shrink-0 flex-col items-end gap-1.5"
                            >
                                <StatusPill :tone="row.status_tone" dot>
                                    {{ row.status_label }}
                                </StatusPill>
                                <span class="text-[12px] text-faint">
                                    {{ dateTime(row.created_at) }}
                                </span>
                            </div>
                        </div>
                    </li>
                </ul>

                <div v-if="reports.data.length" class="px-5 py-4">
                    <Pagination
                        :links="reports.links"
                        :from="reports.from"
                        :to="reports.to"
                        :total="reports.total"
                    />
                </div>
            </Panel>
        </div>

        <ModalShell
            :open="viewing !== null"
            width="lg"
            :title="viewing?.subject ?? ''"
            :subtitle="viewing?.category_label ?? ''"
            @close="viewing = null"
        >
            <div v-if="viewing" class="space-y-5">
                <dl class="grid gap-3 text-[13px] sm:grid-cols-2">
                    <div>
                        <dt class="text-[12px] text-faint">Reported by</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ viewing.reporter?.name ?? 'Former employee' }}
                            <span
                                v-if="viewing.reporter?.department"
                                class="font-normal text-muted"
                            >
                                · {{ viewing.reporter.department }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[12px] text-faint">About</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ viewing.against ?? 'No one named' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[12px] text-faint">When</dt>
                        <dd class="mt-0.5">
                            {{
                                viewing.occurred_on
                                    ? fullDate(viewing.occurred_on)
                                    : 'Not given'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[12px] text-faint">Where</dt>
                        <dd class="mt-0.5">
                            {{ viewing.place ?? 'Not given' }}
                        </dd>
                    </div>
                </dl>

                <div class="rounded-xl bg-sunken/50 p-4">
                    <p
                        class="text-[13.5px] leading-relaxed whitespace-pre-line"
                    >
                        {{ viewing.body }}
                    </p>
                </div>

                <a
                    v-if="viewing.has_evidence"
                    :href="`/reports/${viewing.id}/evidence`"
                    class="inline-flex items-center gap-1.5 text-[13px] font-medium text-beacon hover:underline"
                >
                    <svg
                        class="size-4"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                    >
                        <path
                            d="M14 3.5v5h5M13.5 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8.5l-5-5Z"
                        />
                    </svg>
                    Open the attachment
                </a>

                <p v-if="viewing.handled_by" class="text-[12.5px] text-faint">
                    Last touched by {{ viewing.handled_by }}
                    <template v-if="viewing.handled_at">
                        on {{ dateTime(viewing.handled_at) }}
                    </template>
                </p>

                <div class="space-y-4 border-t border-line-soft pt-4">
                    <SelectField
                        v-model="form.status"
                        label="Where this case stands"
                        required
                        :options="statuses"
                        :error="form.errors.status"
                    />

                    <TextareaField
                        v-model="form.resolution_note"
                        label="Internal note"
                        :rows="4"
                        :required="needsNote"
                        placeholder="What was done, and why the case closes here."
                        :hint="
                            needsNote
                                ? 'Required to close a case. The reporter never sees this.'
                                : 'Only the reports desk sees this.'
                        "
                        :error="form.errors.resolution_note"
                    />
                </div>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="viewing = null">
                    Close
                </AppButton>
                <AppButton :loading="form.processing" @click="save">
                    Save
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
