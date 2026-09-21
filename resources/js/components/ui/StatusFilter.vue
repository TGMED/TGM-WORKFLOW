<script setup lang="ts">
/*
 * A compact status picker for a panel's header, paired with useStatusFilter.
 * Put it in the slot only when filter.useful, so a panel with no title does
 * not grow an empty header while there is one status to choose.
 */
withDefaults(
    defineProps<{
        filter: { options: Array<{ value: string; label: string }> };
        label?: string;
    }>(),
    { label: 'Filter by status' },
);

const status = defineModel<string>({ required: true });
</script>

<template>
    <select
        v-model="status"
        :aria-label="label"
        class="h-9 rounded-lg border border-line bg-panel-raised px-2.5 text-[13px] text-text focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
    >
        <option
            v-for="option in filter.options"
            :key="option.value"
            :value="option.value"
        >
            {{ option.label }}
        </option>
    </select>
</template>
