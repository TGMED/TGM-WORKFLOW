<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ImportSummary } from '@/types';

const props = defineProps<{
    imports: ImportSummary[];
    conventions: string[];
}>();

// The order the registry hands them over in is the order to work through:
// the things other things point at, then the people, then their records,
// then their history. Numbering it makes that the page's main claim.
const numbered = computed(() =>
    props.imports.map((sheet, index) => ({ ...sheet, step: index + 1 })),
);

const byKey = computed(
    () => new Map(props.imports.map((sheet) => [sheet.key, sheet.label])),
);

function dependencyLabels(sheet: ImportSummary): string[] {
    return sheet.depends_on.map((key) => byKey.value.get(key) ?? key);
}

const pages = usePaginated(() => numbered.value);
</script>

<template>
    <Head title="Import" />

    <AppLayout
        heading="Import"
        lede="Load records in bulk from a spreadsheet. Every sheet has a template and a reference to fill it in against."
    >
        <div class="space-y-5">
            <Panel
                eyebrow="Running order"
                title="Work down the list"
                subtitle="Later sheets point at records the earlier ones create. A staff row cannot name a site that is not on file yet."
                flush
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Step</th>
                                <th class="px-5 py-3 font-medium">Sheet</th>
                                <th class="px-5 py-3 font-medium">Columns</th>
                                <th class="px-5 py-3 font-medium">
                                    Import first
                                </th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="imports.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        title="No sheets are yours to import"
                                        message="Importing a sheet needs the same permission as editing those records by hand. Ask an administrator for the one you need."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="sheet in pages.paged"
                                :key="sheet.key"
                                class="align-top transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <span
                                        class="grid size-7 place-items-center rounded-lg border border-line bg-sunken text-[12px] font-semibold text-muted tabular-nums"
                                    >
                                        {{ sheet.step }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <Link
                                        :href="`/admin/imports/${sheet.key}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ sheet.label }}
                                    </Link>
                                    <p
                                        class="max-w-[28rem] text-[12.5px] leading-relaxed text-muted"
                                    >
                                        {{ sheet.description }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill tone="neutral">
                                        {{ sheet.column_count }} columns
                                    </StatusPill>
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] text-faint"
                                >
                                    {{
                                        sheet.depends_on.length > 0
                                            ? dependencyLabels(sheet).join(
                                                  ' and ',
                                              )
                                            : '-'
                                    }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <Link
                                        :href="`/admin/imports/${sheet.key}`"
                                        class="text-[12.5px] font-medium text-beacon hover:underline"
                                    >
                                        Open
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="pages.page"
                    v-model:per-page="pages.perPage"
                    :last-page="pages.lastPage"
                    :from="pages.from"
                    :to="pages.to"
                    :total="pages.total"
                />
            </Panel>

            <Panel
                eyebrow="Every sheet"
                title="How a file is read"
                subtitle="These hold for all of them, and are repeated on each reference file."
            >
                <ul class="space-y-2.5">
                    <li
                        v-for="convention in conventions"
                        :key="convention"
                        class="flex gap-2.5 text-[13px] leading-relaxed text-muted"
                    >
                        <span
                            class="mt-[7px] size-1 shrink-0 rounded-full bg-faint"
                            aria-hidden="true"
                        />
                        {{ convention }}
                    </li>
                </ul>
            </Panel>
        </div>
    </AppLayout>
</template>
