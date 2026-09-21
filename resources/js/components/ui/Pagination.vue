<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PER_PAGE_OPTIONS } from '@/composables/usePaginated';

/*
 * Server-paged lists pass the paginator's links and per_page. Lists paged in
 * the browser (usePaginated) pass lastPage and bind v-model:page and
 * v-model:per-page instead.
 */
const props = defineProps<{
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    lastPage?: number;
    from: number | null;
    to: number | null;
    total: number;
}>();

const page = defineModel<number>('page', { default: 1 });
const perPage = defineModel<number>('perPage', { default: 10 });

/** A list's own default stays on offer even when it is not a standard size. */
const sizes = computed(() =>
    [...new Set([...PER_PAGE_OPTIONS, perPage.value])].sort((a, b) => a - b),
);

function changeSize(event: Event) {
    const size = Number((event.target as HTMLSelectElement).value);

    if (!props.links) {
        perPage.value = size;

        return;
    }

    // The server pages this list: ask it again from the first page, keeping
    // whatever filters are already in the address.
    const query = Object.fromEntries(
        new URLSearchParams(window.location.search),
    );
    delete query.page;

    router.get(
        window.location.pathname,
        { ...query, per_page: size },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

type Step = { page: number | null; label: string; active: boolean };

/** The same run of steps Laravel's paginator gives, built for the browser. */
const steps = computed<Step[]>(() => {
    const last = props.lastPage ?? 1;
    const current = page.value;
    const shown = new Set([1, last, current - 1, current, current + 1]);
    const numbers: Step[] = [];
    let previous = 0;

    for (let number = 1; number <= last; number++) {
        if (!shown.has(number)) {
            continue;
        }

        if (number - previous > 1) {
            numbers.push({ page: null, label: '...', active: false });
        }

        numbers.push({
            page: number,
            label: String(number),
            active: number === current,
        });
        previous = number;
    }

    return [
        {
            page: current > 1 ? current - 1 : null,
            label: '&laquo; Previous',
            active: false,
        },
        ...numbers,
        {
            page: current < last ? current + 1 : null,
            label: 'Next &raquo;',
            active: false,
        },
    ];
});

const stepClass = (active: boolean) => [
    'grid h-8 min-w-8 place-items-center rounded-lg px-2 text-[12.5px] font-medium',
    'transition-colors duration-150',
    active
        ? 'bg-signal text-[#06231b]'
        : 'text-muted hover:bg-line-soft hover:text-text',
];
</script>

<template>
    <div
        v-if="total > 0"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft px-5 py-3.5"
    >
        <div class="flex flex-wrap items-center gap-4">
            <p class="tabular text-[12.5px] text-faint">
                Showing {{ from ?? 0 }}–{{ to ?? 0 }} of {{ total }}
            </p>

            <label class="flex items-center gap-2 text-[12.5px] text-faint">
                Rows per page
                <select
                    :value="perPage"
                    class="h-8 rounded-lg border border-line bg-panel-raised px-2 text-[12.5px] text-text focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    @change="changeSize"
                >
                    <option v-for="size in sizes" :key="size" :value="size">
                        {{ size }}
                    </option>
                </select>
            </label>
        </div>

        <nav v-if="links && links.length > 3" class="flex items-center gap-1">
            <template v-for="(link, index) in links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    :class="stepClass(link.active)"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="grid h-8 min-w-8 place-items-center px-2 text-[12.5px] text-faint/50"
                    v-html="link.label"
                />
            </template>
        </nav>

        <nav
            v-else-if="!links && (lastPage ?? 1) > 1"
            class="flex items-center gap-1"
        >
            <template v-for="(step, index) in steps" :key="index">
                <button
                    v-if="step.page !== null"
                    type="button"
                    :class="stepClass(step.active)"
                    :aria-current="step.active ? 'page' : undefined"
                    @click="page = step.page"
                    v-html="step.label"
                />
                <span
                    v-else
                    class="grid h-8 min-w-8 place-items-center px-2 text-[12.5px] text-faint/50"
                    v-html="step.label"
                />
            </template>
        </nav>
    </div>
</template>
