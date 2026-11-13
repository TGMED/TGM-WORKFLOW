<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/*
 * Fireworks over the page: rockets climb from the bottom and burst into
 * sparks that fall and fade. Drawn on one canvas behind a pointer-transparent
 * layer, so nothing on the page is blocked. It pauses while the tab is hidden
 * and is left out entirely for anyone who has asked for reduced motion.
 */
type Rocket = {
    x: number;
    y: number;
    vy: number;
    burstAt: number;
    hue: number;
};
type Spark = {
    x: number;
    y: number;
    px: number;
    py: number;
    vx: number;
    vy: number;
    life: number;
    decay: number;
    hue: number;
    size: number;
};

const canvas = ref<HTMLCanvasElement | null>(null);
const show = ref(true);

let frame = 0;
let last = 0;
let nextLaunch = 0;
let rockets: Rocket[] = [];
let sparks: Spark[] = [];
let flashes: { x: number; y: number; hue: number; life: number }[] = [];
let width = 0;
let height = 0;
let ratio = 1;

const hues = [0, 45, 120, 200, 280, 330];

function resize() {
    const el = canvas.value;

    if (!el) {
        return;
    }

    ratio = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    height = window.innerHeight;
    el.width = width * ratio;
    el.height = height * ratio;
    el.style.width = `${width}px`;
    el.style.height = `${height}px`;
}

function launch() {
    rockets.push({
        x: width * (0.15 + Math.random() * 0.7),
        y: height,
        vy: -(height * (0.0011 + Math.random() * 0.0004)),
        burstAt: height * (0.15 + Math.random() * 0.3),
        hue: hues[Math.floor(Math.random() * hues.length)],
    });
}

function burst(rocket: Rocket) {
    const count = 60 + Math.floor(Math.random() * 30);

    for (let i = 0; i < count; i++) {
        const angle = (Math.PI * 2 * i) / count + Math.random() * 0.1;
        const speed = 0.12 + Math.random() * 0.2;

        sparks.push({
            x: rocket.x,
            y: rocket.y,
            px: rocket.x,
            py: rocket.y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            life: 1,
            decay: 0.0007 + Math.random() * 0.0006,
            hue: rocket.hue + (Math.random() * 30 - 15),
            size: 1.6 + Math.random() * 1.6,
        });
    }

    flashes.push({ x: rocket.x, y: rocket.y, hue: rocket.hue, life: 1 });
}

function tick(time: number) {
    const ctx = canvas.value?.getContext('2d');

    if (!ctx) {
        return;
    }

    const dt = Math.min(time - (last || time), 50);
    last = time;

    if (time >= nextLaunch) {
        launch();
        nextLaunch = time + 350 + Math.random() * 650;
    }

    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    ctx.globalCompositeOperation = 'lighter';

    // A soft glow where each shell opens.
    flashes = flashes.filter((flash) => {
        flash.life -= 0.004 * dt;

        if (flash.life <= 0) {
            return false;
        }

        const glow = ctx.createRadialGradient(
            flash.x,
            flash.y,
            0,
            flash.x,
            flash.y,
            90,
        );
        glow.addColorStop(
            0,
            `hsla(${flash.hue}, 100%, 70%, ${0.35 * flash.life})`,
        );
        glow.addColorStop(1, `hsla(${flash.hue}, 100%, 70%, 0)`);
        ctx.fillStyle = glow;
        ctx.fillRect(flash.x - 90, flash.y - 90, 180, 180);

        return true;
    });

    ctx.lineCap = 'round';

    rockets = rockets.filter((rocket) => {
        rocket.y += rocket.vy * dt;

        // The rising shell, with a short tail.
        ctx.strokeStyle = `hsla(${rocket.hue}, 100%, 80%, 0.9)`;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(rocket.x, rocket.y + 16);
        ctx.lineTo(rocket.x, rocket.y);
        ctx.stroke();

        if (rocket.y <= rocket.burstAt) {
            burst(rocket);

            return false;
        }

        return true;
    });

    sparks = sparks.filter((spark) => {
        spark.px = spark.x;
        spark.py = spark.y;
        spark.vy += 0.00012 * dt;
        spark.vx *= 0.995;
        spark.x += spark.vx * dt;
        spark.y += spark.vy * dt;
        spark.life -= spark.decay * dt;

        if (spark.life <= 0) {
            return false;
        }

        // Each spark streaks a little behind itself as it flies.
        ctx.strokeStyle = `hsla(${spark.hue}, 100%, 65%, ${spark.life})`;
        ctx.lineWidth = spark.size * spark.life;
        ctx.beginPath();
        ctx.moveTo(
            spark.px - (spark.x - spark.px) * 3,
            spark.py - (spark.y - spark.py) * 3,
        );
        ctx.lineTo(spark.x, spark.y);
        ctx.stroke();

        return true;
    });

    frame = requestAnimationFrame(tick);
}

function start() {
    cancelAnimationFrame(frame);
    last = 0;
    frame = requestAnimationFrame(tick);
}

function onVisibility() {
    if (document.hidden) {
        cancelAnimationFrame(frame);
    } else {
        start();
    }
}

onMounted(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        show.value = false;

        return;
    }

    resize();
    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', onVisibility);

    // Open with a few at once, so the first look is a celebration.
    launch();
    launch();
    launch();
    start();
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    window.removeEventListener('resize', resize);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <canvas
        v-if="show"
        ref="canvas"
        class="pointer-events-none fixed inset-0 z-[55]"
        aria-hidden="true"
    />
</template>
