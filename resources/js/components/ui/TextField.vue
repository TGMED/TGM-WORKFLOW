<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        label?: string;
        type?: string;
        error?: string;
        hint?: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        autocomplete?: string;
        inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'url';
        id?: string;
        min?: string | number;
        max?: string | number;
        step?: string | number;
        // 'lg' is used on the sign-in and signup pages, where the form is the
        // whole screen and can afford larger, easier-to-read type.
        size?: 'md' | 'lg';
    }>(),
    { type: 'text', required: false, disabled: false, size: 'md' },
);

const model = defineModel<string | number | null>();

const fallbackId = `field-${Math.random().toString(36).slice(2, 9)}`;
const fieldId = computed(() => props.id ?? fallbackId);

const large = computed(() => props.size === 'lg');

const revealed = ref(false);
const isPassword = computed(() => props.type === 'password');
const resolvedType = computed(() =>
    isPassword.value && revealed.value ? 'text' : props.type,
);
</script>

<template>
    <div class="space-y-1.5">
        <label
            v-if="label"
            :for="fieldId"
            :class="[
                'flex items-center gap-1 font-medium text-muted',
                large ? 'text-sm' : 'text-[13px]',
            ]"
        >
            {{ label }}
            <span v-if="required" class="text-alert" aria-hidden="true">*</span>
        </label>

        <div class="relative">
            <input
                :id="fieldId"
                v-model="model"
                :type="resolvedType"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :autocomplete="autocomplete"
                :inputmode="inputmode"
                :min="min"
                :max="max"
                :step="step"
                :aria-invalid="!!error"
                :aria-describedby="error ? `${fieldId}-error` : undefined"
                :class="[
                    'w-full rounded-xl border bg-panel-raised text-text',
                    large ? 'h-12 px-4 text-[15px]' : 'h-11 px-3.5 text-sm',
                    'transition-all duration-200 ease-out',
                    'placeholder:text-faint disabled:opacity-50',
                    'focus:outline-none focus:ring-4',
                    isPassword && 'pr-11',
                    error
                        ? 'border-alert focus:border-alert focus:ring-alert/15'
                        : 'border-line focus:border-beacon focus:ring-beacon/15',
                ]"
            />

            <button
                v-if="isPassword"
                type="button"
                :aria-label="revealed ? 'Hide password' : 'Show password'"
                class="absolute top-1/2 right-1 grid size-9 -translate-y-1/2 place-items-center rounded-lg text-faint transition-colors hover:text-text"
                @click="revealed = !revealed"
            >
                <svg
                    class="size-4"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                >
                    <path
                        v-if="!revealed"
                        d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"
                    />
                    <circle v-if="!revealed" cx="12" cy="12" r="3.2" />
                    <path
                        v-else
                        d="M3 3l18 18M10.6 6.1A8.7 8.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-3.3 4M6.6 8.2A17 17 0 0 0 2.5 12S6 18 12 18a9 9 0 0 0 3.3-.6"
                    />
                </svg>
            </button>
        </div>

        <p
            v-if="error"
            :id="`${fieldId}-error`"
            :class="[
                'animate-fade-in text-alert',
                large ? 'text-sm' : 'text-[13px]',
            ]"
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint"
            :class="['text-faint', large ? 'text-sm' : 'text-[13px]']"
        >
            {{ hint }}
        </p>
    </div>
</template>
