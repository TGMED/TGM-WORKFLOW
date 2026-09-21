<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { usePaginated } from '@/composables/usePaginated';
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

const pages = usePaginated(() => props.payslips, { perPage: 15 });
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
                <Link
                    :href="`/admin/payroll/${run.id}/pension`"
                    class="text-[13px] font-medium text-muted hover:text-text"
                >
                    Pension schedule
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
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Gross
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Deductions
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Net
                                </th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="payslips.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        title="Nothing in this run"
                                        message="Nobody active has a salary on file. Set salaries, then rebuild."
                                    />
                                </td>
                            </tr>
                            <template
                                v-for="slip in pages.paged"
                                :key="slip.id"
                            >
                                <tr
                                    class="cursor-pointer transition-colors hover:bg-sunken/40"
                                    @click="
                                        expanded =
                                            expanded === slip.id
                                                ? null
                                                : slip.id
                                    "
                                >
                                    <td class="px-5 py-3.5">
                                        <p class="font-medium">
                                            {{ slip.user.name }}
                                        </p>
                                        <p class="text-[12px] text-faint">
                                            {{ slip.user.employee_id ?? '—' }}
                                            <template
                                                v-if="slip.user.department"
                                            >
                                                · {{ slip.user.department }}
                                            </template>
                                        </p>
                                    </td>
                                    <td
                                        class="tabular px-5 py-3.5 text-right font-mono text-muted"
                                    >
                                        {{ amount(slip.gross_pay) }}
                                    </td>
                                    <td
                                        class="tabular px-5 py-3.5 text-right font-mono text-muted"
                                    >
                                        −{{ amount(slip.total_deductions) }}
                                    </td>
                                    <td
                                        class="tabular px-5 py-3.5 text-right font-mono font-semibold"
                                    >
                                        {{ amount(slip.net_pay) }}
                                    </td>
                                    <td
                                        class="px-5 py-3.5 text-right text-[12.5px] font-medium text-muted"
                                    >
                                        {{
                                            expanded === slip.id
                                                ? 'Hide'
                                                : 'Breakdown'
                                        }}
                                    </td>
                                </tr>

                                <tr v-if="expanded === slip.id">
                                    <td
                                        colspan="5"
                                        class="bg-sunken/40 px-5 py-4"
                                    >
                                        <div class="grid gap-5 sm:grid-cols-2">
                                            <div>
                                                <p class="eyebrow">Earnings</p>
                                                <ul class="mt-2 space-y-1.5">
                                                    <li
                                                        v-for="line in slip.earnings"
                                                        :key="line.label"
                                                        class="flex justify-between gap-4 text-[12.5px]"
                                                    >
                                                        <span
                                                            class="text-muted"
                                                        >
                                                            {{ line.label }}
                                                        </span>
                                                        <span
                                                            class="tabular font-mono"
                                                        >
                                                            {{
                                                                amount(
                                                                    line.amount,
                                                                )
                                                            }}
                                                        </span>
                                                    </li>
                                                </ul>
                                            </div>

                                            <div>
                                                <p class="eyebrow">
                                                    Deductions
                                                </p>
                                                <ul class="mt-2 space-y-1.5">
                                                    <li
                                                        v-for="line in slip.deductions"
                                                        :key="line.label"
                                                        class="text-[12.5px]"
                                                    >
                                                        <div
                                                            class="flex justify-between gap-4"
                                                        >
                                                            <span
                                                                class="text-muted"
                                                            >
                                                                {{ line.label }}
                                                            </span>
                                                            <span
                                                                class="tabular font-mono"
                                                            >
                                                                {{
                                                                    amount(
                                                                        line.amount,
                                                                    )
                                                                }}
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
