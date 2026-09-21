import { computed, reactive, ref, watch } from 'vue';

/** The page sizes every list offers. Matches App\\Support\\PerPage. */
export const PER_PAGE_OPTIONS = [10, 25, 50, 100];

/**
 * The page size in the address, for server-paged lists to carry through
 * their own filter requests so a change of filter keeps the size chosen.
 */
export function currentPerPage(): string | undefined {
    return (
        new URLSearchParams(window.location.search).get('per_page') ?? undefined
    );
}

/**
 * Pages through rows the page already holds. Lists the server pages itself
 * hand the Pagination component their links instead; this is for the ones
 * that arrive whole, so every list pages the same way to the person using it.
 */
export function usePaginated<T>(
    rows: () => T[],
    options: { perPage?: number; resetOn?: () => unknown } = {},
) {
    const perPage = ref(options.perPage ?? 10);
    const page = ref(1);

    watch(perPage, () => {
        page.value = 1;
    });

    // A new filter or tab starts from the top rather than on a stale page.
    if (options.resetOn) {
        watch(options.resetOn, () => {
            page.value = 1;
        });
    }

    const total = computed(() => rows().length);
    const lastPage = computed(() =>
        Math.max(1, Math.ceil(total.value / perPage.value)),
    );

    // A filter or a fresh visit can shrink the list under the current page.
    watch(lastPage, (last) => {
        if (page.value > last) {
            page.value = last;
        }
    });

    const paged = computed(() =>
        rows().slice(
            (page.value - 1) * perPage.value,
            page.value * perPage.value,
        ),
    );

    const from = computed(() =>
        total.value === 0 ? null : (page.value - 1) * perPage.value + 1,
    );
    const to = computed(() =>
        total.value === 0
            ? null
            : Math.min(page.value * perPage.value, total.value),
    );

    // Reactive so a template reads pages.paged and binds v-model:page="pages.page"
    // and v-model:per-page="pages.perPage".
    return reactive({ page, perPage, paged, total, lastPage, from, to });
}
