<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RateFields from '@/components/payroll/RateFields.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { amount, money } from '@/lib/format';
import type { PayrollRunRow, PayrollSettings, PayrollStaffRow } from '@/types';

const props = defineProps<{
    runs: PayrollRunRow[];
    staff: PayrollStaffRow[];
    settings: PayrollSettings;
    unpaid: number;
    on_personal_rates: number;
    next_period: { year: number; month: number };
}>();

const tab = ref<'runs' | 'salaries' | 'rules'>('runs');
const editing = ref<PayrollStaffRow | null>(null);
/** The person whose own rates are being edited, if any. */
const rating = ref<PayrollStaffRow | null>(null);
const search = ref('');

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const runForm = useForm({
    year: props.next_period.year,
    month: props.next_period.month,
});

const salaryForm = useForm({
    user_id: 0,
    annual_gross: null as number | null,
    pension_applies: true,
    nhf_applies: true,
    effective_from: null as string | null,
});

const rulesForm = useForm({
    currency: props.settings.currency,
    basic_percent: props.settings.basic_percent,
    housing_percent: props.settings.housing_percent,
    transport_percent: props.settings.transport_percent,
    pension_employee_percent: props.settings.pension_employee_percent,
    pension_employer_percent: props.settings.pension_employer_percent,
    nhf_percent: props.settings.nhf_percent,
    rent_relief_percent: props.settings.rent_relief_percent,
    rent_relief_cap: props.settings.rent_relief_cap,
    tax_bands: props.settings.tax_bands.map((band) => ({ ...band })),
});

/*
 * One person's own rates. A separate form from the company's so an unsaved
 * edit to one cannot leak into the other, and so the errors land on the right
 * fields when either is rejected.
 */
const personalForm = useForm({
    currency: props.settings.currency,
    basic_percent: props.settings.basic_percent,
    housing_percent: props.settings.housing_percent,
    transport_percent: props.settings.transport_percent,
    pension_employee_percent: props.settings.pension_employee_percent,
    pension_employer_percent: props.settings.pension_employer_percent,
    nhf_percent: props.settings.nhf_percent,
    rent_relief_percent: props.settings.rent_relief_percent,
    rent_relief_cap: props.settings.rent_relief_cap,
    tax_bands: props.settings.tax_bands.map((band) => ({ ...band })),
});

const monthOptions = months.map((label, index) => ({
    value: index + 1,
    label,
}));

const yearOptions = computed(() => {
    const now = new Date().getFullYear();

    return [now - 1, now, now + 1].map((year) => ({
        value: year,
        label: String(year),
    }));
});

const visibleStaff = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return props.staff;
    }

    return props.staff.filter(
        (person) =>
            person.name.toLowerCase().includes(term) ||
            (person.employee_id ?? '').toLowerCase().includes(term) ||
            (person.department ?? '').toLowerCase().includes(term),
    );
});

const monthlyBill = computed(() =>
    props.staff.reduce(
        (total, person) => total + (person.monthly_gross ?? 0),
        0,
    ),
);

function editSalary(person: PayrollStaffRow) {
    salaryForm.clearErrors();
    salaryForm.defaults({
        user_id: person.id,
        annual_gross: person.annual_gross,
        pension_applies: person.pension_applies,
        nhf_applies: person.nhf_applies,
        effective_from: person.effective_from,
    });
    salaryForm.reset();
    editing.value = person;
}

