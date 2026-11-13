<script setup lang="ts">
import { computed } from 'vue';

/*
 * How far a document upload has got: a bar while the file goes up, a short
 * "saving" once the bytes are all sent and the server is storing it, then
 * "Done" once it is on file. Hidden until something is being sent.
 */
const props = defineProps<{
    /** The file names being sent. */
    files: string[];
    /** Upload percentage from the form, or null before it starts. */
    percentage: number | null | undefined;
    processing: boolean;
    done: boolean;
}>();

const percent = computed(() =>
    props.done ? 100 : Math.min(100, Math.round(props.percentage ?? 0)),
);

const state = computed<'idle' | 'uploading' | 'saving' | 'done'>(() => {
    if (props.done) {
        return 'done';
    }

    if (!props.processing) {
        return 'idle';
    }

    return percent.value >= 100 ? 'saving' : 'uploading';
});

const label = computed(() =>
    props.files.length === 1
        ? props.files[0]
        : `${props.files.length} documents`,
);
</script>

<template>
    <div
        v-if="state !== 'idle' && files.length"
        class="space-y-1.5 rounded-xl border border-line-soft bg-sunken/50 px-3.5 py-2.5"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-center justify-between gap-3 text-[12.5px]">
            <span class="min-w-0 truncate text-muted">{{ label }}</span>
            <span
                :class="[
                    'shrink-0 font-medium tabular-nums',
                    state === 'done' ? 'text-signal' : 'text-text',
                ]"
            >
                <template v-if="state === 'uploading'">
                    Uploading {{ percent }}%
                </template>
                <template v-else-if="state === 'saving'">Saving…</template>
                <template v-else>
                    <svg
                        class="-mt-0.5 mr-0.5 inline size-3.5"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    Done
                </template>
            </span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-line-soft">
            <div
                :class="[
                    'h-full rounded-full transition-all duration-300 ease-out',
                    state === 'done' ? 'bg-signal' : 'bg-brand',
                    state === 'saving' && 'animate-pulse',
                ]"
                :style="{ width: `${percent}%` }"
            />
        </div>
    </div>
</template>
