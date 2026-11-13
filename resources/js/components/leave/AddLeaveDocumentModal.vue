<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import LeaveDocumentList from '@/components/leave/LeaveDocumentList.vue';
import UploadProgress from '@/components/leave/UploadProgress.vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import type { LeaveDocument } from '@/types';

const props = defineProps<{
    open: boolean;
    leaveId: number | null;
    summary: string;
    documents: LeaveDocument[];
}>();

const emit = defineEmits<{ close: [] }>();

const input = ref<HTMLInputElement | null>(null);

// Shown for a moment once the files are on file, before the window closes.
const done = ref(false);
let closing: ReturnType<typeof setTimeout> | undefined;

const form = useForm({
    documents: [] as File[],
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            clearTimeout(closing);
            done.value = false;
            form.reset();
            form.clearErrors();

            if (input.value) {
                input.value.value = '';
            }
        }
    },
);

/*
 * Errors come back keyed per file ("documents.0") as well as for the list
 * as a whole, so they are gathered into one list under the field.
 */
const errors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'documents' || key.startsWith('documents.'))
        .map(([, message]) => message),
);

function pick(event: Event) {
    form.documents = Array.from((event.target as HTMLInputElement).files ?? []);
}

function submit() {
    if (props.leaveId === null) {
        return;
    }

    form.post(`/leave/${props.leaveId}/documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            done.value = true;
            closing = setTimeout(() => emit('close'), 1200);
        },
    });
}
</script>

<template>
    <ModalShell
        :open="open"
        title="Add a document"
        :subtitle="summary"
        width="md"
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="documents.length" class="space-y-1.5">
                <p class="text-[13px] font-medium text-muted">
                    Already on file
                </p>
                <LeaveDocumentList :documents="documents" />
            </div>

            <div class="space-y-1.5">
                <label
                    for="leave-extra-documents"
                    class="text-[13px] font-medium text-muted"
                >
                    New document
                </label>
                <input
                    id="leave-extra-documents"
                    ref="input"
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                    class="w-full cursor-pointer rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out file:mr-3 file:rounded-lg file:border-0 file:bg-line-soft file:px-3 file:py-1.5 file:text-[13px] file:text-text focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    :disabled="form.processing || done"
                    @change="pick"
                />
                <UploadProgress
                    :files="form.documents.map((file) => file.name)"
                    :percentage="form.progress?.percentage"
                    :processing="form.processing"
                    :done="done"
                />
                <p
                    v-for="(message, index) in errors"
                    :key="index"
                    class="text-[13px] text-alert"
                >
                    {{ message }}
                </p>
                <p v-if="!errors.length" class="text-[12px] text-faint">
                    A medical record, sick note or letter. PDF or image, under 8
                    MB each, up to 5 at a time. It is added to what is already
                    on file and does not restart the approvals.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" @click="emit('close')">
                    {{ done ? 'Close' : 'Cancel' }}
                </AppButton>
                <AppButton
                    type="submit"
                    :loading="form.processing"
                    :disabled="form.documents.length === 0 || done"
                >
                    Add
                    {{ form.documents.length > 1 ? 'documents' : 'document' }}
                </AppButton>
            </div>
        </form>
    </ModalShell>
</template>
