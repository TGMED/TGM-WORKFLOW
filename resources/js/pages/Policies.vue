<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type PolicyRow = {
    id: number;
    title: string;
    version: string | null;
    summary: string | null;
    file_name: string;
    size_label: string;
    effective_label: string;
};

const props = defineProps<{
    groups: Array<{
        value: string;
        label: string;
        description: string;
        policies: PolicyRow[];
    }>;
    total: number;
}>();

const defaultSize = 10;

/*
 * Only categories with something in them come down. With nothing published
 * at all, one empty table stands in so the page keeps its shape.
 */
const shownGroups = computed(() =>
    props.groups.length
        ? props.groups
        : [
              {
                  value: 'all',
                  label: 'Policies',
                  description: 'The handbook and everything under it.',
                  policies: [] as PolicyRow[],
              },
          ],
);

/** Each group pages on its own, so one long category does not bury the rest. */
const groupPage = reactive<Record<string, number>>({});
const groupSize = reactive<Record<string, number>>({});

function sizeOf(group: { value: string }) {
    return groupSize[group.value] ?? defaultSize;
}

function resize(group: { value: string }, size: number) {
    groupSize[group.value] = size;
    groupPage[group.value] = 1;
}

function pageOf(group: { value: string; policies: PolicyRow[] }) {
    const page = groupPage[group.value] ?? 1;
    const size = sizeOf(group);

    return group.policies.slice((page - 1) * size, page * size);
}

function span(group: { value: string; policies: PolicyRow[] }) {
    const page = groupPage[group.value] ?? 1;
    const size = sizeOf(group);
    const total = group.policies.length;

    return {
        from: total === 0 ? null : (page - 1) * size + 1,
        to: total === 0 ? null : Math.min(page * size, total),
        lastPage: Math.max(1, Math.ceil(total / size)),
    };
}
</script>

<template>
    <Head title="Company policy" />

    <AppLayout
        heading="Company policy"
        lede="The handbook and the rules under it, as they stand today."
    >
        <div class="space-y-6">
            <Panel
                v-for="group in shownGroups"
                :key="group.value"
                flush
                :title="group.label"
                :subtitle="group.description"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Policy</th>
                                <th class="px-5 py-3 font-medium">
                                    In force from
                                </th>
                                <th class="px-5 py-3 font-medium">Size</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="group.policies.length === 0">
                                <td colspan="4">
                                    <EmptyState
                                        title="Nothing published yet"
                                        message="When the people team publishes the handbook it will appear here."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="policy in pageOf(group)"
                                :key="policy.id"
                                class="transition-colors hover:bg-sunken/40"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">
                                        {{ policy.title }}
                                        <span
                                            v-if="policy.version"
                                            class="ml-1 text-[12px] font-normal text-faint"
                                        >
                                            v{{ policy.version }}
                                        </span>
                                    </p>
                                    <p
                                        v-if="policy.summary"
                                        class="max-w-prose text-[12.5px] leading-relaxed text-muted"
                                    >
                                        {{ policy.summary }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-muted"
                                >
                                    {{ policy.effective_label }}
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-muted"
                                >
                                    {{ policy.size_label }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <a
                                        :href="`/policies/${policy.id}/file`"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-line px-3.5 py-2 text-[13px] font-medium transition-colors hover:bg-line-soft"
                                    >
                                        <svg
                                            class="size-4"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.7"
                                            stroke-linecap="round"
                                        >
                                            <path
                                                d="M13.5 4.5h6v6M19.5 4.5 11 13M17.5 13.5v4.5a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 18V8A1.5 1.5 0 0 1 6 6.5h4.5"
                                            />
                                        </svg>
                                        Read
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :page="groupPage[group.value] ?? 1"
                    :per-page="sizeOf(group)"
                    @update:page="groupPage[group.value] = $event"
                    @update:per-page="resize(group, $event)"
                    :last-page="span(group).lastPage"
                    :from="span(group).from"
                    :to="span(group).to"
                    :total="group.policies.length"
                />
            </Panel>
        </div>
    </AppLayout>
</template>
