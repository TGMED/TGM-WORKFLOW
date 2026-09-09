<script setup lang="ts">
import { computed } from 'vue';

/**
 * A set of choices where more than one may be held at once. Used for roles,
 * which stopped being mutually exclusive once somebody could lead a team as
 * well as hold a job.
 */
const props = withDefaults(
    defineProps<{
        label?: string;
        error?: string;
        hint?: string;
        required?: boolean;
        disabled?: boolean;
        options: Array<{
            value: string;
            label: string;
            description?: string | null;
        }>;
    }>(),
    { required: false, disabled: false },
);

const model = defineModel<string[]>({ default: () => [] });

const selected = computed(() => new Set(model.value ?? []));

function toggle(value: string): void {
    if (props.disabled) {
        return;
    }

    const next = new Set(model.value ?? []);

    next.has(value) ? next.delete(value) : next.add(value);

    // Kept in the order the options came in, so the set reads the same way
    // however it was clicked together.
    model.value = props.options
        .map((option) => option.value)
        .filter((value) => next.has(value));
}
</script>

<template>
    <div class="space-y-1.5">
        <span
            v-if="label"
            class="flex items-center gap-1 text-[13px] font-medium text-muted"
        >
            {{ label }}
            <span v-if="required" class="text-alert" aria-hidden="true">*</span>
        </span>

        <div
            :class="[
                'divide-y divide-line-soft overflow-hidden rounded-xl border bg-panel-raised',
                error ? 'border-alert' : 'border-line',
            ]"
        >
            <label
                v-for="option in options"
                :key="option.value"
                :class="[
                    'flex cursor-pointer items-start gap-3 px-3.5 py-2.5 transition-colors',
                    disabled
                        ? 'cursor-not-allowed opacity-50'
                        : 'hover:bg-panel',
                ]"
            >
                <input
                    type="checkbox"
                    class="mt-0.5 size-4 shrink-0 rounded border-line text-beacon focus:ring-2 focus:ring-beacon/30"
                    :checked="selected.has(option.value)"
                    :disabled="disabled"
                    @change="toggle(option.value)"
                />
                <span class="min-w-0">
                    <span class="block text-[13.5px] font-medium text-text">
                        {{ option.label }}
                    </span>
                    <span
                        v-if="option.description"
                        class="block text-[12.5px] text-faint"
                    >
                        {{ option.description }}
                    </span>
                </span>
            </label>
        </div>

        <p v-if="error" class="animate-fade-in text-[13px] text-alert">
            {{ error }}
        </p>
        <p v-else-if="hint" class="text-[13px] text-faint">{{ hint }}</p>
    </div>
</template>
