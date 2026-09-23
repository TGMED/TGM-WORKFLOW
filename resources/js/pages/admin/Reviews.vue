<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { currentPerPage } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type ReviewRow = {
    id: number;
    subject: string;
    department: string | null;
    reviewer: string;
    standing_label: string;
    visibility: string;
    visibility_label: string;
    visibility_tone: Tone;
    rating: number;
    body: string;
    created_at: string | null;
};

const props = defineProps<{
    reviews: {
        data: ReviewRow[];
        per_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { visibility: string };
    visibilities: Array<{ value: string; label: string }>;
    counts: { public: number; private: number };
}>();

const viewing = ref<ReviewRow | null>(null);
const visibility = ref(props.filters.visibility);

const visibilityOptions = computed(() => [
    { value: '', label: 'Every review' },
    ...props.visibilities,
]);

watch(visibility, () => {
    router.get(
        '/admin/reviews',
        { visibility: visibility.value, per_page: currentPerPage() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});
</script>

<template>
    <Head title="Performance reviews" />

    <AppLayout
        heading="Performance reviews"
        lede="Every review staff have written of each other. The author's name goes no further than this page."
    >
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <StatTile
                    label="Shared with the subject"
                    :value="counts.public"
                    tone="signal"
                    caption="Read by them without a name"
                />
                <StatTile
                    label="HR only"
                    :value="counts.private"
                    tone="brass"
                    caption="The subject is not told of these"
                />
            </div>

            <Panel flush>
                <template #action>
                    <SelectField
                        v-model="visibility"
                        :options="visibilityOptions"
                    />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">About</th>
                                <th class="px-5 py-3 font-medium">
                                    Written by
                                </th>
                                <th class="px-5 py-3 font-medium">Rating</th>
                                <th class="px-5 py-3 font-medium">Review</th>
                                <th class="px-5 py-3 font-medium">Written</th>
                                <th class="px-5 py-3 font-medium">
                                    Visibility
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="reviews.data.length === 0">
                                <td colspan="6">
                                    <EmptyState
                                        title="No reviews"
                                        message="Nothing matches this filter yet."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in reviews.data"
                                :key="row.id"
                                class="cursor-pointer align-top transition-colors hover:bg-sunken/40"
                                @click="viewing = row"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ row.subject }}</p>
                                    <p
                                        v-if="row.department"
                                        class="text-[12px] text-faint"
                                    >
                                        {{ row.department }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="text-muted">{{ row.reviewer }}</p>
                                    <p class="text-[12px] text-faint">
                                        {{ row.standing_label }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-muted"
                                >
                                    {{ row.rating }} of 5
                                </td>
                                <td class="px-5 py-3.5">
                                    <p
                                        class="max-w-[24rem] truncate text-[12.5px] text-faint"
                                        :title="row.body"
                                    >
                                        {{ row.body }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(row.created_at) }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill :tone="row.visibility_tone" dot>
                                        {{ row.visibility_label }}
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :links="reviews.links"
                    :per-page="reviews.per_page"
                    :from="reviews.from"
                    :to="reviews.to"
                    :total="reviews.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="viewing !== null"
            width="lg"
            :title="viewing ? `Review of ${viewing.subject}` : ''"
            :subtitle="viewing?.visibility_label ?? ''"
            @close="viewing = null"
        >
            <div v-if="viewing" class="space-y-5">
                <dl class="grid gap-3 text-[13px] sm:grid-cols-3">
                    <div>
                        <dt class="text-[12px] text-faint">Written by</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ viewing.reviewer }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[12px] text-faint">Standing</dt>
                        <dd class="mt-0.5">{{ viewing.standing_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-[12px] text-faint">Rating</dt>
                        <dd class="mt-0.5">{{ viewing.rating }} of 5</dd>
                    </div>
                </dl>

                <div class="rounded-xl bg-sunken/50 p-4">
                    <p
                        class="text-[13.5px] leading-relaxed whitespace-pre-line"
                    >
                        {{ viewing.body }}
                    </p>
                </div>

                <p class="text-[12.5px] text-faint">
                    Written {{ dateTime(viewing.created_at) }}
                </p>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="viewing = null">
                    Close
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
