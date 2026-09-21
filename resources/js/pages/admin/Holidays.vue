<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';

type HolidayRow = {
    id: number;
    name: string;
    date: string;
    location_id: number | null;
    location: string | null;
    date_label: string;
    weekday: string;
    past: boolean;
};

const props = defineProps<{
    year: number;
    years: number[];
    holidays: HolidayRow[];
    locations: Array<{ value: number; label: string }>;
}>();

const open = ref(false);
const editing = ref<HolidayRow | null>(null);

const form = useForm<{
    name: string;
    date: string;
    location_id: number | null;
}>({
    name: '',
    date: '',
    location_id: null,
});

function add() {
    editing.value = null;
    form.clearErrors();
    form.defaults({ name: '', date: '', location_id: null });
    form.reset();
    open.value = true;
}

function edit(row: HolidayRow) {
    editing.value = row;
    form.clearErrors();
    form.defaults({
        name: row.name,
        date: row.date,
        location_id: row.location_id,
    });
    form.reset();
    open.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.put(`/admin/holidays/${editing.value.id}`, options);

        return;
    }

    form.post('/admin/holidays', options);
}

function remove(row: HolidayRow) {
    router.delete(`/admin/holidays/${row.id}`, { preserveScroll: true });
}

function showYear(year: number) {
    router.get(
        '/admin/holidays',
        { year },
        { preserveState: true, preserveScroll: true },
    );
}

const pages = usePaginated(() => props.holidays);
</script>

<template>
    <Head title="Public holidays" />

    <AppLayout
        heading="Public holidays"
        lede="Days off for every site, or for one"
    >
        <template #toolbar>
            <AppButton size="sm" @click="add">Add holiday</AppButton>
        </template>

        <Panel
            :title="`${props.year}`"
            subtitle="A holiday comes off the days its sites are expected in on the attendance report, and is never taken from leave. Staff see their next ones coming up beside every page."
            flush
        >
            <template #action>
                <div class="flex flex-wrap items-center gap-1">
                    <AppButton
                        v-for="option in years"
                        :key="option"
                        size="sm"
                        :variant="option === year ? 'secondary' : 'ghost'"
                        @click="showYear(option)"
                    >
                        {{ option }}
                    </AppButton>
                </div>
            </template>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13.5px]">
                    <thead>
                        <tr class="border-b border-line-soft">
                            <th class="eyebrow px-5 py-3 font-medium">Date</th>
                            <th class="eyebrow px-5 py-3 font-medium">
                                Holiday
                            </th>
                            <th class="eyebrow px-5 py-3 font-medium">Where</th>
                            <th class="eyebrow px-5 py-3 font-medium" />
                            <th class="px-5 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <tr v-if="holidays.length === 0">
                            <td colspan="5">
                                <EmptyState
                                    :title="`No holidays in ${year}`"
                                    message="Add the days the company or a site is closed, and they will be taken out of that week."
                                >
                                    <template #action>
                                        <AppButton size="sm" @click="add"
                                            >Add holiday</AppButton
                                        >
                                    </template>
                                </EmptyState>
                            </td>
                        </tr>
                        <tr
                            v-for="row in pages.paged"
                            :key="row.id"
                            class="group"
                            :class="row.past && 'text-muted'"
                        >
                            <td class="px-5 py-3 whitespace-nowrap">
                                <p class="font-medium">{{ row.date_label }}</p>
                                <p class="text-[12px] text-faint">
                                    {{ row.weekday }}
                                </p>
                            </td>
                            <td class="px-5 py-3">{{ row.name }}</td>
                            <td class="px-5 py-3">
                                {{ row.location ?? 'Every site' }}
                            </td>
                            <td class="px-5 py-3">
                                <StatusPill
                                    :tone="row.past ? 'neutral' : 'beacon'"
                                >
                                    {{ row.past ? 'Past' : 'Coming up' }}
                                </StatusPill>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <div
                                    class="inline-flex items-center gap-1 opacity-60 transition-opacity group-hover:opacity-100"
                                >
                                    <AppButton
                                        size="sm"
                                        variant="ghost"
                                        @click="edit(row)"
                                    >
                                        Edit
                                    </AppButton>
                                    <AppButton
                                        size="sm"
                                        variant="ghost"
                                        @click="remove(row)"
                                    >
                                        Remove
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

        <ModalShell
            :open="open"
            :title="editing ? 'Edit holiday' : 'Add a public holiday'"
            subtitle="Nobody it applies to is expected in, whatever their site's week says."
            @close="open = false"
        >
            <form id="holiday" class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.name"
                    label="Holiday"
                    required
                    placeholder="Independence Day"
                    :error="form.errors.name"
                />
                <TextField
                    v-model="form.date"
                    label="Date"
                    type="date"
                    required
                    hint="One day. A holiday that runs over two days is two entries."
                    :error="form.errors.date"
                />
                <SelectField
                    v-model="form.location_id"
                    label="Where"
                    :options="locations"
                    hint="A state or local holiday is kept by the one site that is off."
                    :error="form.errors.location_id"
                >
                    <option :value="null">Every site</option>
                </SelectField>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="open = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="holiday"
                    :loading="form.processing"
                >
                    {{ editing ? 'Save' : 'Add holiday' }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
