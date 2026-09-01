<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { clockToMinutes, duration, timeOfDay } from '@/lib/format';

/**
 * The punch dial.
 *
 * A time clock is about a moment and a boundary, so the workday itself is the
 * instrument: the arc runs from an hour before opening to an hour after
 * closing, the grace window is a band on the rim, and your arrival is a notch
 * struck onto it. The ring fills as the day burns down.
 */
const props = defineProps<{
    timezone: string;
    workStart: string;
    workEnd: string;
    graceMinutes: number;
    clockedInAt: string | null;
    clockedOutAt: string | null;
    lateMinutes: number;
    status: string | null;
    busy: boolean;
    locating: boolean;
}>();

/* ---- Geometry: a 270° gauge opening at the bottom --------------------- */
const SIZE = 260;
const CENTER = SIZE / 2;
const RADIUS = 108;
const SWEEP_DEGREES = 270;
const START_DEGREES = 135; // 7:30 on a clock face
const ARC_UNITS = (SWEEP_DEGREES / 360) * 100; // pathLength is normalised to 100

/* ---- Office wall clock ------------------------------------------------ */
const now = ref(new Date());
let ticker: number | undefined;

onMounted(() => {
    ticker = window.setInterval(() => (now.value = new Date()), 1000);
});

onBeforeUnmount(() => window.clearInterval(ticker));

/** Minutes since midnight at the office, for any instant. */
function officeMinutes(date: Date): number {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: props.timezone,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).formatToParts(date);

    const read = (type: string) =>
        Number(parts.find((part) => part.type === type)?.value ?? 0);

    return read('hour') * 60 + read('minute') + read('second') / 60;
}

const officeTime = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: props.timezone,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(now.value),
);

const officeSeconds = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: props.timezone,
        second: '2-digit',
    }).format(now.value),
);

const officeDay = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: props.timezone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(now.value),
);

/* ---- Mapping time onto the arc ---------------------------------------- */
const startMinutes = computed(() => clockToMinutes(props.workStart));
const endMinutes = computed(() => clockToMinutes(props.workEnd));
const windowStart = computed(() => startMinutes.value - 60);
const windowEnd = computed(() => endMinutes.value + 60);

/** 0–1 position of a minutes-since-midnight value along the gauge. */
function positionOf(minutes: number): number {
    const span = windowEnd.value - windowStart.value;

    return Math.min(1, Math.max(0, (minutes - windowStart.value) / span));
}

function unitsOf(minutes: number): number {
    return positionOf(minutes) * ARC_UNITS;
}

/** Cartesian point on the rim, used for the arrival and departure notches. */
function pointAt(minutes: number, radius = RADIUS) {
    const degrees = START_DEGREES + positionOf(minutes) * SWEEP_DEGREES;
    const radians = (degrees * Math.PI) / 180;

    return {
        x: CENTER + radius * Math.cos(radians),
        y: CENTER + radius * Math.sin(radians),
    };
}

const nowMinutes = computed(() => officeMinutes(now.value));

const clockedInMinutes = computed(() =>
    props.clockedInAt ? officeMinutes(new Date(props.clockedInAt)) : null,
);

const clockedOutMinutes = computed(() =>
    props.clockedOutAt ? officeMinutes(new Date(props.clockedOutAt)) : null,
);

/* Elapsed workday: from opening time to now, clamped to the closing hour. */
const elapsedArc = computed(() => {
    const from = unitsOf(startMinutes.value);
    const to = unitsOf(Math.min(nowMinutes.value, windowEnd.value));

    return { offset: -from, length: Math.max(0, to - from) };
});

const graceArc = computed(() => {
    const from = unitsOf(startMinutes.value);
    const to = unitsOf(startMinutes.value + props.graceMinutes);

    return { offset: -from, length: Math.max(0, to - from) };
});

const startTick = computed(() => pointAt(startMinutes.value, RADIUS + 14));
const startTickInner = computed(() => pointAt(startMinutes.value, RADIUS - 14));
const arrivalNotch = computed(() =>
    clockedInMinutes.value === null ? null : pointAt(clockedInMinutes.value),
);
const departureNotch = computed(() =>
    clockedOutMinutes.value === null ? null : pointAt(clockedOutMinutes.value),
);

/* ---- State ------------------------------------------------------------ */
const phase = computed<'out' | 'in' | 'done'>(() => {
    if (props.clockedOutAt) {
        return 'done';
    }

    if (props.clockedInAt) {
        return 'in';
    }

    return 'out';
});

const onTheClockMinutes = computed(() => {
    if (clockedInMinutes.value === null) {
        return 0;
    }

    const end = clockedOutMinutes.value ?? nowMinutes.value;

    return Math.max(0, Math.round(end - clockedInMinutes.value));
});

const isLate = computed(() => props.status === 'late');
const inGrace = computed(() => props.status === 'grace');

/** Green on time, amber inside the grace window, red once past it. */
const statusColor = computed(() => {
    if (isLate.value) {
        return 'var(--alert)';
    }

    return inGrace.value ? 'var(--brass)' : 'var(--signal)';
});

const accent = computed(() =>
    phase.value === 'out' ? 'var(--text-faint)' : statusColor.value,
);

