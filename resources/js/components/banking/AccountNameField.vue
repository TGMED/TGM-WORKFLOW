<script setup lang="ts">
import type { LookupState } from '@/composables/useAccountLookup';

defineProps<{
    name: string | null;
    state: LookupState;
    error: string | null;
    label?: string;
}>();
</script>

<template>
    <div class="space-y-1.5">
        <p class="text-[13px] font-medium text-muted">
            {{ label ?? 'Account Name' }}
        </p>
        <p
            class="flex min-h-[42px] items-center rounded-xl border border-line bg-sunken/50 px-3.5 py-2.5 text-sm"
            :class="name ? 'font-medium text-text' : 'text-faint'"
            aria-live="polite"
        >
            <template v-if="state === 'busy'">
                Checking with the bank...
            </template>
            <template v-else-if="name">{{ name }}</template>
            <template v-else>
                Filled in from the bank once you pick it and enter all ten
                digits.
            </template>
        </p>
        <p v-if="state === 'failed' && error" class="text-[13px] text-alert">
            {{ error }}
        </p>
    </div>
</template>
