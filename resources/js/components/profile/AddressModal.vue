<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import SelectField from '@/components/ui/SelectField.vue';
import TextField from '@/components/ui/TextField.vue';
import type { EmployeeAddress, ProfileOptions } from '@/types';

const props = defineProps<{
    open: boolean;
    address: EmployeeAddress | null;
    options: ProfileOptions;
}>();

const emit = defineEmits<{ close: []; removed: [] }>();

const form = useForm({
    label: 'Residential' as string,
    street: '',
    city: '',
    state: '',
    country: null as string | null,
    postal_code: '',
});

const states = computed(() =>
    form.country ? (props.options.states[form.country] ?? null) : null,
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults({
            label: props.address?.label ?? 'Residential',
            street: props.address?.street ?? '',
            city: props.address?.city ?? '',
            state: props.address?.state ?? '',
            country: props.address?.country ?? null,
            postal_code: props.address?.postal_code ?? '',
        });
        form.reset();
    },
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (props.address) {
        form.put(`/profile/addresses/${props.address.id}`, options);
    } else {
        form.post('/profile/addresses', options);
    }
}
</script>

<template>
    <ModalShell
        :open="open"
        :title="address ? 'Edit address' : 'Add address'"
        @close="emit('close')"
    >
        <form id="address-form" class="space-y-4" @submit.prevent="submit">
            <SelectField
                v-model="form.label"
                label="Address type"
                required
                :options="options.address_types"
                :error="form.errors.label"
            />

            <TextField
                v-model="form.street"
                label="Street address"
                required
                placeholder="e.g. 12 Ademola Street"
                :error="form.errors.street"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <TextField
                    v-model="form.city"
                    label="City"
                    :error="form.errors.city"
                />

                <TextField
                    v-model="form.postal_code"
                    label="Postal code"
                    :error="form.errors.postal_code"
                />

                <SelectField
                    v-model="form.country"
                    label="Country"
                    :options="options.countries"
                    :error="form.errors.country"
                    @update:model-value="form.state = ''"
                >
                    <option :value="null">Select a country</option>
                </SelectField>

                <SelectField
                    v-if="states"
                    v-model="form.state"
                    label="State"
                    :options="states"
                    :error="form.errors.state"
                >
                    <option value="">Select a state</option>
                </SelectField>
                <TextField
                    v-else
                    v-model="form.state"
                    label="State"
                    :error="form.errors.state"
                />
            </div>
        </form>

        <template #footer>
            <AppButton
                v-if="address"
                variant="danger"
                class="mr-auto"
                @click="emit('removed')"
            >
                Remove
            </AppButton>
            <AppButton variant="ghost" @click="emit('close')">Cancel</AppButton>
            <AppButton
                type="submit"
                form="address-form"
                :loading="form.processing"
            >
                {{ address ? 'Save changes' : 'Add address' }}
            </AppButton>
        </template>
    </ModalShell>
</template>
