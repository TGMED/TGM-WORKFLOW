import { computed, reactive, ref, watch } from 'vue';

/**
 * Narrows rows the page already holds to one status. The choices are the
 * statuses actually present, so the filter offers no dead ends, and a choice
 * that stops being present (the last pending request withdrawn) falls back to
 * every status rather than leaving an empty table behind.
 */
export function useStatusFilter<T>(
    rows: () => T[],
    read: (row: T) => { value: string; label: string },
    everything = 'Every status',
) {
    const status = ref('all');

    const options = computed(() => {
        const seen = new Map<string, string>();

        for (const row of rows()) {
            const { value, label } = read(row);
            seen.set(value, label);
        }

        return [
            { value: 'all', label: everything },
            ...[...seen].map(([value, label]) => ({ value, label })),
        ];
    });

    watch(options, (current) => {
        if (!current.some((option) => option.value === status.value)) {
            status.value = 'all';
        }
    });

    const filtered = computed(() =>
        status.value === 'all'
            ? rows()
            : rows().filter((row) => read(row).value === status.value),
    );

    // Only worth offering when there is more than one status to pick from.
    const useful = computed(() => options.value.length > 2);

    // Reactive so a template binds v-model="filter.status" and a page hands
    // usePaginated () => filter.rows with resetOn: () => filter.status.
    return reactive({ status, options, rows: filtered, useful });
}
