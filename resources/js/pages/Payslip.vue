<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppButton from '@/components/ui/AppButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { fullDate, money } from '@/lib/format';

type Line = {
    label: string;
    amount: number;
    basis?: string;
};

const props = defineProps<{
    payslip: {
        id: number;
        period_label: string;
        currency: string;
        earnings: Line[];
        deductions: Line[];
        gross_pay: number;
        total_deductions: number;
        net_pay: number;
        employer_pension: number;
        issued_at: string | null;
    };
    employee: {
        name: string;
        employee_id: string | null;
        department: string | null;
        position: string | null;
        bank_name: string | null;
        account_tail: string | null;
    };
    company: string;
}>();

function show(value: number) {
    return money(value, props.payslip.currency);
}

function printSheet() {
    window.print();
}
</script>

<template>
    <Head :title="`Payslip · ${payslip.period_label}`" />

    <AppLayout
        :heading="`Payslip · ${payslip.period_label}`"
        lede="Keep a copy. This is the statement of what you were paid for the month."
    >
        <template #toolbar>
            <div class="flex items-center gap-2">
                <Link
                    href="/payslips"
                    class="text-[13px] font-medium text-muted hover:text-text"
                >
                    All payslips
                </Link>
                <AppButton size="sm" variant="secondary" @click="printSheet">
                    Print
                </AppButton>
            </div>
        </template>

        <!-- The document itself. `payslip-sheet` is what the print rules
             below keep on the page; everything around it is dropped. -->
        <article
            class="payslip-sheet mx-auto max-w-3xl rounded-2xl border border-line bg-panel p-6 shadow-panel sm:p-8"
        >
            <header
                class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5"
            >
                <div>
                    <p class="eyebrow">{{ company }}</p>
                    <h2
                        class="mt-1 font-display text-xl font-semibold tracking-tight"
                    >
                        Payslip
                    </h2>
                    <p class="mt-0.5 text-[13px] text-muted">
                        {{ payslip.period_label }}
                    </p>
                </div>

                <p v-if="payslip.issued_at" class="text-[12px] text-faint">
                    Issued {{ fullDate(payslip.issued_at) }}
                </p>
            </header>

            <dl
                class="grid gap-4 border-b border-line-soft py-5 sm:grid-cols-2"
            >
                <div>
                    <dt class="text-[12px] text-faint">Employee</dt>
                    <dd class="mt-0.5 text-[14px] font-semibold">
                        {{ employee.name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[12px] text-faint">Staff ID</dt>
                    <dd class="mt-0.5 text-[14px]">
                        {{ employee.employee_id ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[12px] text-faint">Position</dt>
                    <dd class="mt-0.5 text-[14px]">
                        {{ employee.position ?? '—' }}
                        <span v-if="employee.department" class="text-muted">
                            · {{ employee.department }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-[12px] text-faint">Paid into</dt>
                    <dd class="mt-0.5 text-[14px]">
                        <template v-if="employee.bank_name">
                            {{ employee.bank_name }}
                            <span
                                v-if="employee.account_tail"
                                class="tabular font-mono text-muted"
                            >
                                {{ employee.account_tail }}
                            </span>
                        </template>
                        <span v-else class="text-muted">
                            No bank details on file
                        </span>
                    </dd>
                </div>
            </dl>

            <div class="grid gap-6 py-5 sm:grid-cols-2">
                <section>
                    <h3
                        class="text-[12px] font-semibold tracking-wide text-faint uppercase"
                    >
                        Earnings
                    </h3>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="line in payslip.earnings"
                            :key="line.label"
                            class="flex items-baseline justify-between gap-4 text-[13.5px]"
                        >
                            <span class="text-muted">{{ line.label }}</span>
                            <span class="tabular font-mono">
                                {{ show(line.amount) }}
                            </span>
                        </li>
                    </ul>
                    <p
                        class="mt-3 flex items-baseline justify-between gap-4 border-t border-line-soft pt-3 text-[13.5px] font-semibold"
                    >
                        <span>Gross pay</span>
                        <span class="tabular font-mono">
                            {{ show(payslip.gross_pay) }}
                        </span>
                    </p>
                </section>

                <section>
                    <h3
                        class="text-[12px] font-semibold tracking-wide text-faint uppercase"
                    >
                        Deductions
                    </h3>

                    <ul v-if="payslip.deductions.length" class="mt-3 space-y-2">
                        <li
                            v-for="line in payslip.deductions"
                            :key="line.label"
                            class="text-[13.5px]"
                        >
                            <div
                                class="flex items-baseline justify-between gap-4"
                            >
                                <span class="text-muted">{{ line.label }}</span>
                                <span class="tabular font-mono">
                                    {{ show(line.amount) }}
                                </span>
                            </div>
                            <p
                                v-if="line.basis"
                                class="mt-0.5 text-[11.5px] text-faint"
                            >
                                {{ line.basis }}
                            </p>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-[13px] text-muted">
                        Nothing was deducted this month.
                    </p>

                    <p
                        class="mt-3 flex items-baseline justify-between gap-4 border-t border-line-soft pt-3 text-[13.5px] font-semibold"
                    >
                        <span>Total deductions</span>
                        <span class="tabular font-mono">
                            {{ show(payslip.total_deductions) }}
                        </span>
                    </p>
                </section>
            </div>

            <div
                class="flex flex-wrap items-baseline justify-between gap-4 rounded-xl bg-sunken px-5 py-4"
            >
                <span class="text-[13.5px] font-semibold">Take-home pay</span>
                <span
                    class="tabular font-mono text-xl font-semibold tracking-tight"
                >
                    {{ show(payslip.net_pay) }}
                </span>
            </div>

            <p
                v-if="payslip.employer_pension > 0"
                class="mt-4 text-[12px] text-faint"
            >
                Your employer also paid
                {{ show(payslip.employer_pension) }} into your pension this
                month. That is on top of your pay, not taken from it.
            </p>

            <p class="mt-4 text-[11.5px] text-faint">
                If anything here looks wrong, raise it with the people team
                before the next payroll closes.
            </p>
        </article>
    </AppLayout>
</template>

<style>
/* A payslip gets printed and filed, so printing gives the document alone —
   no navigation, no toolbar, no panel chrome around it. */
@media print {
    body {
        background: #fff;
    }

    body :not(.payslip-sheet):not(.payslip-sheet *) {
        visibility: hidden;
    }

    .payslip-sheet,
    .payslip-sheet * {
        visibility: visible;
    }

    .payslip-sheet {
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        max-width: none;
        border: 0;
        box-shadow: none;
        padding: 0;
    }
}
</style>
