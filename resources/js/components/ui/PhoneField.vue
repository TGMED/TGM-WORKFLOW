<script setup lang="ts">
import { computed } from 'vue';
import type { CountryOption } from '@/types';

const props = withDefaults(
    defineProps<{
        countries: CountryOption[];
        label?: string;
        error?: string;
        hint?: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        id?: string;
        // Dial code used when the field is empty. Falls back to the first
        // country in the list if this one is not in it.
        defaultCountry?: string;
    }>(),
    { required: false, disabled: false, defaultCountry: 'NG' },
);

/**
 * One full number in E.164, e.g. "+2349053003200". Stored whole so the rest
 * of the app can read a phone number without knowing about dial codes.
 */
const model = defineModel<string | null>();

const fallbackId = `phone-${Math.random().toString(36).slice(2, 9)}`;
const fieldId = computed(() => props.id ?? fallbackId);

// Several countries share a dial code, so the select is keyed by country and
// only its code goes into the value.
const fallbackDial = computed(
    () =>
        props.countries.find((c) => c.value === props.defaultCountry)?.dial ??
        props.countries[0]?.dial ??
        '+234',
);

// Longest match wins, so "+234" is not read as "+2".
const dial = computed(() => {
    const value = model.value ?? '';

    const match = props.countries
        .map((country) => country.dial)
        .filter((code) => value.startsWith(code))
        .sort((a, b) => b.length - a.length)[0];

    return match ?? fallbackDial.value;
});

const national = computed(() =>
    (model.value ?? '').startsWith(dial.value)
        ? (model.value ?? '').slice(dial.value.length)
        : (model.value ?? ''),
);

// The country whose code is currently selected. With shared codes this picks
// the first, which is only ever a cosmetic choice.
const selected = computed(
    () => props.countries.find((country) => country.dial === dial.value)?.value ?? '',
);

/**
 * An empty national part means no number at all, not a bare dial code — a
 * lone "+234" would otherwise pass a "required" check.
 */
function compose(code: string, rest: string) {
    // Trunk prefixes belong to national dialling; E.164 drops them.
    const digits = rest.replace(/\D/g, '').replace(/^0+/, '');

    model.value = digits === '' ? '' : `${code}${digits}`;
}

function onCountry(event: Event) {
    const code = props.countries.find(
        (country) => country.value === (event.target as HTMLSelectElement).value,
    )?.dial;

    if (code) compose(code, national.value);
}

function onNumber(event: Event) {
    compose(dial.value, (event.target as HTMLInputElement).value);
}
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

        <div class="flex gap-2">
            <div class="relative shrink-0">
                <select
                    :value="selected"
                    :disabled="disabled"
                    :aria-label="`${label ?? 'Phone'} country code`"
                    :class="[
                        'h-11 w-[104px] appearance-none rounded-xl border bg-panel-raised pl-3.5 text-sm text-text',
                        'transition-all duration-200 ease-out disabled:opacity-50',
                        'focus:outline-none focus:ring-4',
                        error
                            ? 'border-alert focus:border-alert focus:ring-alert/15'
                            : 'border-line focus:border-beacon focus:ring-beacon/15',
                    ]"
                    @change="onCountry"
                >
                    <option
                        v-for="country in countries"
                        :key="country.value"
                        :value="country.value"
                    >
                        {{ country.value }} {{ country.dial }}
                    </option>
                </select>

                <svg
                    class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-faint"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </div>

            <input
                :id="fieldId"
                :value="national"
                type="tel"
                inputmode="tel"
                autocomplete="tel-national"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :aria-invalid="!!error"
                :aria-describedby="error ? `${fieldId}-error` : undefined"
                :class="[
                    'h-11 min-w-0 flex-1 rounded-xl border bg-panel-raised px-3.5 text-sm text-text',
                    'transition-all duration-200 ease-out',
                    'placeholder:text-faint disabled:opacity-50',
                    'focus:outline-none focus:ring-4',
                    error
                        ? 'border-alert focus:border-alert focus:ring-alert/15'
                        : 'border-line focus:border-beacon focus:ring-beacon/15',
                ]"
                @input="onNumber"
            />
        </div>

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
