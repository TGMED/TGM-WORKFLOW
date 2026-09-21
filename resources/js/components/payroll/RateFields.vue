<script setup lang="ts">
import { computed } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import type { PayrollBand } from '@/types';

/**
 * The rate fields payroll computes with, in one place.
 *
 * Used twice: for the company's rates on the rules tab, and for one person's
 * own set. The two are the same fields answering the same questions, so they
 * are the same component rather than two that drift apart.
 */

/** The shape of whichever Inertia form is being edited. */
type RateForm = {
    currency: string;
    basic_percent: number | string;
    housing_percent: number | string;
    transport_percent: number | string;
    pension_employee_percent: number | string;
    pension_employer_percent: number | string;
    nhf_percent: number | string;
    rent_relief_percent: number | string;
    rent_relief_cap: number | string;
    tax_bands: PayrollBand[];
    errors: Partial<Record<string, string>>;
};

const props = defineProps<{ form: RateForm }>();

// What is left of the package once basic, housing and transport are taken.
// Shown live because a split that overruns 100% is rejected on save.
const otherPercent = computed(
    () =>
        Math.round(
            (100 -
                Number(props.form.basic_percent) -
                Number(props.form.housing_percent) -
                Number(props.form.transport_percent)) *
                100,
        ) / 100,
);

function addBand() {
    // New bands go in below the open-ended one, which has to stay last.
    const open = props.form.tax_bands.filter((band) => band.up_to === null);
    const bounded = props.form.tax_bands.filter((band) => band.up_to !== null);

    props.form.tax_bands = [...bounded, { up_to: 0, rate: 0 }, ...open];
}

function removeBand(index: number) {
    props.form.tax_bands = props.form.tax_bands.filter((_, i) => i !== index);
}
</script>

<template>
    <div class="space-y-6">
        <section>
            <h3 class="eyebrow">How a package splits</h3>
            <p class="mt-1 text-[12.5px] text-muted">
                Pension is assessed on basic, housing and transport together, so
                this split changes what people pay in.
            </p>

            <div class="mt-3 grid gap-4 sm:grid-cols-4">
                <TextField
                    v-model="form.basic_percent"
                    label="Basic %"
                    type="number"
                    step="0.01"
                    :error="form.errors.basic_percent"
                />
                <TextField
                    v-model="form.housing_percent"
                    label="Housing %"
                    type="number"
                    step="0.01"
                    :error="form.errors.housing_percent"
                />
                <TextField
                    v-model="form.transport_percent"
                    label="Transport %"
                    type="number"
                    step="0.01"
                    :error="form.errors.transport_percent"
                />
                <div class="space-y-1.5">
                    <p class="text-[13px] font-medium text-muted">
                        Other allowances
                    </p>
                    <p
                        class="rounded-xl border border-line bg-sunken px-3.5 py-2.5 text-sm"
                        :class="otherPercent < 0 ? 'text-alert' : 'text-muted'"
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
                    v-model="form.pension_employee_percent"
                    label="Pension · employee %"
                    type="number"
                    step="0.01"
                    :error="form.errors.pension_employee_percent"
                />
                <TextField
                    v-model="form.pension_employer_percent"
                    label="Pension · employer %"
                    type="number"
                    step="0.01"
                    hint="Paid on top, not deducted."
                    :error="form.errors.pension_employer_percent"
                />
                <TextField
                    v-model="form.nhf_percent"
                    label="NHF % of basic"
                    type="number"
                    step="0.01"
                    :error="form.errors.nhf_percent"
                />
            </div>
        </section>

        <section>
            <h3 class="eyebrow">Rent relief</h3>
            <p class="mt-1 text-[12.5px] text-muted">
                Taken off before tax is assessed, from the annual rent on each
                employee's own HR record. Nobody with no rent on file gets any.
            </p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <TextField
                    v-model="form.rent_relief_percent"
                    label="Relief % of rent paid"
                    type="number"
                    step="0.01"
                    :error="form.errors.rent_relief_percent"
                />
                <TextField
                    v-model="form.rent_relief_cap"
                    label="Capped at"
                    type="number"
                    step="0.01"
                    :error="form.errors.rent_relief_cap"
                />
            </div>
        </section>

        <section>
            <h3 class="eyebrow">Annual tax bands</h3>
            <p class="mt-1 text-[12.5px] text-muted">
                Each band is charged only on the slice of income inside it. One
                band must be left open-ended to catch everything above the rest.
            </p>

            <p v-if="form.errors.tax_bands" class="mt-2 text-[13px] text-alert">
                {{ form.errors.tax_bands }}
            </p>

            <ul class="mt-3 space-y-2">
                <li
                    v-for="(band, index) in form.tax_bands"
                    :key="index"
                    class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-sunken/40 px-3.5 py-3"
                >
                    <div class="min-w-[10rem] flex-1">
                        <label class="text-[12px] font-medium text-muted">
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
                        <label class="text-[12px] font-medium text-muted">
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
    </div>
</template>
