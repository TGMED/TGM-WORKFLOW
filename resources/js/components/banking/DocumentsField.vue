<script setup lang="ts">
import { ref } from 'vue';

defineProps<{
    label: string;
    hint?: string;
    errors?: Record<string, string>;
}>();

const model = defineModel<File[]>({ default: () => [] });
const input = ref<HTMLInputElement | null>(null);

function pick(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);

    model.value = [...model.value, ...files].slice(0, 10);

    if (input.value) {
        input.value.value = '';
    }
}

function remove(index: number) {
    model.value = model.value.filter((_, i) => i !== index);
}
</script>

<template>
    <div class="space-y-1.5">
        <p class="text-[13px] font-medium text-muted">
            {{ label }}
            <span class="font-normal text-faint">· optional</span>
        </p>
        <input
            ref="input"
            type="file"
            multiple
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
            class="block w-full cursor-pointer rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-line-soft file:px-3 file:py-1.5 file:text-[12.5px] file:font-medium file:text-text"
            @change="pick"
        />
        <p class="text-[12px] text-faint">
            {{
                hint ??
                'PDFs, images, Word or Excel files. Up to 10, each up to 10 MB.'
            }}
        </p>
        <ul v-if="model.length" class="space-y-1 text-[12.5px] text-muted">
            <li
                v-for="(file, index) in model"
                :key="`${file.name}-${index}`"
                class="flex items-center justify-between gap-2"
            >
                <span class="truncate">{{ file.name }}</span>
                <button
                    type="button"
                    class="text-faint hover:text-alert"
                    @click="remove(index)"
                >
                    Remove
                </button>
            </li>
        </ul>
        <template v-for="(message, key) in errors ?? {}" :key="key">
            <p
                v-if="String(key).startsWith('documents')"
                class="text-[13px] text-alert"
            >
                {{ message }}
            </p>
        </template>
    </div>
</template>
