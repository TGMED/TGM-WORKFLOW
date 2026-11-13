<script setup lang="ts">
/*
 * Snow drifting down the screen. Purely decorative: it ignores the pointer,
 * is hidden from screen readers, and is left out entirely for anyone who has
 * asked for reduced motion. Flakes are spread with a fixed pattern rather
 * than at random, so the page looks the same on every load.
 */
const flakes = Array.from({ length: 60 }, (_, i) => {
    const size = 2 + ((i * 7) % 5);

    return {
        left: `${(i * 37.3) % 100}%`,
        size: `${size}px`,
        opacity: 0.45 + ((i * 13) % 50) / 100,
        duration: `${9 + ((i * 11) % 10)}s`,
        delay: `-${((i * 1.7) % 18).toFixed(1)}s`,
        drift: `${((i % 2 === 0 ? 1 : -1) * (10 + ((i * 5) % 30))).toFixed(0)}px`,
    };
});
</script>

<template>
    <div
        class="festive-snow pointer-events-none fixed inset-0 z-[60] overflow-hidden"
        aria-hidden="true"
    >
        <span
            v-for="(flake, index) in flakes"
            :key="index"
            class="festive-flake"
            :style="{
                left: flake.left,
                width: flake.size,
                height: flake.size,
                opacity: flake.opacity,
                animationDuration: flake.duration,
                animationDelay: flake.delay,
                '--drift': flake.drift,
            }"
        />
    </div>
</template>

<style scoped>
.festive-flake {
    position: absolute;
    top: -10px;
    border-radius: 9999px;
    background: #ffffff;
    box-shadow: 0 0 3px rgba(148, 163, 184, 0.9);
    animation-name: festive-fall;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
    will-change: transform;
}

@keyframes festive-fall {
    0% {
        transform: translate3d(0, 0, 0);
    }
    50% {
        transform: translate3d(var(--drift), 52vh, 0);
    }
    100% {
        transform: translate3d(0, 105vh, 0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .festive-snow {
        display: none;
    }
}
</style>
