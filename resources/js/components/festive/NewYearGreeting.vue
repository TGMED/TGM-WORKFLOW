<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { NEW_YEAR } from '@/lib/festive';

/*
 * A New Year card at the top of the sign-in page. It can be closed, and stays
 * clear of the form so nobody has to dismiss it to sign in.
 */
const open = ref(false);

onMounted(() => {
    // Let it arrive just after the first fireworks go up.
    setTimeout(() => {
        open.value = true;
    }, 400);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-700 ease-out"
        enter-from-class="-translate-y-4 opacity-0"
        leave-active-class="transition duration-200 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
    >
        <div
            v-if="open"
            class="fixed inset-x-4 top-4 z-[61] mx-auto max-w-md sm:top-6 lg:top-28 lg:right-auto lg:left-12 lg:mx-0 lg:w-[26rem]"
            role="status"
        >
            <div
                class="relative overflow-hidden rounded-2xl border border-white/15 bg-[#0b1224]/85 px-6 py-5 text-center text-white shadow-2xl backdrop-blur-md"
            >
                <div
                    class="pointer-events-none absolute inset-x-0 -top-px h-px bg-gradient-to-r from-transparent via-amber-300/80 to-transparent"
                    aria-hidden="true"
                />
                <p
                    class="text-[11px] font-semibold tracking-[0.3em] text-amber-300 uppercase"
                >
                    Welcome to {{ NEW_YEAR }}
                </p>
                <p
                    class="mt-1.5 bg-gradient-to-r from-amber-200 via-white to-amber-200 bg-clip-text text-[28px] leading-tight font-bold text-transparent"
                >
                    Happy New Year!
                </p>
                <p class="mt-1.5 text-[13.5px] leading-relaxed text-white/75">
                    Thank you for all you gave last year. Wishing you and your
                    family a healthy, happy and successful {{ NEW_YEAR }}.
                </p>
                <p class="mt-2 text-[12px] text-white/50">
                    From all of us at TGM Education
                </p>

                <button
                    type="button"
                    class="absolute top-2.5 right-2.5 rounded-lg p-1.5 text-white/50 transition hover:bg-white/10 hover:text-white"
                    aria-label="Close the New Year greeting"
                    @click="open = false"
                >
                    <svg
                        class="size-4"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            d="M5.3 5.3a1 1 0 0 1 1.4 0L10 8.6l3.3-3.3a1 1 0 1 1 1.4 1.4L11.4 10l3.3 3.3a1 1 0 0 1-1.4 1.4L10 11.4l-3.3 3.3a1 1 0 0 1-1.4-1.4L8.6 10 5.3 6.7a1 1 0 0 1 0-1.4Z"
                        />
                    </svg>
                </button>
            </div>
        </div>
    </Transition>
</template>
