<script setup lang="ts">
import { computed } from 'vue';

/**
 * Turnout over a run of days, on time stacked under late.
 *
 * Extracted so the company console, a head of department's own dashboard and
 * the admin console all draw the same chart: the same two colours mean the same
 * two things everywhere, which is the whole point of having a house style.
 */
type Day = {
    date: string;
    label: string;
    present: number;
    late: number;
    on_time: number;
};

const props = withDefaults(
    defineProps<{
        days: Day[];
        height?: number;
        /** Thirty bars will not carry thirty labels, so they thin out. */
        labelEvery?: number;
    }>(),
    { height: 150, labelEvery: 1 },
);

// At least one, so a run of empty days divides rather than blows up.
const peak = computed(() =>
    Math.max(1, ...props.days.map((day) => day.present)),
);
</script>

<template>
    <div>
        <div
            class="flex items-end gap-1.5"
            :style="{ height: `${height + 22}px` }"
        >
            <div
                v-for="(day, index) in days"
                :key="day.date"
                class="flex flex-1 flex-col items-center gap-1.5"
            >
                <div
                    class="flex w-full flex-col justify-end"
                    :style="{ height: `${height}px` }"
                >
                    <div
                        class="w-full rounded-t bg-brass/70"
                        :style="{ height: `${(day.late / peak) * 100}%` }"
                        :title="`${day.label}: ${day.late} late`"
                    />
                    <div
                        class="w-full bg-signal/70"
                        :class="day.late === 0 && 'rounded-t'"
                        :style="{ height: `${(day.on_time / peak) * 100}%` }"
                        :title="`${day.label}: ${day.on_time} on time`"
                    />
                </div>
                <span
                    class="text-[10.5px] whitespace-nowrap text-faint"
                    :class="index % labelEvery !== 0 && 'invisible'"
                >
                    {{ day.label }}
                </span>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-4 text-[12px] text-muted">
            <span class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-signal/70" />
                On time
            </span>
            <span class="flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-brass/70" />
                Late
            </span>
        </div>
    </div>
</template>
