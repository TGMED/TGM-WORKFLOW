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
import TextField from '@/components/ui/TextField.vue';
import { currentPerPage } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, fullDate } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type ActionRow = {
    id: number;
    subject: string;
    department: string | null;
    kind: string;
    kind_label: string;
    kind_tone: Tone;
    title: string;
    body: string;
    offence: string | null;
    issued_by: string | null;
    response_due_on: string | null;
    response: string | null;
    responded_at: string | null;
    acknowledged_at: string | null;
    state_label: string;
    state_tone: Tone;
    created_at: string | null;
};

type Person = {
    value: number;
    label: string;
    on_probation: boolean;
    confirmation_due: string | null;
};

const props = defineProps<{
    actions: {
        data: ActionRow[];
        per_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { kind: string };
    kinds: Array<{ value: string; label: string }>;
    counts: Record<string, number>;
    people: Person[];
    offences: Array<{ value: number; label: string }>;
    probation_months: number;
}>();

const kind = ref(props.filters.kind);
const viewing = ref<ActionRow | null>(null);
const issuing = ref(false);
const probationOpen = ref(false);

const kindOptions = computed(() => [
    { value: '', label: 'Every kind' },
    ...props.kinds,
]);

watch(kind, () => {
    router.get(
        '/admin/conduct',
        { kind: kind.value, per_page: currentPerPage() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

const form = useForm({
    subject_user_id: null as number | null,
    kind: 'query',
    title: '',
    body: '',
    offence_id: null as number | null,
    response_due_on: '',
});

// Only somebody still on probation can be confirmed.
const peopleOptions = computed(() =>
    props.people
        .filter((person) => form.kind !== 'confirmation' || person.on_probation)
        .map((person) => ({
            value: person.value,
            label:
                form.kind === 'confirmation' && person.confirmation_due
                    ? `${person.label} (due ${person.confirmation_due})`
                    : person.label,
        })),
);

watch(
    () => form.kind,
    (next) => {
        if (next === 'confirmation') {
            form.offence_id = null;
            form.response_due_on = '';

            const chosen = props.people.find(
                (person) => person.value === form.subject_user_id,
            );

            if (chosen && !chosen.on_probation) {
                form.subject_user_id = null;
            }
        } else if (next !== 'query') {
            form.response_due_on = '';
        }
    },
);

function openIssue() {
    form.clearErrors();
    form.reset();
    issuing.value = true;
}

function issue() {
    form.post('/admin/conduct', {
        preserveScroll: true,
        onSuccess: () => {
            issuing.value = false;
        },
    });
}

const probation = useForm({ probation_months: props.probation_months });

function saveProbation() {
    probation.put('/admin/conduct/probation', {
        preserveScroll: true,
        onSuccess: () => {
            probationOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Queries and warnings" />

    <AppLayout
        heading="Queries and warnings"
        lede="Formal letters to staff. Each one is emailed to the person, their head of department and HR."
    >
        <template #toolbar>
            <div class="flex gap-2">
                <AppButton
                    size="sm"
                    variant="ghost"
                    @click="probationOpen = true"
                >
                    Probation: {{ probation_months }} months
                </AppButton>
                <AppButton size="sm" @click="openIssue()">
                    Issue a letter
                </AppButton>
            </div>
        </template>

        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile
                    label="Queries"
                    :value="counts.query ?? 0"
                    tone="brass"
                />
                <StatTile
                    label="Warnings"
                    :value="counts.warning ?? 0"
                    tone="alert"
                />
                <StatTile
                    label="Confirmations"
                    :value="counts.confirmation ?? 0"
                    tone="signal"
                />
            </div>

            <Panel flush>
                <template #action>
                    <SelectField v-model="kind" :options="kindOptions" />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">To</th>
                                <th class="px-5 py-3 font-medium">Letter</th>
                                <th class="px-5 py-3 font-medium">Issued by</th>
                                <th class="px-5 py-3 font-medium">Issued</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="actions.data.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        title="Nothing issued"
                                        message="Queries, warnings and confirmations show here once issued."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in actions.data"
                                :key="row.id"
                                class="cursor-pointer transition-colors hover:bg-sunken/40"
                                @click="viewing = row"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ row.subject }}</p>
                                    <p
                                        v-if="row.department"
                                        class="text-[12px] text-faint"
                                    >
                                        {{ row.department }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="flex items-center gap-2">
                                        <StatusPill :tone="row.kind_tone">
                                            {{ row.kind_label }}
                                        </StatusPill>
                                        <span class="max-w-[20rem] truncate">
                                            {{ row.title }}
                                        </span>
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ row.issued_by ?? '-' }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(row.created_at) }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill :tone="row.state_tone" dot>
                                        {{ row.state_label }}
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :links="actions.links"
                    :per-page="actions.per_page"
                    :from="actions.from"
                    :to="actions.to"
                    :total="actions.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="viewing !== null"
            width="lg"
            :title="viewing?.title ?? ''"
            :subtitle="
                viewing ? `${viewing.kind_label} to ${viewing.subject}` : ''
            "
            @close="viewing = null"
        >
            <div v-if="viewing" class="space-y-5">
                <p class="text-[12.5px] text-faint">
                    Issued by {{ viewing.issued_by ?? 'HR' }} on
                    {{ dateTime(viewing.created_at) }}
                    <template v-if="viewing.offence">
                        under {{ viewing.offence }}
                    </template>
                    <template v-if="viewing.response_due_on">
                        · answer due {{ fullDate(viewing.response_due_on) }}
                    </template>
                </p>

                <div class="rounded-xl bg-sunken/50 p-4">
                    <p
                        class="text-[13.5px] leading-relaxed whitespace-pre-line"
                    >
                        {{ viewing.body }}
                    </p>
                </div>

                <div
                    v-if="viewing.response"
                    class="space-y-1 border-t border-line-soft pt-4"
                >
                    <p class="text-[12px] text-faint">
                        Their answer, {{ dateTime(viewing.responded_at) }}
                    </p>
                    <p
                        class="text-[13.5px] leading-relaxed whitespace-pre-line"
                    >
                        {{ viewing.response }}
                    </p>
                </div>

                <p
                    v-else-if="viewing.acknowledged_at"
                    class="text-[12.5px] text-faint"
                >
                    Read on {{ dateTime(viewing.acknowledged_at) }}.
                </p>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="viewing = null">
                    Close
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="issuing"
            width="lg"
            title="Issue a letter"
            subtitle="It is emailed to them, their head of department and HR as soon as you issue it."
            @close="issuing = false"
        >
            <form class="space-y-4" @submit.prevent="issue">
                <div class="grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                    <SelectField
                        v-model="form.kind"
                        label="Kind"
                        required
                        :options="kinds"
                        :error="form.errors.kind"
                    />
                    <SelectField
                        v-model="form.subject_user_id"
                        label="To"
                        required
                        :options="peopleOptions"
                        :error="form.errors.subject_user_id"
                    >
                        <option :value="null" disabled>
                            {{
                                form.kind === 'confirmation'
                                    ? 'Pick somebody on probation'
                                    : 'Pick somebody'
                            }}
                        </option>
                    </SelectField>
                </div>

                <TextField
                    v-model="form.title"
                    label="Title"
                    :required="form.kind !== 'confirmation'"
                    :placeholder="
                        form.kind === 'confirmation'
                            ? 'Confirmation of appointment'
                            : ''
                    "
                    :error="form.errors.title"
                />

                <div
                    v-if="form.kind !== 'confirmation'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <SelectField
                        v-model="form.offence_id"
                        label="Offence"
                        :options="offences"
                        hint="From the register, where one fits."
                        :error="form.errors.offence_id"
                    >
                        <option :value="null">None from the register</option>
                    </SelectField>
                    <TextField
                        v-if="form.kind === 'query'"
                        v-model="form.response_due_on"
                        label="Answer due by"
                        type="date"
                        required
                        :error="form.errors.response_due_on"
                    />
                </div>

                <TextareaField
                    v-model="form.body"
                    label="The letter"
                    required
                    :rows="8"
                    :placeholder="
                        form.kind === 'confirmation'
                            ? 'Congratulate them, and say what changes now they are confirmed.'
                            : 'What happened, when, and what is expected of them now.'
                    "
                    :error="form.errors.body"
                />

                <p
                    v-if="form.kind === 'confirmation'"
                    class="rounded-xl bg-sunken/60 px-3 py-2 text-[12.5px] text-muted"
                >
                    Issuing this confirms them today: their record changes from
                    probation to confirmed.
                </p>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="issuing = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="issue">
                    Issue and email
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="probationOpen"
            width="md"
            title="Probation length"
            subtitle="How long new starters serve before confirmation is due."
            @close="probationOpen = false"
        >
            <form class="space-y-3" @submit.prevent="saveProbation">
                <TextField
                    v-model="probation.probation_months"
                    label="Months"
                    type="number"
                    min="1"
                    max="24"
                    required
                    hint="Anybody given their own length on their staff record keeps it."
                    :error="probation.errors.probation_months"
                />
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="probationOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    :loading="probation.processing"
                    @click="saveProbation"
                >
                    Save
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
