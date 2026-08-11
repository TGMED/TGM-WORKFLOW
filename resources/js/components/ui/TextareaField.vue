<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        label?: string;
        error?: string;
        hint?: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        rows?: number;
        id?: string;
    }>(),
    { required: false, disabled: false, rows: 3 },
);

const model = defineModel<string | null>();

const fallbackId = `textarea-${Math.random().toString(36).slice(2, 9)}`;
const fieldId = computed(() => props.id ?? fallbackId);
</script>

<template>
    <div class="space-y-1.5">
        <label
            v-if="label"
            :for="fieldId"
            class="flex items-center gap-1 text-[13px] font-medium text-muted"
        >
            {{ label }}
            <span v-if="required" class="text-alert" aria-hidden="true">*</span>
        </label>

        <textarea
            :id="fieldId"
            v-model="model"
            :rows="rows"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
            :aria-invalid="!!error"
            :aria-describedby="error ? `${fieldId}-error` : undefined"
            :class="[
                'w-full resize-y rounded-xl border bg-panel-raised px-3.5 py-2.5 text-sm text-text',
                'transition-all duration-200 ease-out',
                'placeholder:text-faint disabled:opacity-50',
                'focus:outline-none focus:ring-4',
                error
                    ? 'border-alert focus:border-alert focus:ring-alert/15'
                    : 'border-line focus:border-beacon focus:ring-beacon/15',
            ]"
        />

        <p
            v-if="error"
            :id="`${fieldId}-error`"
            class="animate-fade-in text-[13px] text-alert"
        >
            {{ error }}
        </p>
        <p v-else-if="hint" class="text-[13px] text-faint">{{ hint }}</p>
    </div>
</template>
