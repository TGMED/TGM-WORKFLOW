<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
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
                <EmptyState
                    v-if="recommendations.length === 0"
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

                <ul v-else class="divide-y divide-line-soft">
                    <li
                        v-for="row in recommendations"
                        :key="row.id"
                        class="px-5 py-4"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p
                                    class="text-[14px] font-semibold tracking-tight"
                                >
                                    {{ row.subject }}
                                </p>
                                <p class="mt-0.5 text-[12.5px] text-muted">
                                    <template v-if="row.offence">
                                        {{ row.offence }} ·
                                    </template>
                                    occurrence {{ row.occurrence }} · raised
                                    {{ dateTime(row.created_at) }}
                                </p>
                                <p
                                    class="mt-1.5 max-w-prose text-[13px] leading-relaxed text-faint"
                                >
                                    {{ row.grounds }}
                                </p>

                                <p
                                    v-if="row.hr_note"
                                    class="mt-2 max-w-prose rounded-xl bg-sunken/60 px-3 py-2 text-[13px] leading-relaxed text-muted"
                                >
                                    <span class="font-medium">
                                        {{ row.decided_by ?? 'HR' }}:
                                    </span>
                                    {{ row.hr_note }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <StatusPill :tone="row.status_tone" dot>
                                    {{ row.status_label }}
                                </StatusPill>
                                <AppButton
                                    v-if="row.status === 'pending'"
                                    size="sm"
                                    variant="ghost"
                                    @click="withdrawing = row"
                                >
                                    Withdraw
                                </AppButton>
                            </div>
                        </div>
                    </li>
                </ul>
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
