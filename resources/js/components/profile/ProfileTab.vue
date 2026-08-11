<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PhotoPicker from '@/components/profile/PhotoPicker.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Panel from '@/components/ui/Panel.vue';
import PhoneField from '@/components/ui/PhoneField.vue';
import SelectField from '@/components/ui/SelectField.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import type { EmployeeAddress, EmployeeProfile, ProfileOptions } from '@/types';

const props = defineProps<{
    profile: EmployeeProfile;
    addresses: EmployeeAddress[];
    options: ProfileOptions;
    missingFields: string[];
}>();

const emit = defineEmits<{ 'edit-address': [EmployeeAddress | null] }>();

const form = useForm({
    employee_id: props.profile.employee_id,
    attendance_id: props.profile.attendance_id,
    hired_at: props.profile.hired_at,
    first_name: props.profile.first_name,
    last_name: props.profile.last_name,
    other_names: props.profile.other_names,
    title: props.profile.title,
    gender: props.profile.gender,
    date_of_birth: props.profile.date_of_birth,
    place_of_birth: props.profile.place_of_birth,
    marital_status: props.profile.marital_status,
    mothers_maiden_name: props.profile.mothers_maiden_name,
    spouse_name: props.profile.spouse_name,
    spouse_phone: props.profile.spouse_phone,
    number_of_kids: props.profile.number_of_kids,
    blood_group: props.profile.blood_group,
    genotype: props.profile.genotype,
    religion: props.profile.religion,
    allergies: props.profile.allergies,
    medical_history: props.profile.medical_history,
    national_id_number: props.profile.national_id_number,
    country_of_origin: props.profile.country_of_origin,
    state_of_origin: props.profile.state_of_origin,
    local_government: props.profile.local_government,
    phone: props.profile.phone,
    alternate_phone: props.profile.alternate_phone,
    alternate_email: props.profile.alternate_email,
});

// Nobody is born tomorrow, and the paperwork assumes 16 at the youngest.
const oldestBirthday = new Date(Date.now() - 16 * 365.25 * 864e5)
    .toISOString()
    .slice(0, 10);

// A start date up to a month out covers someone serving a notice period.
const latestJoinDate = new Date(Date.now() + 30 * 864e5)
    .toISOString()
    .slice(0, 10);

// Only some countries carry a state list. The rest get a free-text box rather
// than an empty dropdown.
const states = computed(() =>
    form.country_of_origin
        ? (props.options.states[form.country_of_origin] ?? null)
        : null,
);

function missing(field: string): boolean {
    return props.missingFields.includes(field);
}

function onCountry() {
    // A state from the old country is meaningless under the new one.
    form.state_of_origin = null;
    form.local_government = null;
}

function submit() {
    form.put('/profile', { preserveScroll: true });
}
</script>

