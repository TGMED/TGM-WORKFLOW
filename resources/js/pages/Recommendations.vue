<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusFilter from '@/components/ui/StatusFilter.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import { usePaginated } from '@/composables/usePaginated';
import { useStatusFilter } from '@/composables/useStatusFilter';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type Row = {
    id: number;
    subject: string;
    offence: string | null;
    occurrence: number;
    grounds: string;
    status: string;
    status_label: string;
    status_tone: Tone;
    hr_note: string | null;
    decided_by: string | null;
    decided_at: string | null;
    created_at: string | null;
};

type OffenceOption = {
    value: number;
    label: string;
    ends_employment: boolean;
    ladder: Array<{
        occurrence: number;
        occurrence_label: string;
        action_label: string;
        ends_employment: boolean;
    }>;
};

const props = defineProps<{
    recommendations: Row[];
    people: Array<{ value: number; label: string }>;
    offences: OffenceOption[];
}>();

const modalOpen = ref(false);
const withdrawing = ref<Row | null>(null);
const busy = ref(false);

const form = useForm({
    subject_user_id: null as number | null,
    offence_id: null as number | null,
    occurrence: 1,
    grounds: '',
});

const chosenOffence = computed(() =>
    props.offences.find((offence) => offence.value === form.offence_id),
);

// What the handbook says follows this many occurrences, so somebody can see
// before they write whether the policy backs what they are asking for.
const policySays = computed(() => {
    const ladder = chosenOffence.value?.ladder ?? [];

    if (ladder.length === 0) {
        return null;
    }

    return (
        ladder.find((rung) => rung.occurrence === form.occurrence) ??
        ladder[ladder.length - 1]
    );
});

function open() {
    form.clearErrors();
    form.reset();
    modalOpen.value = true;
}

function submit() {
    form.post('/recommendations', {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    });
}

function withdraw() {
    if (!withdrawing.value) {
        return;
    }

    busy.value = true;

    router.delete(`/recommendations/${withdrawing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            withdrawing.value = null;
        },
    });
}

const statuses = useStatusFilter(
    () => props.recommendations,
    (row) => ({ value: row.status, label: row.status_label }),
);
const pages = usePaginated(() => statuses.rows, {
    resetOn: () => statuses.status,
});
</script>

<template>
    <Head title="Recommendations" />

    <AppLayout
        heading="Recommend a termination"
        lede="Put a case to HR about somebody who answers to you. Nothing changes on their record until HR acts on it."
    >
        <template #toolbar>
            <AppButton
                size="sm"
                :disabled="people.length === 0"
                @click="open()"
            >
                Raise a case
            </AppButton>
        </template>

        <div class="space-y-6">
            <Panel flush title="Cases you have raised">
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
                                <th class="px-5 py-3 font-medium">Grounds</th>
                                <th class="px-5 py-3 font-medium">Raised</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="recommendations.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        :title="
                                            people.length === 0
                                                ? 'Nobody answers to you'
                                                : 'Nothing raised'
                                        "
                                        :message="
                                            people.length === 0
                                                ? 'This page is for managers putting a case to HR about somebody who reports to them.'
                                                : 'If it has come to this, set the case out here and HR will answer it.'
                                        "
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in pages.paged"
                                :key="row.id"
                                class="align-top transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">
                                        {{ row.subject }}
                                    </p>
                                    <p class="text-[12px] text-faint">
                                        <template v-if="row.offence">
                                            {{ row.offence }} ·
                                        </template>
                                        occurrence {{ row.occurrence }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p
                                        class="max-w-[26rem] text-[12.5px] leading-relaxed text-faint"
                                    >
                                        {{ row.grounds }}
                                    </p>
                                    <p
                                        v-if="row.hr_note"
                                        class="mt-2 max-w-[26rem] rounded-xl bg-sunken/60 px-3 py-2 text-[12.5px] leading-relaxed text-muted"
                                    >
                                        <span class="font-medium">
                                            {{ row.decided_by ?? 'HR' }}:
                                        </span>
                                        {{ row.hr_note }}
                                    </p>
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
                                    <AppButton
                                        v-if="row.status === 'pending'"
                                        size="sm"
                                        variant="ghost"
                                        @click="withdrawing = row"
                                    >
                                        Withdraw
                                    </AppButton>
                                </td>
                            </tr>
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
            title="Recommend a termination"
            subtitle="This goes to HR to decide. It does not end anybody's employment on its own."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <SelectField
                    v-model="form.subject_user_id"
                    label="Who"
                    :options="people"
                    :error="form.errors.subject_user_id"
                >
                    <option :value="null" disabled>Pick somebody</option>
                </SelectField>

                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_120px]">
                    <SelectField
                        v-model="form.offence_id"
                        label="Offence"
                        :options="
                            offences.map((offence) => ({
                                value: offence.value,
                                label: offence.label,
                            }))
                        "
                        :error="form.errors.offence_id"
                        hint="From the register, where one fits."
                    >
                        <option :value="null">None from the register</option>
                    </SelectField>

                    <TextField
                        v-model.number="form.occurrence"
                        label="Occurrence"
                        type="number"
                        min="1"
                        :error="form.errors.occurrence"
                    />
                </div>

                <p
                    v-if="policySays"
                    class="rounded-xl bg-sunken/60 px-3 py-2 text-[12.5px]"
                    :class="
                        policySays.ends_employment ? 'text-muted' : 'text-brass'
                    "
                >
                    The policy says this is
                    <span class="font-medium">
                        {{ policySays.action_label.toLowerCase() }}
                    </span>
                    at occurrence {{ form.occurrence }}.
                    <template v-if="!policySays.ends_employment">
                        You are asking for more than the handbook sets out, so
                        say why below.
                    </template>
                </p>

                <div class="space-y-1.5">
                    <label
                        for="recommendation-grounds"
                        class="flex items-center gap-1 text-[13px] font-medium text-muted"
                    >
                        The case
                        <span class="text-alert" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="recommendation-grounds"
                        v-model="form.grounds"
                        rows="6"
                        required
                        placeholder="What happened, when, what was done about it already, and why it has come to this."
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                    <p
                        v-if="form.errors.grounds"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.grounds }}
                    </p>
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Put it to HR
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="withdrawing !== null"
            width="md"
            title="Withdraw this case?"
            :subtitle="withdrawing?.subject"
            @close="withdrawing = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                HR stops seeing it. What you wrote stays on file.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="withdrawing = null">
                    Keep it
                </AppButton>
                <AppButton variant="danger" :loading="busy" @click="withdraw">
                    Withdraw
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
