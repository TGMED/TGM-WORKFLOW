<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type Rung = {
    id?: number;
    occurrence: number;
    occurrence_label?: string;
    action: string;
    action_label?: string;
    action_tone?: Tone;
    ends_employment?: boolean;
    notes: string | null;
};

type OffenceRow = {
    id: number;
    code: string | null;
    title: string;
    description: string | null;
    severity: string;
    severity_label: string;
    severity_tone: Tone;
    is_active: boolean;
    policy: {
        id: number;
        title: string;
        version: string | null;
        is_active: boolean;
    } | null;
    ends_employment: boolean;
    ladder: Rung[];
};

defineProps<{
    offences: OffenceRow[];
    severities: Array<{ value: string; label: string; description: string }>;
    actions: Array<{ value: string; label: string; ends_employment: boolean }>;
    policies: Array<{ value: number; label: string }>;
    totals: { active: number; unanchored: number; without_ladder: number };
}>();

const modalOpen = ref(false);
const editing = ref<OffenceRow | null>(null);

const form = useForm({
    code: '',
    title: '',
    description: '',
    severity: 'minor',
    policy_id: null as number | null,
    ladder: [] as Array<{
        occurrence: number;
        action: string;
        notes: string | null;
    }>,
});

function open(offence: OffenceRow | null) {
    editing.value = offence;

    form.clearErrors();
    form.defaults({
        code: offence?.code ?? '',
        title: offence?.title ?? '',
        description: offence?.description ?? '',
        severity: offence?.severity ?? 'minor',
        policy_id: offence?.policy?.id ?? null,
        ladder: offence
            ? offence.ladder.map((rung) => ({
                  occurrence: rung.occurrence,
                  action: rung.action,
                  notes: rung.notes,
              }))
            : [{ occurrence: 1, action: 'verbal_warning', notes: null }],
    });
    form.reset();
    modalOpen.value = true;
}

function addRung() {
    const next = form.ladder.length
        ? Math.max(...form.ladder.map((rung) => rung.occurrence)) + 1
        : 1;

    if (next > 6) {
        return;
    }

    form.ladder.push({
        occurrence: next,
        action: 'written_warning',
        notes: null,
    });
}

function removeRung(index: number) {
    form.ladder.splice(index, 1);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(`/admin/offences/${editing.value.id}`, options);
    } else {
        form.post('/admin/offences', options);
    }
}

function toggle(offence: OffenceRow) {
    router.patch(
        `/admin/offences/${offence.id}/toggle`,
        {},
        { preserveScroll: true },
    );
}

const occurrenceLabel = (n: number) =>
    ({ 1: 'First time', 2: 'Second time', 3: 'Third time' })[n] ??
    `${n}th time`;
</script>

