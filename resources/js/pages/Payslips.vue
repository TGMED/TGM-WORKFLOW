<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { money } from '@/lib/format';

type PayslipRow = {
    id: number;
    period_label: string;
    year: number;
    month: number;
    currency: string;
    gross_pay: number;
    total_deductions: number;
    net_pay: number;
};

defineProps<{
    payslips: PayslipRow[];
    latest: PayslipRow | null;
}>();
</script>

<template>
    <Head title="Payslips" />

    <AppLayout
        heading="Payslips"
        lede="Your pay, month by month. A payslip appears here once payroll has signed the month off."
    >
        <div class="space-y-6">
            <section
                v-if="latest"
                class="rounded-2xl border border-line bg-panel p-5 shadow-panel"
            >
                <p class="eyebrow">Most recent</p>
                <h2
                    class="mt-1 font-display text-[17px] font-semibold tracking-tight"
                >
                    {{ latest.period_label }}
                </h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-[12px] text-faint">Gross</p>
                        <p class="tabular mt-0.5 font-mono text-[15px]">
                            {{ money(latest.gross_pay, latest.currency) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[12px] text-faint">Deductions</p>
                        <p class="tabular mt-0.5 font-mono text-[15px]">
                            {{
                                money(latest.total_deductions, latest.currency)
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[12px] text-faint">Take-home</p>
                        <p
                            class="tabular mt-0.5 font-mono text-[15px] font-semibold text-signal"
                        >
                            {{ money(latest.net_pay, latest.currency) }}
                        </p>
                    </div>
                </div>

                <Link
                    :href="`/payslips/${latest.id}`"
                    class="mt-4 inline-flex text-[13px] font-medium text-beacon hover:underline"
                >
                    Open the full payslip
                </Link>
            </section>

            <Panel title="Every payslip" flush>
                <EmptyState
                    v-if="payslips.length === 0"
                    title="No payslips yet"
                    message="Once payroll finalises a month, that month's payslip appears here. If you think one is missing, ask the people team."
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Month</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Gross
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Deductions
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Take-home
                                </th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="row in payslips"
                                :key="row.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5 font-medium">
                                    {{ row.period_label }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono text-muted"
                                >
                                    {{ money(row.gross_pay, row.currency) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono text-muted"
                                >
                                    {{
                                        money(
                                            row.total_deductions,
                                            row.currency,
                                        )
                                    }}
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono font-semibold"
                                >
                                    {{ money(row.net_pay, row.currency) }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <Link
                                        :href="`/payslips/${row.id}`"
                                        class="text-[12.5px] font-medium text-beacon hover:underline"
                                    >
                                        View
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </div>
    </AppLayout>
</template>