<template>
    <form
        class="grid items-start gap-5 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]"
        @submit.prevent="submit"
    >
        <!-- Left column: who they are -->
        <Panel title="Profile">
            <div class="space-y-5">
                <PhotoPicker
                    :avatar-url="profile.avatar_url"
                    :initials="profile.initials"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.employee_id"
                        label="Staff ID"
                        placeholder="e.g. TGM/VU/251013"
                        hint="Leave this blank if you have not been issued one yet."
                        :error="form.errors.employee_id"
                    />

                    <TextField
                        v-model="form.hired_at"
                        label="Date You Joined"
                        type="date"
                        required
                        :max="latestJoinDate"
                        :error="form.errors.hired_at"
                        :hint="
                            missing('hired_at')
                                ? 'Still needed'
                                : 'Your first day with us. Ask HR if you are unsure.'
                        "
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.attendance_id"
                        label="Attendance ID"
                        :error="form.errors.attendance_id"
                    />
                    <TextField
                        v-model="form.last_name"
                        label="Last Name"
                        required
                        :error="form.errors.last_name"
                        :hint="
                            missing('last_name') ? 'Still needed' : undefined
                        "
                    />

                    <TextField
                        v-model="form.first_name"
                        label="First Name"
                        required
                        :error="form.errors.first_name"
                        :hint="
                            missing('first_name') ? 'Still needed' : undefined
                        "
                    />
                    <TextField
                        v-model="form.other_names"
                        label="Other Names"
                        :error="form.errors.other_names"
                    />

                    <SelectField
                        v-model="form.gender"
                        label="Gender"
                        required
                        :options="options.genders"
                        :error="form.errors.gender"
                    >
                        <option :value="null">Select</option>
                    </SelectField>
                    <TextField
                        v-model="form.date_of_birth"
                        label="Date of Birth"
                        type="date"
                        required
                        :max="oldestBirthday"
                        :error="form.errors.date_of_birth"
                    />

                    <TextField
                        v-model="form.place_of_birth"
                        label="Place of Birth"
                        :error="form.errors.place_of_birth"
                    />
                    <SelectField
                        v-model="form.marital_status"
                        label="Marital Status"
                        :options="options.marital_statuses"
                        :error="form.errors.marital_status"
                    >
                        <option :value="null">Select</option>
                    </SelectField>

                    <SelectField
                        v-model="form.title"
                        label="Title"
                        :options="options.titles"
                        :error="form.errors.title"
                    >
                        <option :value="null">Select</option>
                    </SelectField>
                    <TextField
                        v-model="form.mothers_maiden_name"
                        label="Mother's Maiden Name"
                        :error="form.errors.mothers_maiden_name"
                    />

                    <TextField
                        v-model="form.spouse_name"
                        label="Spouse's Name"
                        :error="form.errors.spouse_name"
                    />
                    <PhoneField
                        v-model="form.spouse_phone"
                        label="Spouse Phone Number"
                        :countries="options.countries"
                        placeholder="09053003200"
                        :error="form.errors.spouse_phone"
                    />

                    <TextField
                        v-model="form.number_of_kids"
                        label="Number of Kids"
                        type="number"
                        min="0"
                        placeholder="e.g. 2"
                        :error="form.errors.number_of_kids"
                    />
                    <SelectField
                        v-model="form.blood_group"
                        label="Blood Group"
                        :options="options.blood_groups"
                        :error="form.errors.blood_group"
                    >
                        <option :value="null">Select</option>
                    </SelectField>

                    <SelectField
                        v-model="form.religion"
                        label="Religion"
                        :options="options.religions"
                        :error="form.errors.religion"
                    >
                        <option :value="null">Select</option>
                    </SelectField>
                    <SelectField
                        v-model="form.genotype"
                        label="Genotype"
                        :options="options.genotypes"
                        :error="form.errors.genotype"
                    >
                        <option :value="null">Select</option>
                    </SelectField>
                </div>

                <TextareaField
                    v-model="form.allergies"
                    label="Allergies"
                    :rows="2"
                    hint="Anything a first responder should know about."
                    :error="form.errors.allergies"
                />

                <TextareaField
                    v-model="form.medical_history"
                    label="Medical History"
                    :rows="2"
                    :error="form.errors.medical_history"
                />

                <TextField
                    v-model="form.national_id_number"
                    label="National Identification Number (NIN)"
                    :error="form.errors.national_id_number"
                />
            </div>
        </Panel>

        <!-- Right column: where they are from and how to reach them -->
        <div class="space-y-5">
            <Panel title="Origin">
                <div class="space-y-4">
                    <SelectField
                        v-model="form.country_of_origin"
                        label="Country Of Origin"
                        required
                        :options="options.countries"
                        :error="form.errors.country_of_origin"
                        @update:model-value="onCountry"
                    >
                        <option :value="null">Select a country</option>
                    </SelectField>

                    <SelectField
                        v-if="states"
                        v-model="form.state_of_origin"
                        label="State"
                        required
                        :options="states"
                        :error="form.errors.state_of_origin"
                    >
                        <option :value="null">Select a state</option>
                    </SelectField>
                    <TextField
                        v-else
                        v-model="form.state_of_origin"
                        label="State"
                        required
                        :disabled="!form.country_of_origin"
                        :hint="
                            form.country_of_origin
                                ? undefined
                                : 'Pick a country first.'
                        "
                        :error="form.errors.state_of_origin"
                    />

                    <TextField
                        v-model="form.local_government"
                        label="Local Government"
                        :error="form.errors.local_government"
                    />
                </div>
            </Panel>

            <Panel title="Phone and Email">
                <div class="space-y-4">
                    <PhoneField
                        v-model="form.phone"
                        label="Phone Number"
                        required
                        :countries="options.countries"
                        placeholder="09053003200"
                        :error="form.errors.phone"
                    />

                    <PhoneField
                        v-model="form.alternate_phone"
                        label="Alternate Phone Number"
                        :countries="options.countries"
                        placeholder="e.g. 08087656789"
                        :error="form.errors.alternate_phone"
                    />

                    <TextField
                        :model-value="profile.email"
                        label="Email"
                        type="email"
                        disabled
                        hint="This is the address you sign in with. Contact HR to change it."
                    />

                    <TextField
                        v-model="form.alternate_email"
                        label="Alternate Email"
                        type="email"
                        :error="form.errors.alternate_email"
                    />
                </div>
            </Panel>

            <Panel
                title="Address"
                subtitle="We need at least one on file. Saved on its own, not with the form below."
            >
                <template #action>
                    <AppButton size="sm" @click="emit('edit-address', null)">
                        + Add address
                    </AppButton>
                </template>

                <p
                    v-if="!addresses.length"
                    :class="[
                        'text-[13px]',
                        missing('address') ? 'text-brass' : 'text-muted',
                    ]"
                >
                    {{
                        missing('address')
                            ? 'Still needed. Add the address you live at to carry on.'
                            : 'No address on file yet.'
                    }}
                </p>

                <ul v-else class="space-y-2">
                    <li
                        v-for="address in addresses"
                        :key="address.id"
                        class="rounded-xl border border-line-soft bg-sunken/40 px-4 py-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold">
                                    {{ address.label }}
                                </p>
                                <p
                                    class="mt-0.5 text-[13px] leading-relaxed text-muted"
                                >
                                    {{ address.one_line }}
                                </p>
                            </div>

                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="emit('edit-address', address)"
                            >
                                Edit
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </Panel>
        </div>

        <div class="flex justify-end lg:col-span-2">
            <AppButton type="submit" :loading="form.processing">
                Save Changes
            </AppButton>
        </div>
    </form>
</template>