<template>
    <Head title="Offences" />

    <AppLayout
        heading="Offences and what follows"
        lede="The register, and the ladder the policy sets against each entry."
    >
        <template #toolbar>
            <AppButton size="sm" @click="open(null)">Add an offence</AppButton>
        </template>

        <div class="space-y-6">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatTile label="On the register" :value="totals.active" />
                <StatTile
                    label="Not tied to a policy"
                    :value="totals.unanchored"
                    :tone="totals.unanchored > 0 ? 'brass' : 'default'"
                    caption="Nothing to point at in a hearing"
                />
                <StatTile
                    label="No ladder written"
                    :value="totals.without_ladder"
                    :tone="totals.without_ladder > 0 ? 'brass' : 'default'"
                    caption="Nothing follows, as written"
                />
            </div>

            <Panel flush title="The register">
                <EmptyState
                    v-if="offences.length === 0"
                    title="Nothing on the register"
                    message="Write out what the handbook treats as an offence, and what follows each one."
                >
                    <template #action>
                        <AppButton size="sm" @click="open(null)">
                            Add an offence
                        </AppButton>
                    </template>
                </EmptyState>

                <ul v-else class="divide-y divide-line-soft">
                    <li
                        v-for="offence in offences"
                        :key="offence.id"
                        class="px-5 py-4"
                        :class="offence.is_active ? '' : 'opacity-70'"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-if="offence.code"
                                        class="tabular rounded-md bg-sunken px-1.5 py-0.5 font-mono text-[11.5px] text-muted"
                                    >
                                        {{ offence.code }}
                                    </span>
                                    <p
                                        class="text-[14px] font-semibold tracking-tight"
                                    >
                                        {{ offence.title }}
                                    </p>
                                    <StatusPill :tone="offence.severity_tone">
                                        {{ offence.severity_label }}
                                    </StatusPill>
                                    <StatusPill
                                        v-if="!offence.is_active"
                                        tone="neutral"
                                    >
                                        Off the register
                                    </StatusPill>
                                </div>

                                <p
                                    v-if="offence.description"
                                    class="mt-1 max-w-prose text-[13px] leading-relaxed text-muted"
                                >
                                    {{ offence.description }}
                                </p>

                                <p class="mt-1.5 text-[12px]">
                                    <span
                                        v-if="offence.policy"
                                        class="text-faint"
                                    >
                                        From {{ offence.policy.title }}
                                        <template v-if="offence.policy.version">
                                            (v{{ offence.policy.version }})
                                        </template>
                                    </span>
                                    <span v-else class="text-brass">
                                        Not tied to a policy document
                                    </span>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <AppButton
                                    size="sm"
                                    variant="secondary"
                                    @click="open(offence)"
                                >
                                    Edit
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="ghost"
                                    @click="toggle(offence)"
                                >
                                    {{
                                        offence.is_active
                                            ? 'Retire'
                                            : 'Put back'
                                    }}
                                </AppButton>
                            </div>
                        </div>

                        <ol
                            v-if="offence.ladder.length"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <li
                                v-for="rung in offence.ladder"
                                :key="rung.occurrence"
                                class="rounded-xl border border-line-soft px-3 py-2"
                            >
                                <p class="text-[11.5px] text-faint">
                                    {{ rung.occurrence_label }}
                                </p>
                                <p
                                    class="text-[13px] font-medium"
                                    :class="
                                        rung.ends_employment
                                            ? 'text-alert'
                                            : 'text-text'
                                    "
                                >
                                    {{ rung.action_label }}
                                </p>
                                <p
                                    v-if="rung.notes"
                                    class="mt-0.5 max-w-[240px] text-[12px] text-faint"
                                >
                                    {{ rung.notes }}
                                </p>
                            </li>
                        </ol>

                        <p v-else class="mt-3 text-[12.5px] text-brass">
                            No ladder written, so the policy says nothing about
                            what follows this.
                        </p>
                    </li>
                </ul>
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            :title="editing ? 'Edit offence' : 'Add an offence'"
            subtitle="Tie it to the policy it comes from, then write what follows each time it happens."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-[120px_minmax(0,1fr)]">
                    <TextField
                        v-model="form.code"
                        label="Code"
                        placeholder="D4"
                        :error="form.errors.code"
                    />
                    <TextField
                        v-model="form.title"
                        label="Offence"
                        required
                        :error="form.errors.title"
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.severity"
                        label="Severity"
                        :options="
                            severities.map((severity) => ({
                                value: severity.value,
                                label: severity.label,
                            }))
                        "
                        :error="form.errors.severity"
                    />
                    <SelectField
                        v-model="form.policy_id"
                        label="From policy"
                        :options="policies"
                        :error="form.errors.policy_id"
                        hint="Only policies in force can be cited."
                    >
                        <option :value="null">Not tied to one</option>
                    </SelectField>
                </div>

                <div class="space-y-1.5">
                    <label
                        for="offence-description"
                        class="text-[13px] font-medium text-muted"
                    >
                        What it covers
                    </label>
                    <textarea
                        id="offence-description"
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <p class="text-[13px] font-medium text-muted">
                            What follows
                        </p>
                        <AppButton
                            size="sm"
                            variant="ghost"
                            :disabled="form.ladder.length >= 6"
                            @click="addRung"
                        >
                            Add a step
                        </AppButton>
                    </div>

                    <div
                        v-for="(rung, index) in form.ladder"
                        :key="index"
                        class="flex flex-wrap items-end gap-2 rounded-xl border border-line-soft p-3"
                    >
                        <p class="w-[92px] text-[12.5px] text-faint">
                            {{ occurrenceLabel(rung.occurrence) }}
                        </p>

                        <SelectField
                            v-model="rung.action"
                            class="min-w-[180px] flex-1"
                            :options="
                                actions.map((action) => ({
                                    value: action.value,
                                    label: action.label,
                                }))
                            "
                        />

                        <button
                            type="button"
                            class="pb-2.5 text-[12.5px] text-muted hover:text-alert"
                            @click="removeRung(index)"
                        >
                            Remove
                        </button>
                    </div>

                    <p
                        v-if="form.ladder.length === 0"
                        class="text-[12.5px] text-brass"
                    >
                        With no steps, the policy says nothing about what
                        follows this offence.
                    </p>
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    {{ editing ? 'Save' : 'Add to register' }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
