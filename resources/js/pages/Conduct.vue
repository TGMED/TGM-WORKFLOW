<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, fullDate } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type Row = {
    id: number;
    kind: string;
    kind_label: string;
    kind_tone: Tone;
    title: string;
    body: string;
    offence: string | null;
    issued_by: string | null;
    response_due_on: string | null;
    response: string | null;
    responded_at: string | null;
    acknowledged_at: string | null;
    expects_response: boolean;
    state_label: string;
    state_tone: Tone;
    created_at: string | null;
};

const props = defineProps<{ actions: Row[] }>();

const reading = ref<Row | null>(null);
const busy = ref(false);

const form = useForm({ response: '' });

function open(row: Row) {
    form.clearErrors();
    form.reset();
    reading.value = row;
}

function answer() {
    if (!reading.value) {
        return;
    }

    form.post(`/conduct/${reading.value.id}/respond`, {
        preserveScroll: true,
        onSuccess: () => {
            reading.value = null;
        },
    });
}

function acknowledge() {
    if (!reading.value) {
        return;
    }

    busy.value = true;

    router.post(
        `/conduct/${reading.value.id}/acknowledge`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                reading.value = null;
            },
        },
    );
}

const pages = usePaginated(() => props.actions);
</script>

<template>
    <Head title="Conduct" />

    <AppLayout
        heading="Queries and warnings"
        lede="Formal letters HR has issued to you, including your confirmation. Answer a query here; your answer goes to HR and your head of department."
    >
        <Panel flush>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-[13.5px]">
                    <thead
                        class="border-b border-line-soft text-left text-[12px] text-faint"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Letter</th>
                            <th class="px-5 py-3 font-medium">Issued</th>
                            <th class="px-5 py-3 font-medium">Answer due</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <tr v-if="actions.length === 0">
                            <td colspan="4">
                                <EmptyState
                                    title="Nothing on file"
                                    message="No query, warning or confirmation has been issued to you."
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
                                <p class="flex items-center gap-2 font-medium">
                                    <StatusPill :tone="row.kind_tone">
                                        {{ row.kind_label }}
                                    </StatusPill>
                                    {{ row.title }}
                                </p>
                            </td>
                            <td
                                class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                            >
                                {{ dateTime(row.created_at) }}
                            </td>
                            <td
                                class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                            >
                                {{
                                    row.response_due_on
                                        ? fullDate(row.response_due_on)
                                        : '-'
                                }}
                            </td>
                            <td class="px-5 py-3.5">
                                <StatusPill :tone="row.state_tone" dot>
                                    {{ row.state_label }}
                                </StatusPill>
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
            :open="reading !== null"
            width="lg"
            :title="reading?.title ?? ''"
            :subtitle="
                reading
                    ? `${reading.kind_label} from ${reading.issued_by ?? 'HR'}, ${dateTime(reading.created_at)}`
                    : ''
            "
            @close="reading = null"
        >
            <div v-if="reading" class="space-y-5">
                <p v-if="reading.offence" class="text-[13px] text-muted">
                    Under the register entry
                    <span class="font-medium">{{ reading.offence }}</span>
                </p>

                <div class="rounded-xl bg-sunken/50 p-4">
                    <p
                        class="text-[13.5px] leading-relaxed whitespace-pre-line"
                    >
                        {{ reading.body }}
                    </p>
                </div>

                <template v-if="reading.expects_response">
                    <div
                        v-if="reading.response"
                        class="space-y-1 border-t border-line-soft pt-4"
                    >
                        <p class="text-[12px] text-faint">
                            Your answer, {{ dateTime(reading.responded_at) }}
                        </p>
                        <p
                            class="text-[13.5px] leading-relaxed whitespace-pre-line"
                        >
                            {{ reading.response }}
                        </p>
                    </div>

                    <TextareaField
                        v-else
                        v-model="form.response"
                        label="Your answer"
                        required
                        :rows="6"
                        :hint="
                            reading.response_due_on
                                ? `Due by ${fullDate(reading.response_due_on)}. Once sent it cannot be changed.`
                                : 'Once sent it cannot be changed.'
                        "
                        :error="form.errors.response"
                    />
                </template>

                <p
                    v-else-if="reading.acknowledged_at"
                    class="text-[12.5px] text-faint"
                >
                    You marked this as read on
                    {{ dateTime(reading.acknowledged_at) }}.
                </p>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="reading = null">
                    Close
                </AppButton>
                <AppButton
                    v-if="reading?.expects_response && !reading.response"
                    :loading="form.processing"
                    @click="answer"
                >
                    Send answer
                </AppButton>
                <AppButton
                    v-if="
                        reading &&
                        !reading.expects_response &&
                        !reading.acknowledged_at
                    "
                    :loading="busy"
                    @click="acknowledge"
                >
                    I have read this
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
