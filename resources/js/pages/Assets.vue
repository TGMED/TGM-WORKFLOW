<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type Row = {
    id: number;
    tag: string;
    name: string;
    category: string;
    serial_number: string | null;
    location: string | null;
    spot: string | null;
    condition_label: string;
    assigned_at: string | null;
};

const props = defineProps<{ assets: Row[] }>();

const pages = usePaginated(() => props.assets);
</script>

<template>
    <Head title="My assets" />

    <AppLayout
        heading="My assets"
        lede="Company equipment in your care. If something here is not with you, or something you have is missing, tell HR."
    >
        <Panel flush>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-[13.5px]">
                    <thead
                        class="border-b border-line-soft text-left text-[12px] text-faint"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Asset</th>
                            <th class="px-5 py-3 font-medium">Category</th>
                            <th class="px-5 py-3 font-medium">Kept at</th>
                            <th class="px-5 py-3 font-medium">Condition</th>
                            <th class="px-5 py-3 font-medium">
                                With you since
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <tr v-if="assets.length === 0">
                            <td colspan="5">
                                <EmptyState
                                    title="Nothing assigned"
                                    message="No company equipment is assigned to you."
                                />
                            </td>
                        </tr>
                        <tr v-for="row in pages.paged" :key="row.id">
                            <td class="px-5 py-3.5">
                                <p class="font-medium">{{ row.name }}</p>
                                <p class="text-[12px] text-faint">
                                    {{ row.tag }}
                                    <template v-if="row.serial_number">
                                        · S/N {{ row.serial_number }}
                                    </template>
                                </p>
                            </td>
                            <td class="px-5 py-3.5 text-muted">
                                {{ row.category }}
                            </td>
                            <td class="px-5 py-3.5 text-muted">
                                {{
                                    [row.location, row.spot]
                                        .filter(Boolean)
                                        .join(', ') || '-'
                                }}
                            </td>
                            <td class="px-5 py-3.5 text-muted">
                                {{ row.condition_label }}
                            </td>
                            <td
                                class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                            >
                                {{ dateTime(row.assigned_at) }}
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
    </AppLayout>
</template>
