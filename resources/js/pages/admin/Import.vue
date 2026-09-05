<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    DuplicateOption,
    ImportDetail,
    ImportResult,
    ImportDuplicates,
} from '@/types';

const props = defineProps<{
    sheet: ImportDetail;
    conventions: string[];
    duplicate_options: DuplicateOption[];
    result: ImportResult | null;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const fileName = ref<string | null>(null);

const form = useForm({
    file: null as File | null,
    duplicates: 'update' as ImportDuplicates,
    commit: false as boolean,
});

const templateUrl = computed(
    () => `/admin/imports/${props.sheet.key}/template`,
);
const referenceUrl = computed(
    () => `/admin/imports/${props.sheet.key}/reference`,
);

const duplicateOptions = computed(() =>
    props.duplicate_options.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);

const chosenDuplicate = computed(() =>
    props.duplicate_options.find((option) => option.value === form.duplicates),
);

// A file has to be checked before it can be imported. The check writes
// nothing and reports exactly what committing would do, so there is no
// reason to let anybody skip it — and the button below stays shut until
// a check has come back on the file now in the picker.
const checkedFile = ref<string | null>(null);

const readyToImport = computed(
    () =>
        form.file !== null &&
        checkedFile.value === fileName.value &&
        props.result !== null &&
        !props.result.committed,
);

function onFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    form.file = file;
    fileName.value = file?.name ?? null;
    checkedFile.value = null;
    form.clearErrors();
}

