<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AccountNameField from '@/components/banking/AccountNameField.vue';
import DocumentsField from '@/components/banking/DocumentsField.vue';
import RequisitionDetails from '@/components/banking/RequisitionDetails.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusFilter from '@/components/ui/StatusFilter.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import TextField from '@/components/ui/TextField.vue';
import { useAccountLookup } from '@/composables/useAccountLookup';
import { usePaginated } from '@/composables/usePaginated';
import { useStatusFilter } from '@/composables/useStatusFilter';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, money } from '@/lib/format';
import type { RequisitionRow } from '@/types/requisition';

const props = defineProps<{
    requisitions: RequisitionRow[];
    banks: Array<{ value: string; label: string }>;
}>();

const raising = ref(false);
const viewingId = ref<number | null>(null);
const withdrawing = ref(false);

const viewing = computed(
    () => props.requisitions.find((row) => row.id === viewingId.value) ?? null,
);

const form = useForm({
    title: '',
    purpose: '',
    amount: '' as number | '',
    bank_code: null as string | null,
    account_number: '',
    documents: [] as File[],
});

const { accountName, lookup, lookupError } = useAccountLookup(
    () => [form.bank_code, form.account_number] as const,
);

function openRaise() {
    form.reset();
    form.clearErrors();
    raising.value = true;
}

function submit() {
    form.post('/requisitions', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            raising.value = false;
        },
    });
}

const retireForm = useForm({
    amount_spent: '' as number | string,
    notes: '',
    documents: [] as File[],
});

function open(row: RequisitionRow) {
    retireForm.defaults({
        amount_spent: row.retirement?.amount_spent ?? row.amount,
        notes: row.retirement?.notes ?? '',
        documents: [],
    });
    retireForm.reset();
    retireForm.clearErrors();
    viewingId.value = row.id;
}

function retire() {
    if (!viewing.value) {
        return;
    }

    retireForm.post(`/requisitions/${viewing.value.id}/retirement`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            viewingId.value = null;
        },
    });
}

function withdraw() {
    if (!viewing.value) {
        return;
    }

    withdrawing.value = true;

    router.delete(`/requisitions/${viewing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            withdrawing.value = false;
            viewingId.value = null;
        },
    });
}

const statuses = useStatusFilter(
    () => props.requisitions,
    (row) => ({ value: row.status, label: row.status_label }),
);
const pages = usePaginated(() => statuses.rows, {
    resetOn: () => statuses.status,
});
</script>

<template>
    <Head title="Requisitions" />

    <AppLayout
        heading="Requisitions"
        lede="Ask finance for money ahead of spending it, then retire it with what you spent."
    >
        <template #toolbar>
            <AppButton size="sm" @click="openRaise()">
                Raise a requisition
            </AppButton>
        </template>

        <Panel flush>
            <template v-if="statuses.useful" #action>
                <StatusFilter v-model="statuses.status" :filter="statuses" />
            </template>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-[13.5px]">
                    <thead
                        class="border-b border-line-soft text-left text-[12px] text-faint"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Requisition</th>
                            <th class="px-5 py-3 text-right font-medium">
                                Amount
                            </th>
                            <th class="px-5 py-3 font-medium">Raised</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Retirement</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <tr v-if="requisitions.length === 0">
                            <td colspan="5">
                                <EmptyState
                                    title="Nothing raised"
                                    message="Raise a requisition when you need money for company spending."
                                />
                            </td>
                        </tr>
                        <tr
                            v-for="row in pages.paged"
                            :key="row.id"
                            class="cursor-pointer transition-colors hover:bg-sunken/40"
                            @click="open(row)"
                        >
                            <td class="px-5 py-3.5">
                                <p class="font-medium">{{ row.title }}</p>
                                <p class="text-[12px] text-faint">
                                    {{ row.reference }} · {{ row.account_name }}
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
                                <span
                                    v-else-if="row.awaits_retirement"
                                    class="text-[12.5px] text-brass"
                                >
                                    To retire
                                </span>
                                <span v-else class="text-faint">-</span>
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

        <ModalShell
            :open="raising"
            width="lg"
            title="Raise a requisition"
            subtitle="Finance approves it and pays the account you name."
            @close="raising = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.title"
                    label="What it is for"
                    required
                    placeholder="Printer toner for the finance office"
                    :error="form.errors.title"
                />
                <TextareaField
                    v-model="form.purpose"
                    label="Details"
                    required
                    :rows="4"
                    placeholder="What is being bought, from whom, and why."
                    :error="form.errors.purpose"
                />
                <TextField
                    v-model="form.amount"
                    label="Amount (NGN)"
                    type="number"
                    min="1"
                    step="0.01"
                    required
                    :error="form.errors.amount"
                />

                <div class="space-y-4 rounded-xl bg-sunken/40 p-4">
                    <p class="text-[13px] font-medium">Who is to be paid</p>
                    <SelectField
                        v-model="form.bank_code"
                        label="Bank"
                        required
                        :options="banks"
                        :error="form.errors.bank_code"
                    >
                        <option :value="null" disabled>Select bank</option>
                    </SelectField>
                    <TextField
                        v-model="form.account_number"
                        label="Account number"
                        inputmode="numeric"
                        autocomplete="off"
                        required
                        :error="form.errors.account_number"
                    />
                    <AccountNameField
                        :name="accountName"
                        :state="lookup"
                        :error="lookupError"
                    />
                </div>

                <DocumentsField
                    v-model="form.documents"
                    label="Quotes or invoices"
                    :errors="form.errors"
                />
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="raising = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Send to finance
                </AppButton>
            </template>
        </ModalShell>

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

                <form
                    v-if="viewing.awaits_retirement"
                    class="space-y-4 border-t border-line-soft pt-4"
                    @submit.prevent="retire"
                >
                    <p class="text-[13px] font-medium">
                        {{
                            viewing.retirement
                                ? 'Correct your retirement and send it again'
                                : 'Retire it: account for what you spent'
                        }}
                    </p>
                    <TextField
                        v-model="retireForm.amount_spent"
                        label="Amount spent (NGN)"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                        :error="retireForm.errors.amount_spent"
                    />
                    <TextareaField
                        v-model="retireForm.notes"
                        label="Notes"
                        :rows="3"
                        placeholder="Anything finance should know, such as change returned."
                        :error="retireForm.errors.notes"
                    />
                    <DocumentsField
                        v-model="retireForm.documents"
                        label="Receipts"
                        :errors="retireForm.errors"
                    />
                </form>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="viewingId = null">
                    Close
                </AppButton>
                <AppButton
                    v-if="viewing?.status === 'pending'"
                    variant="danger"
                    :loading="withdrawing"
                    @click="withdraw"
                >
                    Withdraw
                </AppButton>
                <AppButton
                    v-if="viewing?.awaits_retirement"
                    :loading="retireForm.processing"
                    @click="retire"
                >
                    Send retirement
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
