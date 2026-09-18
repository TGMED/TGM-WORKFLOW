<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/components/ui/EmptyState.vue';
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

defineProps<{
    groups: Array<{
        value: string;
        label: string;
        description: string;
        policies: PolicyRow[];
    }>;
    total: number;
}>();
</script>

<template>
    <Head title="Company policy" />

    <AppLayout
        heading="Company policy"
        lede="The handbook and the rules under it, as they stand today."
    >
        <div class="space-y-6">
            <EmptyState
                v-if="total === 0"
                title="Nothing published yet"
                message="When the people team publishes the handbook it will appear here."
            />

            <Panel
                v-for="group in groups"
                :key="group.value"
                flush
                :title="group.label"
                :subtitle="group.description"
            >
                <ul class="divide-y divide-line-soft">
                    <li
                        v-for="policy in group.policies"
                        :key="policy.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 transition-colors hover:bg-line-soft/40"
                    >
                        <div class="min-w-[220px] flex-1">
                            <p class="text-[14px] font-semibold tracking-tight">
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
                                class="mt-0.5 max-w-prose text-[13px] leading-relaxed text-muted"
                            >
                                {{ policy.summary }}
                            </p>
                            <p class="mt-1 text-[12px] text-faint">
                                In force from {{ policy.effective_label }} ·
                                {{ policy.size_label }}
                            </p>
                        </div>

                        <a
                            :href="`/policies/${policy.id}/file`"
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
                                    d="M12 3.5v11m0 0-3.5-3.5M12 14.5l3.5-3.5M4.5 16.5v2a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-2"
                                />
                            </svg>
                            Read
                        </a>
                    </li>
                </ul>
            </Panel>
        </div>
    </AppLayout>
</template>
