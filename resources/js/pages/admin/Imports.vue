<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
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
</script>

<template>
    <Head title="Import" />

    <AppLayout
        heading="Import"
        lede="Load records in bulk from a spreadsheet. Every sheet has a template and a reference to fill it in against."
    >
        <div class="space-y-5">
            <EmptyState
                v-if="imports.length === 0"
                title="No sheets are yours to import"
                message="Importing a sheet needs the same permission as editing those records by hand. Ask an administrator for the one you need."
            />

            <template v-else>
                <Panel
                    eyebrow="Running order"
                    title="Work down the list"
                    subtitle="Later sheets point at records the earlier ones create. A staff row cannot name a site that is not on file yet."
                    flush
                >
                    <ol class="divide-y divide-line-soft">
                        <li v-for="sheet in numbered" :key="sheet.key">
                            <Link
                                :href="`/admin/imports/${sheet.key}`"
                                class="flex items-start gap-4 px-5 py-4 transition-colors hover:bg-line-soft"
                            >
                                <span
                                    class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg border border-line bg-sunken text-[12px] font-semibold text-muted tabular-nums"
                                >
                                    {{ sheet.step }}
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="font-display text-[15px] font-semibold tracking-tight"
                                        >
                                            {{ sheet.label }}
                                        </span>
                                        <StatusPill tone="neutral">
                                            {{ sheet.column_count }} columns
                                        </StatusPill>
                                    </div>

                                    <p
                                        class="mt-1 text-[13px] leading-relaxed text-muted"
                                    >
                                        {{ sheet.description }}
                                    </p>

                                    <p
                                        v-if="sheet.depends_on.length > 0"
                                        class="mt-1.5 text-[12.5px] text-faint"
                                    >
                                        Import
                                        {{
                                            dependencyLabels(sheet).join(
                                                ' and ',
                                            )
                                        }}
                                        first.
                                    </p>
                                </div>

                                <svg
                                    class="mt-1.5 size-4 shrink-0 text-faint"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="m9 6 6 6-6 6" />
                                </svg>
                            </Link>
                        </li>
                    </ol>
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
            </template>
        </div>
    </AppLayout>
</template>
