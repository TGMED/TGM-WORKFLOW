<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RequisitionDetails from '@/components/banking/RequisitionDetails.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import { currentPerPage } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, money } from '@/lib/format';
import type { RequisitionRow } from '@/types/requisition';

const props = defineProps<{
    requisitions: {
        data: RequisitionRow[];
        per_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { status: string };
    statuses: Array<{ value: string; label: string }>;
    totals: Record<string, { count: number; amount: string }>;
    retirements_waiting: number;
}>();

const status = ref(props.filters.status);

watch(status, () => {
    router.get(
        '/admin/requisitions',
        { status: status.value, per_page: currentPerPage() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

const viewingId = ref<number | null>(null);
const viewing = computed(
    () =>
        props.requisitions.data.find((row) => row.id === viewingId.value) ??
        null,
);

const decision = useForm({ decision: 'approved', note: '' });
const payment = useForm({ payment_reference: '' });
const review = useForm({ decision: 'accepted', note: '' });

function open(row: RequisitionRow) {
    decision.reset();
    payment.reset();
    review.reset();
    decision.clearErrors();
    payment.clearErrors();
    review.clearErrors();
    viewingId.value = row.id;
}

const close = {
    preserveScroll: true,
    onSuccess: () => (viewingId.value = null),
};

function decide(value: 'approved' | 'declined') {
    decision.decision = value;
    decision.post(`/admin/requisitions/${viewingId.value}/decision`, close);
}

function pay() {
    payment.post(`/admin/requisitions/${viewingId.value}/payment`, close);
}

function answerRetirement(value: 'accepted' | 'queried') {
    review.decision = value;
    review.post(
        `/admin/requisitions/${viewingId.value}/retirement-review`,
        close,
    );
}

const waitingRetirement = computed(
    () => viewing.value?.retirement?.status === 'pending',
);
</script>

<template>
    <Head title="Requisitions" />

    <AppLayout
        heading="Requisitions"
        lede="Approve requisitions, record them as paid, and close them when the retirement adds up."
    >
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Waiting on a decision"
                    :value="totals.pending?.count ?? 0"
                    tone="brass"
                    :caption="money(Number(totals.pending?.amount ?? 0))"
                />
                <StatTile
                    label="Approved, not yet paid"
                    :value="totals.approved?.count ?? 0"
                    :caption="money(Number(totals.approved?.amount ?? 0))"
                />
                <StatTile
                    label="Paid, not yet retired"
                    :value="totals.paid?.count ?? 0"
                    :caption="money(Number(totals.paid?.amount ?? 0))"
                />
                <StatTile
                    label="Retirements to check"
                    :value="retirements_waiting"
                    :tone="retirements_waiting > 0 ? 'alert' : 'default'"
                />
            </div>

            <Panel flush>
                <template #action>
                    <SelectField v-model="status" :options="statuses" />
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">
                                    Requisition
                                </th>
                                <th class="px-5 py-3 font-medium">Raised by</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    Amount
                                </th>
                                <th class="px-5 py-3 font-medium">Raised</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">
                                    Retirement
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="requisitions.data.length === 0">
                                <td colspan="6">
                                    <EmptyState
                                        title="Nothing here"
                                        message="No requisition matches this filter."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in requisitions.data"
                                :key="row.id"
                                class="cursor-pointer transition-colors hover:bg-sunken/40"
                                @click="open(row)"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ row.title }}</p>
                                    <p class="text-[12px] text-faint">
                                        {{ row.reference }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="text-muted">
                                        {{ row.requester }}
                                    </p>
                                    <p
                                        v-if="row.department"
                                        class="text-[12px] text-faint"
                                    >
                                        {{ row.department }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ money(Number(row.amount)) }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(row.created_at) }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill :tone="row.status_tone" dot>
                                        {{ row.status_label }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        v-if="row.retirement"
                                        :tone="row.retirement.status_tone"
                                    >
                                        {{ row.retirement.status_label }}
                                    </StatusPill>
                                    <span v-else class="text-faint">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :links="requisitions.links"
                    :per-page="requisitions.per_page"
                    :from="requisitions.from"
                    :to="requisitions.to"
                    :total="requisitions.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="viewing !== null"
            width="lg"
            :title="viewing?.title ?? ''"
            :subtitle="
                viewing ? `${viewing.reference} · ${viewing.status_label}` : ''
            "
            @close="viewingId = null"
        >
            <div v-if="viewing" class="space-y-6">
                <RequisitionDetails :row="viewing" />

                <div
                    v-if="viewing.status === 'pending'"
                    class="space-y-3 border-t border-line-soft pt-4"
                >
                    <TextareaField
                        v-model="decision.note"
                        label="Note to them"
                        :rows="3"
                        hint="Needed to decline."
                        :error="decision.errors.note"
                    />
                </div>

                <div
                    v-if="viewing.status === 'approved'"
                    class="space-y-3 border-t border-line-soft pt-4"
                >
                    <TextField
                        v-model="payment.payment_reference"
                        label="Payment reference"
                        placeholder="Bank transfer reference, if there is one"
                        :error="payment.errors.payment_reference"
                    />
                </div>

                <div
                    v-if="waitingRetirement"
                    class="space-y-3 border-t border-line-soft pt-4"
                >
                    <TextareaField
                        v-model="review.note"
                        label="Note on the retirement"
                        :rows="3"
                        hint="Needed to send it back."
                        :error="review.errors.note"
                    />
                </div>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="viewingId = null">
                    Close
                </AppButton>
                <template v-if="viewing?.status === 'pending'">
                    <AppButton
                        variant="danger"
                        :loading="decision.processing"
                        @click="decide('declined')"
                    >
                        Decline
                    </AppButton>
                    <AppButton
                        :loading="decision.processing"
                        @click="decide('approved')"
                    >
                        Approve
                    </AppButton>
                </template>
                <AppButton
                    v-if="viewing?.status === 'approved'"
                    :loading="payment.processing"
                    @click="pay"
                >
                    Mark as paid
                </AppButton>
                <template v-if="waitingRetirement">
                    <AppButton
                        variant="ghost"
                        :loading="review.processing"
                        @click="answerRetirement('queried')"
                    >
                        Send back
                    </AppButton>
                    <AppButton
                        :loading="review.processing"
                        @click="answerRetirement('accepted')"
                    >
                        Accept and close
                    </AppButton>
                </template>
            </template>
        </ModalShell>
    </AppLayout>
</template>