function saveSalary() {
    salaryForm.post('/admin/salaries', {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

/**
 * Open somebody's own rates. Their set where they have one; otherwise the
 * company's, as the starting point they would be copied from.
 */
function editRates(person: PayrollStaffRow) {
    const source = person.rates ?? props.settings;

    personalForm.clearErrors();
    personalForm.defaults({
        currency: source.currency,
        basic_percent: source.basic_percent,
        housing_percent: source.housing_percent,
        transport_percent: source.transport_percent,
        pension_employee_percent: source.pension_employee_percent,
        pension_employer_percent: source.pension_employer_percent,
        nhf_percent: source.nhf_percent,
        rent_relief_percent: source.rent_relief_percent,
        rent_relief_cap: source.rent_relief_cap,
        tax_bands: source.tax_bands.map((band) => ({ ...band })),
    });
    personalForm.reset();
    rating.value = person;
}

function savePersonalRates() {
    if (rating.value === null) {
        return;
    }

    const person = rating.value;

    const put = () =>
        personalForm.put(`/admin/staff/${person.id}/payroll-settings`, {
            preserveScroll: true,
            onSuccess: () => {
                rating.value = null;
            },
        });

    // Somebody not yet on their own rates needs the row creating first; the
    // copy it starts from is then overwritten by what is in the form.
    if (person.rates === null) {
        router.post(
            `/admin/staff/${person.id}/payroll-settings`,
            {},
            { preserveScroll: true, onSuccess: put },
        );

        return;
    }

    put();
}

function backToCompanyRates(person: PayrollStaffRow) {
    router.delete(`/admin/staff/${person.id}/payroll-settings`, {
        preserveScroll: true,
        onSuccess: () => {
            rating.value = null;
        },
    });
}

function openRun() {
    runForm.post('/admin/payroll', { preserveScroll: true });
}

function saveRules() {
    rulesForm.put('/admin/payroll-settings', { preserveScroll: true });
}

function deleteRun(run: PayrollRunRow) {
    router.delete(`/admin/payroll/${run.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Payroll" />

    <AppLayout
        heading="Payroll"
        lede="Salaries, the rules pay is worked out under, and each month's run."
    >
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="On the payroll"
                    :value="staff.length - unpaid"
                    caption="With a salary on file"
                />
                <StatTile
                    label="No salary set"
                    :value="unpaid"
                    :tone="unpaid > 0 ? 'alert' : 'default'"
                    caption="These people get no payslip"
                />
                <StatTile
                    label="Monthly gross bill"
                    :value="monthlyBill"
                    :decimals="0"
                    tone="brass"
                    :caption="settings.currency"
                />
                <StatTile label="Runs on file" :value="runs.length" />
            </div>

            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="option in [
                        { key: 'runs', label: 'Monthly runs' },
                        { key: 'salaries', label: 'Salaries' },
                        { key: 'rules', label: 'Rates and tax bands' },
                    ]"
                    :key="option.key"
                    type="button"
                    class="rounded-full px-3.5 py-1.5 text-[13px] font-medium transition-colors"
                    :class="
                        tab === option.key
                            ? 'bg-brand text-white'
                            : 'bg-line-soft text-muted hover:text-text'
                    "
                    @click="tab = option.key as typeof tab"
                >
                    {{ option.label }}
                </button>
            </div>

            <!-- Runs -->
            <Panel
                v-if="tab === 'runs'"
                title="Monthly runs"
                subtitle="A run is drafted, checked, and then finalised. Finalising is what puts payslips in front of staff."
                flush
            >
                <template #action>
                    <div class="flex flex-wrap items-end gap-2">
                        <SelectField
                            v-model="runForm.month"
                            :options="monthOptions"
                        />
                        <SelectField
                            v-model="runForm.year"
                            :options="yearOptions"
                        />
                        <AppButton
                            size="sm"
                            :loading="runForm.processing"
                            @click="openRun"
                        >
                            Draft this month
                        </AppButton>
                    </div>
                </template>

                <div
                    v-if="unpaid > 0"
                    class="mx-5 mt-5 rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                >
                    {{ unpaid }}
                    {{ unpaid === 1 ? 'person has' : 'people have' }} no salary
                    on file and will be left out of any run you draft. Set them
                    under Salaries first.
                </div>

                <EmptyState
                    v-if="runs.length === 0"
                    title="No payroll has been run"
                    message="Pick a month above and draft it. Nothing is visible to staff until you finalise it."
                />

                <ul v-else class="mt-2 divide-y divide-line-soft">
                    <li
                        v-for="run in runs"
                        :key="run.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex items-center gap-2 text-[14px] font-semibold tracking-tight"
                            >
                                {{ run.period_label }}
                                <StatusPill :tone="run.status_tone" dot>
                                    {{ run.status_label }}
                                </StatusPill>
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ run.headcount }}
                                {{ run.headcount === 1 ? 'person' : 'people' }}
                                · gross
                                {{ money(run.gross_total, settings.currency) }}
                                · net
                                {{ money(run.net_total, settings.currency) }}
                            </p>
                            <p
                                v-if="run.finalised_by"
                                class="mt-0.5 text-[12px] text-faint"
                            >
                                Signed off by {{ run.finalised_by }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <Link
                                :href="`/admin/payroll/${run.id}`"
                                class="text-[12.5px] font-medium text-beacon hover:underline"
                            >
                                Open
                            </Link>
                            <AppButton
                                v-if="run.is_draft"
                                variant="ghost"
                                size="sm"
                                @click="deleteRun(run)"
                            >
                                Delete draft
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </Panel>

            <!-- Salaries -->
            <Panel
                v-else-if="tab === 'salaries'"
                title="Salaries"
                subtitle="One annual package per person. Basic, housing, pension and tax are all worked out from it."
                flush
            >
                <template #action>
                    <TextField v-model="search" placeholder="Search staff" />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Person</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Annual gross
                                </th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Monthly
                                </th>
                                <th class="px-5 py-3 font-medium">Schemes</th>
                                <th class="px-5 py-3 font-medium">Rates</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="person in visibleStaff"
                                :key="person.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">
                                        {{ person.name }}
                                    </p>
                                    <p class="text-[12px] text-faint">
                                        {{ person.employee_id ?? '—' }}
                                        <template v-if="person.department">
                                            · {{ person.department }}
                                        </template>
                                    </p>
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono"
                                >
                                    <span v-if="person.annual_gross !== null">
                                        {{ amount(person.annual_gross) }}
                                    </span>
                                    <StatusPill v-else tone="alert">
                                        Not set
                                    </StatusPill>
                                </td>
                                <td
                                    class="tabular px-5 py-3.5 text-right font-mono text-muted"
                                >
                                    {{ amount(person.monthly_gross) }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] text-muted"
                                >
                                    <template
                                        v-if="person.annual_gross !== null"
                                    >
                                        {{
                                            person.pension_applies
                                                ? 'Pension'
                                                : 'No pension'
                                        }}
                                        ·
                                        {{
                                            person.nhf_applies
                                                ? 'NHF'
                                                : 'No NHF'
                                        }}
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        v-if="person.rates"
                                        tone="brass"
                                    >
                                        Own rates
                                    </StatusPill>
                                    <span
                                        v-else
                                        class="text-[12.5px] text-faint"
                                    >
                                        Company
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <AppButton
                                        variant="ghost"
                                        size="sm"
                                        @click="editRates(person)"
                                    >
                                        Rates
                                    </AppButton>
                                    <AppButton
                                        variant="ghost"
                                        size="sm"
                                        @click="editSalary(person)"
                                    >
                                        {{
                                            person.annual_gross === null
                                                ? 'Set'
                                                : 'Edit'
                                        }}
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>

            <!-- Rules -->
            <Panel
                v-else
                title="Rates and tax bands"
                subtitle="Everything payroll computes with. Change these when the law does, then rebuild any draft run to apply them."
            >
                <form class="space-y-6" @submit.prevent="saveRules">
                    <RateFields :form="rulesForm" />

                    <div
                        class="flex justify-end border-t border-line-soft pt-4"
                    >
                        <AppButton
                            :loading="rulesForm.processing"
                            @click="saveRules"
                        >
                            Save rules
                        </AppButton>
                    </div>
                </form>
            </Panel>
        </div>

        <ModalShell
            :open="editing !== null"
            :title="editing ? `Salary · ${editing.name}` : ''"
            subtitle="The whole annual package. Everything else on the payslip is worked out from it."
            @close="editing = null"
        >
            <form class="space-y-4" @submit.prevent="saveSalary">
                <TextField
                    v-model="salaryForm.annual_gross"
                    label="Annual gross"
                    type="number"
                    step="0.01"
                    required
                    :hint="
                        salaryForm.annual_gross
                            ? `${money(Number(salaryForm.annual_gross) / 12, settings.currency)} a month`
                            : undefined
                    "
                    :error="salaryForm.errors.annual_gross"
                />

                <TextField
                    v-model="salaryForm.effective_from"
                    label="Effective from"
                    type="date"
                    hint="For the record. Runs use whatever is on file when they are built."
                    :error="salaryForm.errors.effective_from"
                />

                <div
                    class="space-y-2.5 rounded-xl border border-line bg-sunken/40 p-3.5"
                >
                    <label
                        class="flex cursor-pointer items-center gap-2.5 text-[13px]"
                    >
                        <input
                            v-model="salaryForm.pension_applies"
                            type="checkbox"
                            class="size-4 rounded border-line text-brand focus:ring-beacon/30"
                        />
                        In the pension scheme
                    </label>
                    <label
                        class="flex cursor-pointer items-center gap-2.5 text-[13px]"
                    >
                        <input
                            v-model="salaryForm.nhf_applies"
                            type="checkbox"
                            class="size-4 rounded border-line text-brand focus:ring-beacon/30"
                        />
                        Contributes to the National Housing Fund
                    </label>
                </div>

                <p
                    v-if="editing && editing.annual_rent > 0"
                    class="text-[12px] text-faint"
                >
                    Annual rent of {{ amount(editing.annual_rent) }} is on their
                    HR record and will buy them tax relief.
                </p>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="editing = null">
                    Cancel
                </AppButton>
                <AppButton :loading="salaryForm.processing" @click="saveSalary">
                    Save salary
                </AppButton>
            </template>
        </ModalShell>

        <!-- One person's own rates. Opening it on somebody who is on the
             company rates shows those, and saving is what puts them on a set
             of their own. -->
        <ModalShell
            :open="rating !== null"
            :title="rating ? `Rates · ${rating.name}` : ''"
            :subtitle="
                rating?.rates
                    ? 'Their own rates. These do not follow the company rates when those change.'
                    : 'Showing the company rates. Saving puts this person on their own set, copied from these, and they stop following company changes.'
            "
            @close="rating = null"
        >
            <form class="space-y-6" @submit.prevent="savePersonalRates">
                <RateFields :form="personalForm" />
            </form>

            <template #footer>
                <AppButton
                    v-if="rating?.rates"
                    variant="ghost"
                    @click="backToCompanyRates(rating)"
                >
                    Back to company rates
                </AppButton>
                <AppButton variant="ghost" @click="rating = null">
                    Cancel
                </AppButton>
                <AppButton
                    :loading="personalForm.processing"
                    @click="savePersonalRates"
                >
                    {{ rating?.rates ? 'Save rates' : 'Use their own rates' }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
