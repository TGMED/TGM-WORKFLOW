<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type Row = {
    id: number;
    subject: {
        id: number;
        name: string;
        employee_id: string | null;
        position: string | null;
        department: string | null;
        hired_at: string | null;
    };
    raised_by: { name: string; position: string | null };
    offence: {
        id: number;
        code: string | null;
        title: string;
        severity_label: string;
        severity_tone: Tone;
        policy: string | null;
    } | null;
    occurrence: number;
    policy_says: string | null;
    policy_agrees: boolean;
    grounds: string;
    status: string;
    status_label: string;
    status_tone: Tone;
    hr_note: string | null;
    decided_by: string | null;
    decided_at: string | null;
    created_at: string | null;
};

const props = defineProps<{
    recommendations: Row[];
    filters: { status: string };
    statuses: Array<{ value: string; label: string }>;
    counts: { pending: number; accepted: number; declined: number };
}>();

const status = ref(props.filters.status);

watch(status, (value) => {
    router.get(
        '/admin/recommendations',
        { status: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

const answering = ref<Row | null>(null);

const form = useForm({
    status: 'accepted',
    hr_note: '',
});

function answer(row: Row, decision: 'accepted' | 'declined') {
    answering.value = row;

    form.clearErrors();
    form.defaults({ status: decision, hr_note: '' });
    form.reset();
}

function submit() {
    if (!answering.value) {
        return;
    }

    form.put(`/admin/recommendations/${answering.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            answering.value = null;
        },
    });
}
</script>

<template>
    <Head title="Recommendations" />

    <AppLayout
        heading="Recommendations to terminate"
        lede="Cases managers have put to you. Accepting one records your agreement; the exit is still recorded by hand afterwards."
    >
        <div class="space-y-6">
            <div class="stagger grid grid-cols-3 gap-3">
                <StatTile
                    label="With you"
                    :value="counts.pending"
                    :tone="counts.pending > 0 ? 'brass' : 'default'"
                />
                <StatTile label="Accepted" :value="counts.accepted" />
                <StatTile label="Declined" :value="counts.declined" />
            </div>

            <SelectField
                v-model="status"
                class="max-w-[240px]"
                :options="statuses"
            >
                <option value="all">Everything</option>
            </SelectField>

            <Panel flush>
                <EmptyState
                    v-if="recommendations.length === 0"
                    title="Nothing here"
                    message="No cases match this filter."
                />

                <ul v-else class="divide-y divide-line-soft">
                    <li
                        v-for="row in recommendations"
                        :key="row.id"
                        class="px-5 py-5"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link
                                        :href="`/admin/staff/${row.subject.id}`"
                                        class="text-[14.5px] font-semibold tracking-tight hover:underline"
                                    >
                                        {{ row.subject.name }}
                                    </Link>
                                    <StatusPill :tone="row.status_tone" dot>
                                        {{ row.status_label }}
                                    </StatusPill>
                                </div>

                                <p class="mt-0.5 text-[12.5px] text-muted">
                                    {{
                                        [
                                            row.subject.position,
                                            row.subject.department,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>

                                <p class="mt-1 text-[12.5px] text-faint">
                                    Raised by {{ row.raised_by.name }} on
                                    {{ dateTime(row.created_at) }}
                                </p>
                            </div>

                            <div
                                v-if="row.status === 'pending'"
                                class="flex shrink-0 items-center gap-2"
                            >
                                <AppButton
                                    size="sm"
                                    variant="secondary"
                                    @click="answer(row, 'declined')"
                                >
                                    Decline
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="danger"
                                    @click="answer(row, 'accepted')"
                                >
                                    Accept
                                </AppButton>
                            </div>
                        </div>

                        <div
                            class="mt-3 flex flex-wrap items-center gap-2 text-[12.5px]"
                        >
                            <StatusPill
                                v-if="row.offence"
                                :tone="row.offence.severity_tone"
                            >
                                {{ row.offence.title }}
                            </StatusPill>
                            <span v-else class="text-brass">
                                No offence cited
                            </span>

                            <span class="text-faint">
                                Occurrence {{ row.occurrence }}
                            </span>

                            <span
                                v-if="row.policy_says"
                                :class="
                                    row.policy_agrees
                                        ? 'text-muted'
                                        : 'text-brass'
                                "
                            >
                                Policy says: {{ row.policy_says.toLowerCase() }}
                                <template v-if="!row.policy_agrees">
                                    — this asks for more than the handbook sets
                                    out
                                </template>
                            </span>

                            <span v-if="row.offence?.policy" class="text-faint">
                                From {{ row.offence.policy }}
                            </span>
                        </div>

                        <p
                            class="mt-3 max-w-prose rounded-xl bg-sunken/60 px-3 py-2 text-[13px] leading-relaxed text-muted"
                        >
                            {{ row.grounds }}
                        </p>

                        <p
                            v-if="row.hr_note"
                            class="mt-2 max-w-prose text-[12.5px] text-faint"
                        >
                            <span class="font-medium">
                                {{ row.decided_by ?? 'HR' }}
                                {{ row.status_label.toLowerCase() }} this:
                            </span>
                            {{ row.hr_note }}
                        </p>
                    </li>
                </ul>
            </Panel>
        </div>

        <ModalShell
            :open="answering !== null"
            :title="
                form.status === 'accepted'
                    ? 'Accept this recommendation?'
                    : 'Decline this recommendation?'
            "
            :subtitle="answering?.subject.name"
            @close="answering = null"
        >
            <div class="space-y-4">
                <p class="text-[13.5px] leading-relaxed text-muted">
                    <template v-if="form.status === 'accepted'">
                        This records that you agree. Nothing on their record
                        changes until somebody records the exit on their staff
                        page, which is where the handover and the clearing up
                        happen.
                    </template>
                    <template v-else>
                        The manager who raised it hears your answer, so write
                        something they can take back to their team.
                    </template>
                </p>

                <div class="space-y-1.5">
                    <label
                        for="hr-note"
                        class="flex items-center gap-1 text-[13px] font-medium text-muted"
                    >
                        Your note
                        <span class="text-alert" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="hr-note"
                        v-model="form.hr_note"
                        rows="4"
                        required
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                    <p
                        v-if="form.errors.hr_note"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.hr_note }}
                    </p>
                </div>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="answering = null">
                    Cancel
                </AppButton>
                <AppButton
                    :variant="form.status === 'accepted' ? 'danger' : 'primary'"
                    :loading="form.processing"
                    @click="submit"
                >
                    {{ form.status === 'accepted' ? 'Accept' : 'Decline' }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
