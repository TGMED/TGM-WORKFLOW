<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { NoticeboardEvent, SharedProps } from '@/types';

const page = usePage<SharedProps>();

const board = computed(() => page.props.noticeboard);

const announcements = computed(() => board.value?.announcements ?? []);
const events = computed(() => board.value?.events ?? []);

// Grouped by day, so the rail reads as a diary rather than as a list of
// things that happen to have dates on them.
const days = computed(() => {
    const grouped = new Map<
        string,
        { label: string; items: NoticeboardEvent[] }
    >();

    for (const event of events.value) {
        const day = grouped.get(event.date) ?? {
            label: event.when,
            items: [],
        };

        day.items.push(event);
        grouped.set(event.date, day);
    }

    return [...grouped.entries()].map(([date, day]) => ({ date, ...day }));
});

const expanded = ref<number | null>(null);

const icons: Record<NoticeboardEvent['kind'], string> = {
    birthday:
        'M12 6.5V4m0 2.5c-1.5 0-2.5 1-2.5 2.5h5c0-1.5-1-2.5-2.5-2.5ZM5 12.5h14v6a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 18.5v-6Zm0 0V11a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1.5',
    anniversary:
        'M12 3.5 14 8l5 .7-3.6 3.5.9 5-4.3-2.4L7.7 17l.9-5L5 8.7 10 8l2-4.5Z',
    leave: 'M4.5 6.5h15M6.5 6.5V4m11 2.5V4M3.5 10.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Z',
    out_of_office:
        'M4 20V9.5L12 4l8 5.5V20M4 20h16M14.5 9.5h4.5m0 0-1.8-1.8m1.8 1.8-1.8 1.8',
    holiday:
        'M8 3v3m8-3v3M3.5 9.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Zm7 6.5 1 2 2.2.3-1.6 1.5.4 2.2-2-1-2 1 .4-2.2-1.6-1.5 2.2-.3 1-2Z',
};

const today = new Date().toLocaleDateString(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});
</script>

<template>
    <aside
        v-if="board"
        data-tour="notice-rail"
        class="hidden w-[320px] shrink-0 border-l border-line bg-panel/40 xl:block"
        aria-label="Notices and what is coming up"
    >
        <div class="sticky top-0 max-h-dvh space-y-5 overflow-y-auto p-5">
            <section>
                <p class="eyebrow">Today</p>
                <p
                    class="mt-0.5 font-display text-[15px] font-semibold tracking-tight"
                >
                    {{ today }}
                </p>
            </section>

            <section>
                <div class="flex items-baseline justify-between">
                    <p class="eyebrow">Notices</p>
                    <Link
                        href="/announcements"
                        class="text-[12px] text-muted hover:text-text"
                    >
                        All
                    </Link>
                </div>

                <p
                    v-if="announcements.length === 0"
                    class="mt-2 text-[12.5px] text-faint"
                >
                    Nothing up at the moment.
                </p>

                <ul v-else class="mt-2 space-y-2">
                    <li
                        v-for="announcement in announcements"
                        :key="announcement.id"
                        class="rounded-xl border border-line-soft p-3"
                    >
                        <button
                            type="button"
                            class="w-full text-left"
                            @click="
                                expanded =
                                    expanded === announcement.id
                                        ? null
                                        : announcement.id
                            "
                        >
                            <p
                                class="flex items-start gap-1.5 text-[13px] font-medium"
                            >
                                <span
                                    v-if="announcement.is_pinned"
                                    class="mt-[3px] size-1.5 shrink-0 rounded-full bg-brass"
                                    aria-hidden="true"
                                />
                                {{ announcement.title }}
                            </p>
                        </button>

                        <p
                            class="mt-1 text-[12.5px] leading-relaxed text-muted"
                            :class="
                                expanded === announcement.id
                                    ? ''
                                    : 'line-clamp-2'
                            "
                        >
                            {{ announcement.body }}
                        </p>
                    </li>
                </ul>
            </section>

            <section>
                <p class="eyebrow">Coming up</p>

                <p
                    v-if="days.length === 0"
                    class="mt-2 text-[12.5px] text-faint"
                >
                    Nothing in the next fortnight.
                </p>

                <ul v-else class="mt-2 space-y-3">
                    <li v-for="day in days" :key="day.date">
                        <p class="text-[11.5px] font-medium text-faint">
                            {{ day.label }}
                        </p>
                        <ul class="mt-1 space-y-1.5">
                            <li
                                v-for="(item, index) in day.items"
                                :key="`${day.date}-${index}`"
                                class="flex items-start gap-2"
                            >
                                <svg
                                    class="mt-[3px] size-3.5 shrink-0"
                                    :class="
                                        item.kind === 'holiday'
                                            ? 'text-beacon'
                                            : 'text-faint'
                                    "
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linecap="round"
                                >
                                    <path :d="icons[item.kind]" />
                                </svg>
                                <span class="min-w-0 text-[12.5px]">
                                    <span class="font-medium">
                                        {{ item.who }}
                                    </span>
                                    <span class="text-muted">
                                        · {{ item.label }}
                                    </span>
                                </span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </section>
        </div>
    </aside>
</template>
