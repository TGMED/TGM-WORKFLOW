<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { amount, dateTime } from '@/lib/format';
import type { PayrollStatusTone, PayslipLine } from '@/types';

type PayslipRow = {
    id: number;
    user: {
        id: number;
        name: string;
        employee_id: string | null;
        department: string | null;
    };
    currency: string;
    earnings: PayslipLine[];
    deductions: PayslipLine[];
    gross_pay: number;
    total_deductions: number;
    net_pay: number;
    employer_pension: number;
};

const props = defineProps<{
    run: {
        id: number;
        period_label: string;
        status: string;
        status_label: string;
        status_tone: PayrollStatusTone;
        is_draft: boolean;
        created_by: string | null;
        finalised_by: string | null;
        finalised_at: string | null;
    };
    payslips: PayslipRow[];
    totals: {
        headcount: number;
        gross: number;
        deductions: number;
        net: number;
        employer_pension: number;
    };
}>();

const expanded = ref<number | null>(null);
const confirming = ref(false);
const busy = ref(false);

function rebuild() {
    busy.value = true;

    router.post(
        `/admin/payroll/${props.run.id}/rebuild`,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function finalise() {
    busy.value = true;

    router.post(
        `/admin/payroll/${props.run.id}/finalise`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                confirming.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Payroll · ${run.period_label}`" />

    <AppLayout
        :heading="run.period_label"
        lede="Every payslip this run produced. Check the totals before signing it off."
    >
        <template #toolbar>
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    href="/admin/payroll"
                    class="text-[13px] font-medium text-muted hover:text-text"
                >
                    All runs
                </Link>
                <AppButton
                    v-if="run.is_draft"
                    variant="secondary"
                    size="sm"
                    :loading="busy"
                    @click="rebuild"
                >
                    Rebuild
                </AppButton>
                <AppButton
                    v-if="run.is_draft"
                    size="sm"
                    @click="confirming = true"
                >
                    Finalise
                </AppButton>
            </div>
        </template>

        <div class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <StatusPill :tone="run.status_tone" dot>
                    {{ run.status_label }}
                </StatusPill>
                <p v-if="run.finalised_by" class="text-[12.5px] text-faint">
                    Signed off by {{ run.finalised_by }}
                    <template v-if="run.finalised_at">
                        on {{ dateTime(run.finalised_at) }}
                    </template>
                </p>
                <p v-else-if="run.is_draft" class="text-[12.5px] text-faint">
                    A draft. Nobody can see these payslips yet.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="People" :value="totals.headcount" />
                <StatTile
                    label="Gross"
                    :value="totals.gross"
                    :decimals="2"
                    tone="brass"
                />
                <StatTile
                    label="Deductions"
                    :value="totals.deductions"
                    :decimals="2"
                />
                <StatTile
                    label="Net to pay"
                    :value="totals.net"
                    :decimals="2"
                    tone="signal"
                />
            </div>

            <Panel
                title="Payslips"
                :subtitle="`Employer pension on top: ${amount(totals.employer_pension)}`"
                flush
            >
                <EmptyState
                    v-if="payslips.length === 0"
                    title="Nothing in this run"
                    message="Nobody active has a salary on file. Set salaries, then rebuild."
                />

                <ul v-else class="divide-y divide-line-soft">
                    <li v-for="slip in payslips" :key="slip.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 px-5 py-3.5 text-left transition-colors hover:bg-sunken/40"
                            @click="
                                expanded = expanded === slip.id ? null : slip.id
                            "
                        >
                            <div class="min-w-0">
                                <p class="text-[13.5px] font-medium">
                                    {{ slip.user.name }}
                                </p>
                                <p class="text-[12px] text-faint">
                                    {{ slip.user.employee_id ?? '—' }}
                                    <template v-if="slip.user.department">
                                        · {{ slip.user.department }}
                                    </template>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-6">
                                <span
                                    class="tabular hidden font-mono text-[13px] text-muted sm:inline"
                                >
                                    {{ amount(slip.gross_pay) }}
                                </span>
                                <span
                                    class="tabular hidden font-mono text-[13px] text-muted sm:inline"
                                >
                                    −{{ amount(slip.total_deductions) }}
                                </span>
                                <span
                                    class="tabular font-mono text-[13.5px] font-semibold"
                                >
                                    {{ amount(slip.net_pay) }}
                                </span>
                            </div>
                        </button>

                        <div
                            v-if="expanded === slip.id"
                            class="grid gap-5 bg-sunken/40 px-5 py-4 sm:grid-cols-2"
                        >
                            <div>
                                <p class="eyebrow">Earnings</p>
                                <ul class="mt-2 space-y-1.5">
                                    <li
                                        v-for="line in slip.earnings"
                                        :key="line.label"
                                        class="flex justify-between gap-4 text-[12.5px]"
                                    >
                                        <span class="text-muted">
                                            {{ line.label }}
                                        </span>
                                        <span class="tabular font-mono">
                                            {{ amount(line.amount) }}
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div>
                                <p class="eyebrow">Deductions</p>
                                <ul class="mt-2 space-y-1.5">
                                    <li
                                        v-for="line in slip.deductions"
                                        :key="line.label"
                                        class="text-[12.5px]"
                                    >
                                        <div class="flex justify-between gap-4">
                                            <span class="text-muted">
                                                {{ line.label }}
                                            </span>
                                            <span class="tabular font-mono">
                                                {{ amount(line.amount) }}
                                            </span>
                                        </div>
                                        <p
                                            v-if="line.basis"
                                            class="text-[11.5px] text-faint"
                                        >
                                            {{ line.basis }}
                                        </p>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </li>
                </ul>
            </Panel>
        </div>

        <ModalShell
            :open="confirming"
            width="md"
            title="Finalise this run?"
            :subtitle="run.period_label"
            @close="confirming = false"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                Every payslip in this run becomes visible to the person it
                belongs to, and the run can no longer be rebuilt or deleted.
                Check the totals first — after this, a correction means running
                an adjustment, not editing what was paid.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="confirming = false">
                    Not yet
                </AppButton>
                <AppButton :loading="busy" @click="finalise">
                    Finalise and publish
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
