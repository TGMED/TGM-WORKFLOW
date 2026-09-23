<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type Section = {
    key: string;
    title: string;
    description: string;
    columns: string[];
    rows: Array<Array<string | number | null>>;
};

const props = defineProps<{
    filters: { from: string; to: string };
    sections: Section[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);

function apply() {
    router.get(
        '/admin/hr-reports',
        { from: from.value, to: to.value },
        { preserveScroll: true, replace: true },
    );
}

function exportUrl(key: string): string {
    const query = new URLSearchParams({
        from: props.filters.from,
        to: props.filters.to,
    });

    return `/admin/hr-reports/${key}/export?${query.toString()}`;
}

// Numbers line up on the right; the first column is always a name.
function isFigure(value: string | number | null): boolean {
    return typeof value === 'number' || /^-?\d+(\.\d+)?$/.test(String(value));
}

function show(value: string | number | null): string {
    if (value === null || value === '') {
        return '-';
    }

    if (typeof value === 'string' && /^-?\d+\.\d{2}$/.test(value)) {
        return Number(value).toLocaleString('en-NG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    return typeof value === 'number' ? value.toLocaleString('en-NG') : value;
}
</script>

<template>
    <Head title="HR reports" />

    <AppLayout
        heading="HR reports"
        lede="Company-wide totals over a window, by department. Each table downloads as a CSV of exactly what it shows."
    >
        <div class="space-y-6">
            <Panel>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="apply"
                >
                    <TextField v-model="from" label="From" type="date" />
                    <TextField v-model="to" label="To" type="date" />
                    <AppButton type="submit">Show</AppButton>
                </form>
            </Panel>

            <Panel
                v-for="section in sections"
                :key="section.key"
                flush
                :title="section.title"
            >
                <template #action>
                    <a
                        :href="exportUrl(section.key)"
                        class="text-[13px] font-medium text-beacon hover:underline"
                    >
                        Download CSV
                    </a>
                </template>

                <p class="px-5 pt-3 text-[12.5px] text-faint">
                    {{ section.description }}
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th
                                    v-for="(column, index) in section.columns"
                                    :key="column"
                                    class="px-5 py-3 font-medium"
                                    :class="index > 0 ? 'text-right' : ''"
                                >
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="section.rows.length === 0">
                                <td :colspan="section.columns.length">
                                    <EmptyState
                                        title="Nothing in this window"
                                        message="Try a wider window."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="(row, index) in section.rows"
                                :key="index"
                                :class="
                                    row[0] === 'All departments'
                                        ? 'font-medium'
                                        : ''
                                "
                            >
                                <td
                                    v-for="(cell, cellIndex) in row"
                                    :key="cellIndex"
                                    class="px-5 py-3"
                                    :class="
                                        cellIndex > 0 && isFigure(cell)
                                            ? 'text-right tabular-nums'
                                            : cellIndex > 0
                                              ? 'text-right'
                                              : ''
                                    "
                                >
                                    {{ show(cell) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </div>
    </AppLayout>
</template>
