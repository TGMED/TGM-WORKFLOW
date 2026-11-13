<script setup lang="ts">
/*
 * A tiny string of Christmas lights wound around the logo. The wire spirals
 * along the wordmark: the strands running down from top to bottom pass in
 * front of the letters and the strands climbing back up pass behind them,
 * so the logo is drawn twice over, once with each layer, to wrap it.
 *
 * Purely decorative: it ignores the pointer, is hidden from screen readers,
 * and the bulbs hold still for anyone who has asked for reduced motion. The
 * box is 200 x 40 with the logo filling the middle band (y 10 to 30).
 */
const props = defineProps<{ layer: 'front' | 'back' }>();

const colours = ['#ef4444', '#facc15', '#22c55e', '#3b82f6', '#f97316'];

const START = 4;
const END = 198;
const TURNS = 4;
const HALF = (END - START) / (TURNS * 2);
const MID = 20;
const REACH = 13;

const at = (x: number): number =>
    MID - REACH * Math.cos((Math.PI * (x - START)) / HALF);

type Strand = { d: string; points: { x: number; y: number }[] };

// Each half-turn runs from one edge to the other. Even ones go top to
// bottom (in front), odd ones bottom to top (behind).
const strands: Strand[] = Array.from({ length: TURNS * 2 }, (_, i) => i)
    .filter((i) => (i % 2 === 0) === (props.layer === 'front'))
    .map((i) => {
        const x0 = START + i * HALF;
        const samples = Array.from({ length: 13 }, (_, k) => {
            const x = x0 + (HALF * k) / 12;

            return { x, y: at(x) };
        });

        return {
            d: samples
                .map(
                    (p, k) =>
                        `${k ? 'L' : 'M'}${p.x.toFixed(2)} ${p.y.toFixed(2)}`,
                )
                .join(' '),
            points: [0.25, 0.5, 0.75].map((t) => {
                const x = x0 + HALF * t;

                return { x, y: at(x) };
            }),
        };
    });

const bulbs = strands.flatMap((strand, s) =>
    strand.points.map((point, i) => {
        const n = s * 3 + i + (props.layer === 'front' ? 0 : 2);

        return {
            ...point,
            colour: colours[n % colours.length],
            delay: `${((n * 0.37) % 1.8).toFixed(2)}s`,
            duration: `${1.4 + (n % 3) * 0.45}s`,
        };
    }),
);
</script>

<template>
    <svg
        class="pointer-events-none overflow-visible"
        viewBox="0 0 200 40"
        aria-hidden="true"
        focusable="false"
    >
        <path
            v-for="(strand, index) in strands"
            :key="`wire-${index}`"
            :d="strand.d"
            fill="none"
            stroke="#2f5d43"
            stroke-width="0.8"
            stroke-linecap="round"
            :opacity="layer === 'back' ? 0.7 : 1"
        />
        <ellipse
            v-for="(bulb, index) in bulbs"
            :key="`bulb-${index}`"
            class="festive-bulb"
            :cx="bulb.x"
            :cy="bulb.y"
            rx="1.5"
            ry="2"
            :fill="bulb.colour"
            :style="{
                color: bulb.colour,
                animationDelay: bulb.delay,
                animationDuration: bulb.duration,
            }"
        />
    </svg>
</template>

<style scoped>
.festive-bulb {
    filter: drop-shadow(0 0 1.5px currentColor);
    animation-name: festive-twinkle;
    animation-iteration-count: infinite;
    animation-timing-function: ease-in-out;
}

@keyframes festive-twinkle {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.35;
    }
}

@media (prefers-reduced-motion: reduce) {
    .festive-bulb {
        animation: none;
    }
}
</style>