const headline = computed(() => {
    if (phase.value === 'done') {
        return 'Day closed';
    }

    if (phase.value === 'in') {
        return 'On the clock';
    }

    return 'Not clocked in';
});

const detail = computed(() => {
    if (phase.value === 'out') {
        return `Opens at ${props.workStart} · ${props.graceMinutes} min grace`;
    }

    if (phase.value === 'done') {
        return `${timeOfDay(props.clockedInAt)} → ${timeOfDay(props.clockedOutAt)} · ${duration(onTheClockMinutes.value)}`;
    }

    return `Since ${timeOfDay(props.clockedInAt)} · ${duration(onTheClockMinutes.value)} elapsed`;
});
</script>

<template>
    <div class="relative grid place-items-center">
        <!-- GPS acquisition rings, shown only while a fix is being taken. -->
        <template v-if="locating">
            <span
                v-for="delay in [0, 600, 1200]"
                :key="delay"
                class="animate-lock-ping pointer-events-none absolute size-[260px] rounded-full border-2 border-beacon"
                :style="{ animationDelay: `${delay}ms` }"
                aria-hidden="true"
            />
        </template>

        <svg
            :viewBox="`0 0 ${SIZE} ${SIZE}`"
            class="size-[260px] max-w-full -rotate-0"
            role="img"
            :aria-label="`${headline}. ${detail}`"
        >
            <g :transform="`rotate(${START_DEGREES} ${CENTER} ${CENTER})`">
                <!-- Rim track: the whole day, unlit. -->
                <circle
                    :cx="CENTER"
                    :cy="CENTER"
                    :r="RADIUS"
                    fill="none"
                    stroke="var(--ring-track)"
                    stroke-width="14"
                    stroke-linecap="round"
                    pathLength="100"
                    :stroke-dasharray="`${ARC_UNITS} ${100 - ARC_UNITS}`"
                />

                <!-- The grace window sits just after opening time. -->
                <circle
                    :cx="CENTER"
                    :cy="CENTER"
                    :r="RADIUS"
                    fill="none"
                    stroke="var(--brass)"
                    stroke-opacity="0.5"
                    stroke-width="14"
                    pathLength="100"
                    :stroke-dasharray="`${graceArc.length} 100`"
                    :stroke-dashoffset="graceArc.offset"
                />

                <!-- Elapsed workday. -->
                <circle
                    :cx="CENTER"
                    :cy="CENTER"
                    :r="RADIUS"
                    fill="none"
                    :stroke="accent"
                    stroke-width="14"
                    stroke-linecap="round"
                    pathLength="100"
                    :stroke-dasharray="`${elapsedArc.length} 100`"
                    :stroke-dashoffset="elapsedArc.offset"
                    class="transition-all duration-700 ease-out"
                    style="
                        filter: drop-shadow(
                            0 0 6px
                                color-mix(
                                    in srgb,
                                    currentColor 40%,
                                    transparent
                                )
                        );
                    "
                />
            </g>

            <!-- Opening-time gradation. -->
            <line
                :x1="startTickInner.x"
                :y1="startTickInner.y"
                :x2="startTick.x"
                :y2="startTick.y"
                stroke="var(--text-faint)"
                stroke-width="2"
                stroke-linecap="round"
            />

            <!-- Arrival: struck onto the rim where you punched in. -->
            <g v-if="arrivalNotch">
                <circle
                    :cx="arrivalNotch.x"
                    :cy="arrivalNotch.y"
                    r="11"
                    fill="var(--panel)"
                />
                <circle
                    :cx="arrivalNotch.x"
                    :cy="arrivalNotch.y"
                    r="6.5"
                    :fill="statusColor"
                    class="animate-pop-in"
                />
            </g>

            <g v-if="departureNotch">
                <circle
                    :cx="departureNotch.x"
                    :cy="departureNotch.y"
                    r="11"
                    fill="var(--panel)"
                />
                <circle
                    :cx="departureNotch.x"
                    :cy="departureNotch.y"
                    r="6.5"
                    fill="none"
                    stroke="var(--text-muted)"
                    stroke-width="2.5"
                />
            </g>
        </svg>

        <!-- Dial face -->
        <div
            class="pointer-events-none absolute inset-0 grid place-content-center text-center"
        >
            <p class="eyebrow">{{ headline }}</p>

            <p
                class="tabular mt-1 font-mono text-[40px] leading-none font-semibold tracking-tight"
            >
                {{ officeTime
                }}<span class="text-[20px] text-faint"
                    >:{{ officeSeconds }}</span
                >
            </p>

            <p class="mt-2 max-w-[168px] text-[12px] leading-snug text-muted">
                {{ detail }}
            </p>

            <p
                v-if="isLate && lateMinutes > 0"
                class="mt-1.5 font-mono text-[11px] font-semibold tracking-wide text-alert uppercase"
            >
                {{ duration(lateMinutes) }} late
            </p>

            <p
                v-else-if="inGrace"
                class="mt-1.5 font-mono text-[11px] font-semibold tracking-wide text-brass uppercase"
            >
                Within grace · not late
            </p>
        </div>

        <p class="sr-only">{{ officeDay }}</p>
    </div>
</template>