function submit(commit: boolean) {
    form.commit = commit;

    form.post(`/admin/imports/${props.sheet.key}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            checkedFile.value = commit ? null : fileName.value;

            if (commit) {
                reset();
            }
        },
    });
}

function reset() {
    form.file = null;
    fileName.value = null;
    checkedFile.value = null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

const requiredColumns = computed(() =>
    props.sheet.columns.filter((column) => column.required),
);
</script>

<template>
    <Head :title="`Import ${sheet.label.toLowerCase()}`" />

    <AppLayout
        :heading="`Import ${sheet.label.toLowerCase()}`"
        :lede="sheet.description"
    >
        <div class="space-y-5">
            <Link
                href="/admin/imports"
                class="inline-flex items-center gap-1.5 text-[13px] text-muted transition-colors hover:text-text"
            >
                <svg
                    class="size-3.5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m15 6-6 6 6 6" />
                </svg>
                All imports
            </Link>

            <Panel
                eyebrow="Step one"
                title="Start from the template"
                subtitle="The template carries the header row and one example row. The reference says what every column will accept."
            >
                <div class="flex flex-wrap gap-2.5">
                    <a :href="templateUrl" download>
                        <AppButton variant="primary" size="sm">
                            Download template
                        </AppButton>
                    </a>
                    <a :href="referenceUrl" download>
                        <AppButton variant="secondary" size="sm">
                            Download reference
                        </AppButton>
                    </a>
                </div>

                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="eyebrow">Rows are matched on</dt>
                        <dd class="mt-1 text-[13px] leading-relaxed text-muted">
                            {{ sheet.matched_on }}.
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">Columns you must give</dt>
                        <dd class="mt-1.5 flex flex-wrap gap-1.5">
                            <StatusPill
                                v-for="column in requiredColumns"
                                :key="column.name"
                                tone="brass"
                            >
                                {{ column.name }}
                            </StatusPill>
                            <span
                                v-if="requiredColumns.length === 0"
                                class="text-[13px] text-muted"
                            >
                                None on their own — see the reference.
                            </span>
                        </dd>
                    </div>
                </dl>

                <ul
                    v-if="sheet.notes.length > 0"
                    class="mt-5 space-y-2.5 border-t border-line-soft pt-5"
                >
                    <li
                        v-for="note in sheet.notes"
                        :key="note"
                        class="flex gap-2.5 text-[13px] leading-relaxed text-muted"
                    >
                        <span
                            class="mt-[7px] size-1 shrink-0 rounded-full bg-brass"
                            aria-hidden="true"
                        />
                        {{ note }}
                    </li>
                </ul>
            </Panel>

            <Panel
                eyebrow="Step two"
                title="Check the file, then import it"
                subtitle="Checking writes nothing. It runs the whole file exactly as an import would and then throws the result away, so what it reports is what importing will do."
            >
                <div class="space-y-4">
                    <div>
                        <label
                            class="flex items-center gap-1 text-[13px] font-medium text-muted"
                            for="import-file"
                        >
                            CSV file
                            <span class="text-alert" aria-hidden="true">*</span>
                        </label>

                        <input
                            id="import-file"
                            ref="fileInput"
                            type="file"
                            accept=".csv,text/csv"
                            class="mt-1.5 block w-full cursor-pointer rounded-xl border border-line bg-panel-raised text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-line-soft file:px-4 file:py-3 file:text-[13px] file:font-medium file:text-text hover:border-faint"
                            @change="onFile"
                        />

                        <p
                            v-if="form.errors.file"
                            class="mt-1.5 text-[12.5px] text-alert"
                        >
                            {{ form.errors.file }}
                        </p>
                    </div>

                    <div>
                        <SelectField
                            v-model="form.duplicates"
                            label="When a row already exists"
                            :options="duplicateOptions"
                            :error="form.errors.duplicates"
                        />
                        <p
                            v-if="chosenDuplicate"
                            class="mt-1.5 text-[12.5px] text-faint"
                        >
                            {{ chosenDuplicate.description }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5 pt-1">
                        <AppButton
                            variant="secondary"
                            :disabled="form.file === null"
                            :loading="form.processing && !form.commit"
                            @click="submit(false)"
                        >
                            Check the file
                        </AppButton>

                        <AppButton
                            variant="primary"
                            :disabled="!readyToImport"
                            :loading="form.processing && form.commit"
                            @click="submit(true)"
                        >
                            Import
                        </AppButton>

                        <p
                            v-if="form.file !== null && !readyToImport"
                            class="text-[12.5px] text-faint"
                        >
                            Check the file before importing it.
                        </p>
                    </div>
                </div>
            </Panel>

            <Panel
                v-if="result"
                :eyebrow="
                    result.committed ? 'Imported' : 'Checked, nothing written'
                "
                :title="result.summary"
                :subtitle="
                    result.committed
                        ? 'The records below are on file now.'
                        : 'Nothing has been written. Import the file to make these changes.'
                "
            >
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div
                        v-for="tile in [
                            {
                                label: 'Created',
                                value: result.created,
                                tone: 'signal',
                            },
                            {
                                label: 'Updated',
                                value: result.updated,
                                tone: 'beacon',
                            },
                            {
                                label: 'Skipped',
                                value: result.skipped,
                                tone: 'neutral',
                            },
                            {
                                label: 'Rejected',
                                value: result.failed,
                                tone: 'alert',
                            },
                        ]"
                        :key="tile.label"
                        class="rounded-xl border border-line bg-sunken px-4 py-3"
                    >
                        <p class="eyebrow">{{ tile.label }}</p>
                        <p
                            class="mt-1 font-display text-[22px] font-semibold tabular-nums"
                            :class="
                                tile.value > 0 && tile.tone === 'alert'
                                    ? 'text-alert'
                                    : 'text-text'
                            "
                        >
                            {{ tile.value }}
                        </p>
                    </div>
                </div>

                <div v-if="result.failures.length > 0" class="mt-5">
                    <p class="eyebrow">Rows that were not written</p>
                    <p class="mt-1 text-[13px] text-muted">
                        Row numbers match the line numbers in your spreadsheet.
                        Every other row
                        {{
                            result.committed
                                ? 'was imported'
                                : 'would be imported'
                        }}.
                        <template v-if="result.failed > result.listed_failures">
                            The first {{ result.listed_failures }} of
                            {{ result.failed }} are listed; fix these and check
                            the file again.
                        </template>
                    </p>

                    <ul
                        class="mt-3 divide-y divide-line-soft rounded-xl border border-line"
                    >
                        <li
                            v-for="failure in result.failures"
                            :key="failure.row"
                            class="flex gap-3 px-4 py-3"
                        >
                            <span
                                class="mt-0.5 shrink-0 font-mono text-[12px] text-faint tabular-nums"
                            >
                                Row {{ failure.row }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <ul class="space-y-1">
                                    <li
                                        v-for="message in failure.messages"
                                        :key="message"
                                        class="text-[13px] leading-relaxed text-alert"
                                    >
                                        {{ message }}
                                    </li>
                                </ul>

                                <p
                                    v-if="
                                        Object.keys(failure.values).length > 0
                                    "
                                    class="mt-1 truncate text-[12px] text-faint"
                                >
                                    {{
                                        Object.values(failure.values).join(
                                            ' · ',
                                        )
                                    }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </div>
            </Panel>

            <Panel
                eyebrow="Reference"
                :title="`${sheet.columns.length} columns`"
                subtitle="The same list the reference file carries, so you can fill the template in without leaving the page."
                flush
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="eyebrow px-5 py-3">Column</th>
                                <th class="eyebrow px-5 py-3">Accepts</th>
                                <th class="eyebrow px-5 py-3">Example</th>
                                <th class="eyebrow px-5 py-3">What it is</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="column in sheet.columns"
                                :key="column.name"
                            >
                                <td class="px-5 py-3 align-top">
                                    <span
                                        class="font-mono text-[12.5px] text-text"
                                    >
                                        {{ column.name }}
                                    </span>
                                    <StatusPill
                                        v-if="column.required"
                                        tone="brass"
                                        class="ml-1.5"
                                    >
                                        Required
                                    </StatusPill>
                                </td>
                                <td
                                    class="px-5 py-3 align-top text-[12.5px] text-muted"
                                >
                                    {{ column.accepted }}
                                </td>
                                <td class="px-5 py-3 align-top">
                                    <span
                                        v-if="column.example"
                                        class="font-mono text-[12.5px] text-faint"
                                    >
                                        {{ column.example }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-[12.5px] text-faint"
                                        >—</span
                                    >
                                </td>
                                <td
                                    class="max-w-md px-5 py-3 align-top text-[13px] leading-relaxed text-muted"
                                >
                                    {{ column.description }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </div>
    </AppLayout>
</template>
