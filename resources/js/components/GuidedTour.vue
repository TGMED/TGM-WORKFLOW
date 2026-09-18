<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import { tourFor } from '@/lib/tours';
import type { TourStep } from '@/lib/tours';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();

const tour = computed(() => tourFor(page.component));

const open = ref(false);
const index = ref(0);

// Where the spotlight sits. Animated by transitioning these, so moving from
// one step to the next glides rather than jumping.
const box = ref<{
    top: number;
    left: number;
    width: number;
    height: number;
} | null>(null);

const steps = computed<TourStep[]>(() => {
    const all = tour.value?.steps ?? [];

    // A step whose anchor is not on this page describes something that is not
    // there, usually because the person's role does not have it.
    return all.filter(
        (step) =>
            !step.anchor ||
            document.querySelector(`[data-tour="${step.anchor}"]`) !== null,
    );
});

const step = computed(() => steps.value[index.value] ?? null);

const seen = computed(() => page.props.tours_seen ?? []);

function measure() {
    const anchor = step.value?.anchor;

    if (!anchor) {
        box.value = null;

        return;
    }

    const element = document.querySelector(`[data-tour="${anchor}"]`);

    if (!element) {
        box.value = null;

        return;
    }

    element.scrollIntoView({ block: 'center', behavior: 'smooth' });

    const rect = element.getBoundingClientRect();
    const pad = 8;

    box.value = {
        top: rect.top - pad,
        left: rect.left - pad,
        width: rect.width + pad * 2,
        height: rect.height + pad * 2,
    };
}

// The card sits under the spotlight where there is room, and over it where
// there is not, so it never covers the thing it is describing.
const cardStyle = computed(() => {
    if (!box.value) {
        return { top: '50%', left: '50%', transform: 'translate(-50%, -50%)' };
    }

    const below = box.value.top + box.value.height + 16;
    const roomBelow = window.innerHeight - below > 220;

    return {
        top: roomBelow
            ? `${below}px`
            : `${Math.max(16, box.value.top - 220)}px`,
        left: `${Math.min(Math.max(16, box.value.left), window.innerWidth - 360)}px`,
    };
});

function start() {
    index.value = 0;
    open.value = true;
    void nextTick(measure);
}

function next() {
    if (index.value >= steps.value.length - 1) {
        finish();

        return;
    }

    index.value += 1;
    void nextTick(measure);
}

function back() {
    index.value = Math.max(0, index.value - 1);
    void nextTick(measure);
}

function finish() {
    open.value = false;

    const id = tour.value?.id;

    if (!id || seen.value.includes(id)) {
        return;
    }

    router.post(
        '/tours',
        { tour: id },
        { preserveScroll: true, preserveState: true, only: ['tours_seen'] },
    );
}

function onKey(event: KeyboardEvent) {
    if (!open.value) {
        return;
    }

    if (event.key === 'Escape') {
        finish();
    }

    if (event.key === 'ArrowRight' || event.key === 'Enter') {
        next();
    }

    if (event.key === 'ArrowLeft') {
        back();
    }
}

function maybeStart() {
    const id = tour.value?.id;

    if (!id || seen.value.includes(id) || steps.value.length === 0) {
        open.value = false;

        return;
    }

    // A breath after the page settles, so the spotlight measures the layout
    // that the person is actually looking at.
    window.setTimeout(start, 600);
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    window.addEventListener('resize', measure);
    maybeStart();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', measure);
});

// A page change is a new tour, or none.
watch(
    () => page.component,
    () => {
        open.value = false;
        void nextTick(maybeStart);
    },
);

// The help button in the top bar asks for this by name.
defineExpose({ start, hasTour: computed(() => tour.value !== null) });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && step"
            class="fixed inset-0 z-50"
            role="dialog"
            aria-modal="true"
            :aria-label="step.title"
        >
            <!-- The dimmed page. A spotlight is cut out of it with a very
                 large shadow rather than with four separate panels, which
                 keeps it to one element that can be transitioned. -->
            <div
                v-if="box"
                class="tour-spotlight pointer-events-none absolute rounded-xl"
                :style="{
                    top: `${box.top}px`,
                    left: `${box.left}px`,
                    width: `${box.width}px`,
                    height: `${box.height}px`,
                }"
            />
            <div v-else class="absolute inset-0 bg-black/55" @click="finish" />

            <div
                class="tour-card absolute w-[340px] max-w-[calc(100vw-32px)] rounded-2xl border border-line bg-panel p-4 shadow-panel"
                :style="cardStyle"
            >
                <p class="eyebrow">
                    Step {{ index + 1 }} of {{ steps.length }}
                </p>
                <p
                    class="mt-1 font-display text-[15px] font-semibold tracking-tight"
                >
                    {{ step.title }}
                </p>
                <p class="mt-1.5 text-[13px] leading-relaxed text-muted">
                    {{ step.body }}
                </p>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        class="text-[12.5px] text-faint hover:text-muted"
                        @click="finish"
                    >
                        Skip
                    </button>

                    <div class="flex items-center gap-2">
                        <AppButton
                            v-if="index > 0"
                            size="sm"
                            variant="ghost"
                            @click="back"
                        >
                            Back
                        </AppButton>
                        <AppButton size="sm" @click="next">
                            {{ index === steps.length - 1 ? 'Done' : 'Next' }}
                        </AppButton>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* The cut-out: everything outside the box is dimmed by the spread of the
   shadow, and the box itself stays clear. Transitioning the position and the
   size is what makes the spotlight travel between steps. */
.tour-spotlight {
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.55);
    outline: 2px solid var(--color-brand, #4f7cff);
    outline-offset: 2px;
    transition:
        top 320ms cubic-bezier(0.4, 0, 0.2, 1),
        left 320ms cubic-bezier(0.4, 0, 0.2, 1),
        width 320ms cubic-bezier(0.4, 0, 0.2, 1),
        height 320ms cubic-bezier(0.4, 0, 0.2, 1);
}

.tour-card {
    animation: tour-card-in 220ms cubic-bezier(0.4, 0, 0.2, 1);
    transition:
        top 320ms cubic-bezier(0.4, 0, 0.2, 1),
        left 320ms cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes tour-card-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Somebody who has asked not to be moved about gets the same tour without
   anything sliding. */
@media (prefers-reduced-motion: reduce) {
    .tour-spotlight,
    .tour-card {
        transition: none;
        animation: none;
    }
}
</style>
