<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';

type PolicyRow = {
    id: number;
    title: string;
    category: string;
    category_label: string;
    version: string | null;
    summary: string | null;
    file_name: string;
    size_label: string;
    effective_from: string;
    effective_label: string;
    in_force: boolean;
    is_active: boolean;
    is_upcoming: boolean;
    uploaded_by: string | null;
    supersedes: { id: number; title: string; version: string | null } | null;
    created_at: string | null;
};

const props = defineProps<{
    policies: PolicyRow[];
    categories: Array<{ value: string; label: string; description: string }>;
    totals: { in_force: number; upcoming: number; retired: number };
}>();

const modalOpen = ref(false);
const retiring = ref<PolicyRow | null>(null);
const busy = ref(false);

// A new version replaces one already in force, so only those can be chosen.
const replaceable = computed(() =>
    props.policies
        .filter((policy) => policy.is_active)
        .map((policy) => ({
            value: policy.id,
            label: policy.version
                ? `${policy.title} (v${policy.version})`
                : policy.title,
        })),
);

const form = useForm({
    title: '',
    category: 'handbook',
    version: '',
    summary: '',
    effective_from: '',
    supersedes_id: null as number | null,
    document: null as File | null,
});

function open() {
    form.clearErrors();
    form.reset();
    modalOpen.value = true;
}

function pickFile(event: Event) {
    const input = event.target as HTMLInputElement;

    form.document = input.files?.[0] ?? null;
}

function submit() {
    form.post('/admin/policies', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    });
}

function retire() {
    if (!retiring.value) {
        return;
    }

    busy.value = true;

    router.patch(
        `/admin/policies/${retiring.value.id}/retire`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                retiring.value = null;
            },
        },
    );
}

function restore(policy: PolicyRow) {
    router.patch(
        `/admin/policies/${policy.id}/restore`,
        {},
        { preserveScroll: true },
    );
}

const pages = usePaginated(() => props.policies);
</script>

