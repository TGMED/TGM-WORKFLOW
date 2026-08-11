<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import PhoneField from '@/components/ui/PhoneField.vue';
import SelectField from '@/components/ui/SelectField.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import type { EmployeeRelation, ProfileOptions, RelationKind } from '@/types';

const props = defineProps<{
    open: boolean;
    kind: RelationKind;
    // The record being changed, or null when adding a new one.
    relation: EmployeeRelation | null;
    options: ProfileOptions;
}>();

const emit = defineEmits<{ close: []; removed: [] }>();

const form = useForm({
    kind: props.kind,
    name: '',
    relationship: null as string | null,
    phone: '',
    email: '',
    date_of_birth: null as string | null,
    gender: null as string | null,
    occupation: '',
    address: '',
});

const heading = computed(
    () =>
        props.options.relation_kinds.find((k) => k.value === props.kind)
            ?.label ?? 'Relation',
);

// A next of kin is who gets rung in an emergency, so their number is the one
// detail the server insists on.
const phoneRequired = computed(() => props.kind === 'next_of_kin');

const today = new Date().toISOString().slice(0, 10);

// Reload whenever the modal opens, so a half-typed entry from last time never
// leaks into the next one.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.defaults({
            kind: props.kind,
            name: props.relation?.name ?? '',
            relationship: props.relation?.relationship ?? null,
            phone: props.relation?.phone ?? '',
            email: props.relation?.email ?? '',
            date_of_birth: props.relation?.date_of_birth ?? null,
            gender: props.relation?.gender ?? null,
            occupation: props.relation?.occupation ?? '',
            address: props.relation?.address ?? '',
        });
        form.reset();
    },
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (props.relation) {
        form.put(`/profile/relations/${props.relation.id}`, options);
    } else {
        form.post('/profile/relations', options);
    }
}
</script>

<template>
    <ModalShell
        :open="open"
        :title="
            relation
                ? `Edit ${heading.toLowerCase()}`
                : `Add ${heading.toLowerCase()}`
        "
        subtitle="Only the name, relationship and — for next of kin — a phone number are required."
        @close="emit('close')"
    >
        <form id="relation-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <TextField
                    v-model="form.name"
                    label="Full name"
                    required
                    :error="form.errors.name"
                />

                <SelectField
                    v-model="form.relationship"
                    label="Relationship"
                    required
                    :options="options.relationships"
                    :error="form.errors.relationship"
                >
                    <option :value="null">Select</option>
                </SelectField>

                <PhoneField
                    v-model="form.phone"
                    label="Phone number"
                    :required="phoneRequired"
                    :countries="options.countries"
                    :error="form.errors.phone"
                />

                <TextField
                    v-model="form.email"
                    label="Email"
                    type="email"
                    :error="form.errors.email"
                />

                <TextField
                    v-model="form.date_of_birth"
                    label="Date of birth"
                    type="date"
                    :max="today"
                    :error="form.errors.date_of_birth"
                />

                <SelectField
                    v-model="form.gender"
                    label="Gender"
                    :options="options.genders"
                    :error="form.errors.gender"
                >
                    <option :value="null">Select</option>
                </SelectField>
            </div>

            <TextField
                v-model="form.occupation"
                label="Occupation"
                :error="form.errors.occupation"
            />

            <TextareaField
                v-model="form.address"
                label="Address"
                :rows="2"
                :error="form.errors.address"
            />
        </form>

        <template #footer>
            <AppButton
                v-if="relation"
                variant="danger"
                class="mr-auto"
                @click="emit('removed')"
            >
                Remove
            </AppButton>
            <AppButton variant="ghost" @click="emit('close')">Cancel</AppButton>
            <AppButton
                type="submit"
                form="relation-form"
                :loading="form.processing"
            >
                {{ relation ? 'Save changes' : `Add ${heading.toLowerCase()}` }}
            </AppButton>
        </template>
    </ModalShell>
</template>
