<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, money } from '@/lib/format';

type Line = {
    user_id: number;
    name: string;
    employee_id: string | null;
    rsa_number: string | null;
    pfa_name: string | null;
    employee: number;
    employer: number;
    total: number;
    ready: boolean;
};

const props = defineProps<{
    run: {
        id: number;
        period_label: string;
        status_label: string;
        status_tone: 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';
        is_draft: boolean;
        remitted_at: string | null;
        reference: string | null;
        remitted_by: string | null;
    };
    lines: Line[];
    totals: {
        people: number;
        employee: number;
        employer: number;
        total: number;
        missing_rsa: number;
    };
}>();

const remitOpen = ref(false);

const form = useForm({
    pension_reference: props.run.reference ?? '',
    pension_remitted_at: '',
});

function submit() {
    form.post(`/admin/payroll/${props.run.id}/pension/remit`, {
        preserveScroll: true,
        onSuccess: () => {
            remitOpen.value = false;
        },
    });
}

const show = (value: number) => money(value, 'NGN');
</script>

<template>
    <Head :title="`Pension · ${run.period_label}`" />

    <AppLayout
        :heading="`Pension · ${run.period_label}`"
        lede="What is owed to the administrator for the month, read off the payslips as they were signed off."
    >
        <template #toolbar>
            <div class="flex items-center gap-2">
                <Link
                    :href="`/admin/payroll/${run.id}`"
                    class="text-[13px] font-medium text-muted hover:text-text"
                >
                    Back to the run
                </Link>
                <a
                    v-if="!run.is_draft"
                    :href="`/admin/payroll/${run.id}/pension/export`"
                    class="inline-flex items-center rounded-xl border border-line px-3.5 py-2 text-[13px] font-medium transition-colors hover:bg-line-soft"
                >
                    Download schedule
                </a>
                <AppButton
                    v-if="!run.is_draft"
                    size="sm"
                    @click="remitOpen = true"
                >
                    {{
                        run.remitted_at
                            ? 'Update remittance'
                            : 'Record remittance'
                    }}
                </AppButton>
            </div>
        </template>

        <div class="space-y-6">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile label="People" :value="totals.people" />
                <StatTile
                    label="From staff"
                    :value="show(totals.employee)"
                    caption="Deducted from pay"
                />
                <StatTile
                    label="From the company"
                    :value="show(totals.employer)"
                    caption="On top of pay"
                />
                <StatTile
                    label="To remit"
                    :value="show(totals.total)"
                    tone="signal"
                />
            </div>

            <div
                v-if="run.is_draft"
                class="rounded-2xl border border-brass/40 bg-brass-soft px-4 py-3.5 text-[13px] text-brass"
            >
                This run is still a draft, so the schedule can change. It cannot
                be downloaded or remitted until the run is signed off.
            </div>

            <div
                v-if="totals.missing_rsa > 0"
                class="rounded-2xl border border-brass/40 bg-brass-soft px-4 py-3.5 text-[13px] text-brass"
            >
                {{ totals.missing_rsa }}
                {{ totals.missing_rsa === 1 ? 'person has' : 'people have' }}
                no RSA number on file, so there is nowhere for their money to
                land. Their line is marked below.
            </div>

            <div
                v-if="run.remitted_at"
                class="rounded-2xl border border-signal/40 bg-signal-soft px-4 py-3.5 text-[13px] text-signal"
            >
                Remitted {{ dateTime(run.remitted_at) }}
                <template v-if="run.remitted_by">
                    by {{ run.remitted_by }}
                </template>
                · reference {{ run.reference }}
            </div>

            <Panel flush :title="`Schedule · ${run.period_label}`">
                <EmptyState
                    v-if="lines.length === 0"
                    title="Nobody on the schedule"
                    message="No payslip in this run carries a pension contribution."
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Name
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    RSA number
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    PFA
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Staff
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Company
                                </th>
                                <th class="eyebrow px-5 py-3 font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="line in lines"
                                :key="line.user_id"
                                class="transition-colors hover:bg-line-soft/40"
                            >
                                <td class="px-5 py-3">
                                    <p class="text-[13.5px] font-medium">
                                        {{ line.name }}
                                    </p>
                                    <p
                                        v-if="line.employee_id"
                                        class="text-[11.5px] text-faint"
                                    >
                                        {{ line.employee_id }}
                                    </p>
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        v-if="line.rsa_number"
                                        class="tabular font-mono text-[12.5px]"
                                    >
                                        {{ line.rsa_number }}
                                    </span>
                                    <StatusPill v-else tone="brass">
                                        Missing
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3 text-[12.5px] text-muted">
                                    {{ line.pfa_name ?? '-' }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                >
                                    {{ show(line.employee) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px]"
                                >
                                    {{ show(line.employer) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 font-mono text-[12.5px] font-semibold"
                                >
                                    {{ show(line.total) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </div>

        <ModalShell
            :open="remitOpen"
            title="Record the remittance"
            subtitle="The app cannot see a bank transfer, so this is what somebody enters after making one."
            @close="remitOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.pension_reference"
                    label="Reference"
                    required
                    placeholder="Bank reference or the administrator's receipt"
                    :error="form.errors.pension_reference"
                />
                <TextField
                    v-model="form.pension_remitted_at"
                    label="Paid on"
                    type="date"
                    :error="form.errors.pension_remitted_at"
                    hint="Left blank, today is recorded."
                />
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="remitOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Record it
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
