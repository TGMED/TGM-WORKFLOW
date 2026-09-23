<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AccountNameField from '@/components/banking/AccountNameField.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import TextField from '@/components/ui/TextField.vue';
import { useAccountLookup } from '@/composables/useAccountLookup';
import type { EmployeeProfile, ProfileOptions } from '@/types';

const props = defineProps<{
    profile: EmployeeProfile;
    options: ProfileOptions;
}>();

const form = useForm({
    bank_code: props.profile.bank_code,
    account_number: props.profile.account_number,
    bvn: props.profile.bvn,
    swift_code: props.profile.swift_code,
    sort_code: props.profile.sort_code,
    annual_rent: props.profile.annual_rent,
    rsa_number: props.profile.rsa_number,
    pfa_name: props.profile.pfa_name,
    tax_identification_number: props.profile.tax_identification_number,
    nhf_number: props.profile.nhf_number,
});

const { accountName, lookup, lookupError } = useAccountLookup(
    () => [form.bank_code, form.account_number] as const,
    props.profile.account_name,
);

/** Saved without a validation error, so a guided setup can move on. */
const emit = defineEmits<{ saved: [] }>();

function submit() {
    form.put('/profile/bank', {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <form class="mx-auto max-w-xl space-y-5" @submit.prevent="submit">
        <Panel title="Bank Details">
            <div class="space-y-4">
                <SelectField
                    v-model="form.bank_code"
                    label="Bank Name"
                    :options="options.banks"
                    :error="form.errors.bank_code"
                    :hint="
                        options.banks.length === 0
                            ? 'The list of banks could not be loaded. Try again later.'
                            : undefined
                    "
                >
                    <option :value="null">Select Bank</option>
                </SelectField>

                <TextField
                    v-model="form.account_number"
                    label="Account Number"
                    inputmode="numeric"
                    autocomplete="off"
                    :error="form.errors.account_number"
                />

                <AccountNameField
                    :name="accountName"
                    :state="lookup"
                    :error="lookupError"
                />

                <TextField
                    v-model="form.bvn"
                    label="BVN"
                    inputmode="numeric"
                    :error="form.errors.bvn"
                />

                <TextField
                    v-model="form.swift_code"
                    label="Swift Code"
                    :error="form.errors.swift_code"
                />

                <TextField
                    v-model="form.sort_code"
                    label="Sort Code"
                    :error="form.errors.sort_code"
                />

                <TextField
                    v-model="form.annual_rent"
                    label="Annual Rent"
                    type="number"
                    min="0"
                    step="0.01"
                    hint="Used for the rent relief on your tax return."
                    :error="form.errors.annual_rent"
                />
            </div>
        </Panel>

        <Panel title="PFA">
            <div class="space-y-4">
                <TextField
                    v-model="form.rsa_number"
                    label="RSA"
                    placeholder="Enter your RSA Number"
                    :error="form.errors.rsa_number"
                />

                <SelectField
                    v-model="form.pfa_name"
                    label="PFA"
                    :options="options.pension_administrators"
                    :error="form.errors.pfa_name"
                >
                    <option :value="null">
                        Select your pension administrator
                    </option>
                </SelectField>
            </div>
        </Panel>

        <Panel title="Others">
            <div class="space-y-4">
                <TextField
                    v-model="form.tax_identification_number"
                    label="Tax Identification Number"
                    :error="form.errors.tax_identification_number"
                />

                <TextField
                    v-model="form.nhf_number"
                    label="NHF Number"
                    :error="form.errors.nhf_number"
                />
            </div>
        </Panel>

        <div class="flex justify-end">
            <AppButton type="submit" :loading="form.processing">
                Save Changes
            </AppButton>
        </div>
    </form>
</template>
