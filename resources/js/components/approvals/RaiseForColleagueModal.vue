<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import SelectField from '@/components/ui/SelectField.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import type { RaiseOptions } from '@/types';

const props = defineProps<{ open: boolean; options: RaiseOptions }>();

const emit = defineEmits<{ close: [] }>();

const tab = ref<'leave' | 'lateness'>('leave');

const leave = useForm({
    staff_id: null as number | null,
    leave_type_id: null as number | null,
    supervisor_id: null as number | null,
    relief_officer_id: null as number | null,
    start_date: '',
    end_date: '',
    reason: '',
});

const lateness = useForm({
    staff_id: null as number | null,
    work_date: new Date().toISOString().slice(0, 10),
    reason: '',
});

// The department the request is being raised in, once somebody is chosen.
const staffDepartment = computed(
    () =>
        props.options.staff.find((option) => option.value === leave.staff_id)
            ?.department_id ?? null,
);

// Cover comes from the requester's own department: doing their job while they
// are away is only a real offer from somebody who does that kind of work.
// Whoever the request is for cannot cover their own desk, so they drop off as
// soon as they are picked. Somebody in no department is not narrowed, which
// matches how their own leave form behaves.
const relief = computed(() =>
    props.options.colleagues.filter(
        (option) =>
            option.value !== leave.staff_id &&
            (staffDepartment.value === null ||
                option.department_id === staffDepartment.value),
    ),
);

const approvers = computed(() =>
    props.options.approvers.filter((option) => option.value !== leave.staff_id),
);

// Picking someone who was already named further down the form would leave a
// contradiction on screen until the server rejected it.
watch(
    () => leave.staff_id,
    (staffId) => {
        // Cover already named may not be in the new person's department, so
        // it is cleared rather than left to be rejected on save.
        if (
            !relief.value.some(
                (option) => option.value === leave.relief_officer_id,
            )
        ) {
            leave.relief_officer_id = null;
        }

        if (leave.relief_officer_id === staffId) {
            leave.relief_officer_id = null;
        }

        if (leave.supervisor_id === staffId) {
            leave.supervisor_id = null;
        }
    },
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        tab.value = 'leave';
        leave.clearErrors();
        leave.reset();
        lateness.clearErrors();
        lateness.reset();
    },
);

function submitLeave() {
    leave.post('/approvals/on-behalf/leave', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function submitLateness() {
    lateness.post('/approvals/on-behalf/lateness', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <ModalShell
        :open="open"
        title="Raise for a colleague"
        subtitle="File a request for someone who cannot file it themselves. It still runs the usual chain."
        width="xl"
        @close="emit('close')"
    >
        <div class="space-y-5">
            <div class="flex items-center gap-1 rounded-xl bg-sunken p-1">
                <button
                    v-for="option in [
                        { key: 'leave', label: 'Leave' },
                        { key: 'lateness', label: 'Lateness' },
                    ]"
                    :key="option.key"
                    type="button"
                    :class="[
                        'flex-1 rounded-lg px-3 py-2 text-[13px] font-medium transition-all duration-200',
                        tab === option.key
                            ? 'bg-panel-raised text-text shadow-panel'
                            : 'text-muted hover:text-text',
                    ]"
                    @click="tab = option.key as 'leave' | 'lateness'"
                >
                    {{ option.label }}
                </button>
            </div>

            <form
                v-if="tab === 'leave'"
                id="raise-leave"
                class="space-y-4"
                @submit.prevent="submitLeave"
            >
                <SelectField
                    v-model="leave.staff_id"
                    label="Who is it for"
                    required
                    :options="options.staff"
                    :error="leave.errors.staff_id"
                >
                    <option :value="null" disabled>Pick a colleague</option>
                </SelectField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="leave.leave_type_id"
                        label="Leave type"
                        required
                        :options="options.leave_types"
                        :error="leave.errors.leave_type_id"
                    >
                        <option :value="null" disabled>Pick a type</option>
                    </SelectField>

                    <SelectField
                        v-model="leave.relief_officer_id"
                        label="Relief officer"
                        required
                        :options="relief"
                        :error="leave.errors.relief_officer_id"
                        hint="From their own department. They agree the cover before it reaches the reporting line."
                    >
                        <option :value="null" disabled>Pick a colleague</option>
                    </SelectField>
                </div>

                <SelectField
                    v-model="leave.supervisor_id"
                    label="Approver"
                    required
                    :options="approvers"
                    :error="leave.errors.supervisor_id"
                    hint="Not you: filing a request and approving it cannot be the same person."
                >
                    <option :value="null" disabled>Pick an approver</option>
                </SelectField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="leave.start_date"
                        label="First day"
                        type="date"
                        required
                        :error="leave.errors.start_date"
                    />
                    <TextField
                        v-model="leave.end_date"
                        label="Last day"
                        type="date"
                        required
                        :error="leave.errors.end_date"
                    />
                </div>

                <TextareaField
                    v-model="leave.reason"
                    label="Reason"
                    :rows="3"
                    placeholder="Why they are away, and anything the approver should know."
                    :error="leave.errors.reason"
                />
            </form>

            <form
                v-else
                id="raise-lateness"
                class="space-y-4"
                @submit.prevent="submitLateness"
            >
                <SelectField
                    v-model="lateness.staff_id"
                    label="Who is it for"
                    required
                    :options="options.staff"
                    :error="lateness.errors.staff_id"
                >
                    <option :value="null" disabled>Pick a colleague</option>
                </SelectField>

                <TextField
                    v-model="lateness.work_date"
                    label="Day"
                    type="date"
                    required
                    :error="lateness.errors.work_date"
                    hint="Anything in the last fortnight."
                />

                <TextareaField
                    v-model="lateness.reason"
                    label="Explanation"
                    :rows="3"
                    required
                    placeholder="What they told you about the morning."
                    :error="lateness.errors.reason"
                />
            </form>
        </div>

        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">Cancel</AppButton>
            <AppButton
                v-if="tab === 'leave'"
                type="submit"
                form="raise-leave"
                :loading="leave.processing"
            >
                Raise leave
            </AppButton>
            <AppButton
                v-else
                type="submit"
                form="raise-lateness"
                :loading="lateness.processing"
            >
                File explanation
            </AppButton>
        </template>
    </ModalShell>
</template>
