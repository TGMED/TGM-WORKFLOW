<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
    next_period: { year: number; month: number };
}>();

const tab = ref<'runs' | 'salaries' | 'rules'>('runs');
const editing = ref<PayrollStaffRow | null>(null);
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

// What is left of the package once basic, housing and transport are taken.
// Shown live because a split that overruns 100% is rejected on save.
const otherPercent = computed(
    () =>
        Math.round(
            (100 -
                Number(rulesForm.basic_percent) -
                Number(rulesForm.housing_percent) -
                Number(rulesForm.transport_percent)) *
                100,
        ) / 100,
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

function openRun() {
    runForm.post('/admin/payroll', { preserveScroll: true });
}

function saveRules() {
    rulesForm.put('/admin/payroll-settings', { preserveScroll: true });
}

function addBand() {
    // New bands go in below the open-ended one, which has to stay last.
    const open = rulesForm.tax_bands.filter((band) => band.up_to === null);
    const bounded = rulesForm.tax_bands.filter((band) => band.up_to !== null);

    rulesForm.tax_bands = [...bounded, { up_to: 0, rate: 0 }, ...open];
}

function removeBand(index: number) {
    rulesForm.tax_bands = rulesForm.tax_bands.filter((_, i) => i !== index);
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
                                <td class="px-5 py-3.5 text-right">
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
                    <section>
                        <h3 class="eyebrow">How a package splits</h3>
                        <p class="mt-1 text-[12.5px] text-muted">
                            Pension is assessed on basic, housing and transport
                            together, so this split changes what people pay in.
                        </p>

                        <div class="mt-3 grid gap-4 sm:grid-cols-4">
                            <TextField
                                v-model="rulesForm.basic_percent"
                                label="Basic %"
                                type="number"
                                step="0.01"
                                :error="rulesForm.errors.basic_percent"
                            />
                            <TextField
                                v-model="rulesForm.housing_percent"
                                label="Housing %"
                                type="number"
                                step="0.01"
                                :error="rulesForm.errors.housing_percent"
                            />
                            <TextField
                                v-model="rulesForm.transport_percent"
                                label="Transport %"
                                type="number"
                                step="0.01"
                                :error="rulesForm.errors.transport_percent"
                            />
                            <div class="space-y-1.5">
                                <p class="text-[13px] font-medium text-muted">
                                    Other allowances
                                </p>
                                <p
                                    class="rounded-xl border border-line bg-sunken px-3.5 py-2.5 text-sm"
                                    :class="
                                        otherPercent < 0
                                            ? 'text-alert'
                                            : 'text-muted'
                                    "
                                >
                                    {{ otherPercent }}%
                                </p>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="eyebrow">Statutory deductions</h3>
                        <div class="mt-3 grid gap-4 sm:grid-cols-3">
                            <TextField
                                v-model="rulesForm.pension_employee_percent"
                                label="Pension · employee %"
                                type="number"
                                step="0.01"
                                :error="
                                    rulesForm.errors.pension_employee_percent
                                "
                            />
                            <TextField
                                v-model="rulesForm.pension_employer_percent"
                                label="Pension · employer %"
                                type="number"
                                step="0.01"
                                hint="Paid on top, not deducted."
                                :error="
                                    rulesForm.errors.pension_employer_percent
                                "
                            />
                            <TextField
                                v-model="rulesForm.nhf_percent"
                                label="NHF % of basic"
                                type="number"
                                step="0.01"
                                :error="rulesForm.errors.nhf_percent"
                            />
                        </div>
                    </section>

                    <section>
                        <h3 class="eyebrow">Rent relief</h3>
                        <p class="mt-1 text-[12.5px] text-muted">
                            Taken off before tax is assessed, from the annual
                            rent on each employee's own HR record. Nobody with
                            no rent on file gets any.
                        </p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <TextField
                                v-model="rulesForm.rent_relief_percent"
                                label="Relief % of rent paid"
                                type="number"
                                step="0.01"
                                :error="rulesForm.errors.rent_relief_percent"
                            />
                            <TextField
                                v-model="rulesForm.rent_relief_cap"
                                label="Capped at"
                                type="number"
                                step="0.01"
                                :error="rulesForm.errors.rent_relief_cap"
                            />
                        </div>
                    </section>

                    <section>
                        <h3 class="eyebrow">Annual tax bands</h3>
                        <p class="mt-1 text-[12.5px] text-muted">
                            Each band is charged only on the slice of income
                            inside it. One band must be left open-ended to catch
                            everything above the rest.
                        </p>

                        <p
                            v-if="rulesForm.errors.tax_bands"
                            class="mt-2 text-[13px] text-alert"
                        >
                            {{ rulesForm.errors.tax_bands }}
                        </p>

                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="(band, index) in rulesForm.tax_bands"
                                :key="index"
                                class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-sunken/40 px-3.5 py-3"
                            >
                                <div class="min-w-[10rem] flex-1">
                                    <label
                                        class="text-[12px] font-medium text-muted"
                                    >
                                        Up to
                                    </label>
                                    <input
                                        v-model="band.up_to"
                                        type="number"
                                        step="1"
                                        placeholder="Open-ended"
                                        class="mt-1 w-full rounded-lg border border-line bg-panel-raised px-3 py-2 font-mono text-[13px] focus:border-beacon focus:outline-none"
                                    />
                                </div>
                                <div class="w-28">
                                    <label
                                        class="text-[12px] font-medium text-muted"
                                    >
                                        Rate %
                                    </label>
                                    <input
                                        v-model="band.rate"
                                        type="number"
                                        step="0.01"
                                        class="mt-1 w-full rounded-lg border border-line bg-panel-raised px-3 py-2 font-mono text-[13px] focus:border-beacon focus:outline-none"
                                    />
                                </div>
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    @click="removeBand(index)"
                                >
                                    Remove
                                </AppButton>
                            </li>
                        </ul>

                        <AppButton
                            class="mt-3"
                            variant="secondary"
                            size="sm"
                            @click="addBand"
                        >
                            Add a band
                        </AppButton>
                    </section>

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
    </AppLayout>
</template>