<template>
    <Head title="Policy library" />

    <AppLayout
        heading="Policy library"
        lede="What the company publishes, and what staff are held to."
    >
        <template #toolbar>
            <AppButton size="sm" @click="open()">Publish a policy</AppButton>
        </template>

        <div class="space-y-6">
            <div class="stagger grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatTile label="In force" :value="totals.in_force" />
                <StatTile
                    label="Dated ahead"
                    :value="totals.upcoming"
                    :tone="totals.upcoming > 0 ? 'brass' : 'default'"
                    caption="Published, not yet the rule"
                />
                <StatTile
                    label="Retired"
                    :value="totals.retired"
                    caption="Kept on file"
                />
            </div>

            <Panel flush title="Everything published">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Policy</th>
                                <th class="px-5 py-3 font-medium">Category</th>
                                <th class="px-5 py-3 font-medium">File</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="policies.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        title="Nothing published yet"
                                        message="Publish the handbook and staff can read it from their own policy page."
                                    >
                                        <template #action>
                                            <AppButton
                                                size="sm"
                                                @click="open()"
                                            >
                                                Publish a policy
                                            </AppButton>
                                        </template>
                                    </EmptyState>
                                </td>
                            </tr>
                            <tr
                                v-for="policy in pages.paged"
                                :key="policy.id"
                                :class="[
                                    'align-top transition-colors hover:bg-sunken/40',
                                    !policy.is_active && 'opacity-70',
                                ]"
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
                                        class="max-w-[24rem] text-[12.5px] leading-relaxed text-faint"
                                    >
                                        {{ policy.summary }}
                                    </p>
                                    <p
                                        v-if="policy.supersedes"
                                        class="mt-0.5 text-[12px] text-faint"
                                    >
                                        Replaces
                                        {{ policy.supersedes.title }}
                                        <template
                                            v-if="policy.supersedes.version"
                                        >
                                            (v{{ policy.supersedes.version }})
                                        </template>
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-muted">
                                    {{ policy.category_label }}
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] text-muted"
                                >
                                    <p class="max-w-[14rem] truncate">
                                        {{ policy.file_name }}
                                    </p>
                                    <p class="text-faint">
                                        {{ policy.size_label }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill
                                        :tone="
                                            policy.in_force
                                                ? 'signal'
                                                : policy.is_upcoming
                                                  ? 'brass'
                                                  : 'neutral'
                                        "
                                    >
                                        {{
                                            policy.in_force
                                                ? 'In force'
                                                : policy.is_upcoming
                                                  ? 'From ' +
                                                    policy.effective_label
                                                  : 'Retired'
                                        }}
                                    </StatusPill>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <a
                                            :href="`/admin/policies/${policy.id}/file`"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-[13px] font-medium text-muted hover:text-text"
                                        >
                                            Open
                                        </a>
                                        <AppButton
                                            v-if="policy.is_active"
                                            size="sm"
                                            variant="ghost"
                                            @click="retiring = policy"
                                        >
                                            Retire
                                        </AppButton>
                                        <AppButton
                                            v-else
                                            size="sm"
                                            variant="secondary"
                                            @click="restore(policy)"
                                        >
                                            Put back
                                        </AppButton>
                                    </div>
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
        </div>

        <ModalShell
            :open="modalOpen"
            title="Publish a policy"
            subtitle="Replacing one already in force retires it in the same breath, so there is only ever one current rule."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.title"
                    label="Title"
                    required
                    :error="form.errors.title"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.category"
                        label="Filed under"
                        :options="
                            categories.map((category) => ({
                                value: category.value,
                                label: category.label,
                            }))
                        "
                        :error="form.errors.category"
                    />
                    <TextField
                        v-model="form.version"
                        label="Version"
                        placeholder="2.1, Rev C, 2026-A"
                        :error="form.errors.version"
                    />
                </div>

                <TextField
                    v-model="form.effective_from"
                    label="In force from"
                    type="date"
                    required
                    :error="form.errors.effective_from"
                    hint="Dated ahead, it is published but not yet the rule."
                />

                <SelectField
                    v-model="form.supersedes_id"
                    label="Replaces"
                    :options="replaceable"
                    :error="form.errors.supersedes_id"
                >
                    <option :value="null">Nothing, this is new</option>
                </SelectField>

                <div class="space-y-1.5">
                    <label
                        for="policy-summary"
                        class="text-[13px] font-medium text-muted"
                    >
                        Summary
                    </label>
                    <textarea
                        id="policy-summary"
                        v-model="form.summary"
                        rows="3"
                        placeholder="A line or two so staff know what this covers before they open it."
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm text-text transition-all duration-200 ease-out focus:border-beacon focus:ring-4 focus:ring-beacon/15 focus:outline-none"
                    />
                    <p
                        v-if="form.errors.summary"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.summary }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label
                        for="policy-document"
                        class="flex items-center gap-1 text-[13px] font-medium text-muted"
                    >
                        Document
                        <span class="text-alert" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="policy-document"
                        type="file"
                        accept=".pdf,application/pdf"
                        class="w-full rounded-xl border border-line bg-panel-raised px-3.5 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-line-soft file:px-3 file:py-1.5 file:text-[13px] file:font-medium"
                        @change="pickFile"
                    />
                    <p class="text-[12px] text-faint">
                        PDF only, up to 20 MB. It opens in the browser to be
                        read.
                    </p>
                    <p
                        v-if="form.errors.document"
                        class="text-[13px] text-alert"
                    >
                        {{ form.errors.document }}
                    </p>
                </div>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Publish
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="retiring !== null"
            width="md"
            title="Take this out of force?"
            :subtitle="retiring?.title"
            @close="retiring = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                Staff stop seeing it on their policy page. The document stays on
                file, so anyone looking back at what the rule used to say can
                still find it.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="retiring = null">
                    Keep it
                </AppButton>
                <AppButton variant="danger" :loading="busy" @click="retire">
                    Retire
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
