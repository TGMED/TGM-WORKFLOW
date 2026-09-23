<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
import { dateTime } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';
type Option = { value: string | number; label: string };

type AssetRow = {
    id: number;
    tag: string;
    name: string;
    asset_category_id: number;
    category: string;
    serial_number: string | null;
    location_id: number | null;
    location: string | null;
    spot: string | null;
    status: string;
    status_label: string;
    status_tone: Tone;
    condition: string;
    condition_label: string;
    purchased_on: string | null;
    purchase_cost: string | null;
    notes: string | null;
    assignee: string | null;
    assigned_at: string | null;
    history: Array<{
        user: string;
        assigned_at: string;
        returned_at: string | null;
        note: string | null;
    }>;
};

type Category = {
    id: number;
    name: string;
    description: string | null;
    assets_count: number;
};

const props = defineProps<{
    assets: {
        data: AssetRow[];
        per_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { status: string; category: number | null; search: string };
    counts: Record<string, number>;
    categories: Category[];
    locations: Option[];
    people: Option[];
    statuses: Option[];
    all_statuses: Option[];
    conditions: Option[];
}>();

const status = ref(props.filters.status);
const category = ref<number | null>(props.filters.category);
const search = ref(props.filters.search);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function reload() {
    router.get(
        '/admin/assets',
        {
            status: status.value,
            category: category.value ?? '',
            search: search.value,
            per_page: currentPerPage(),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([status, category], reload);
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 350);
});

const categoryOptions = computed(() =>
    props.categories.map((c) => ({ value: c.id, label: c.name })),
);

const editing = ref<AssetRow | null>(null);
const editorOpen = ref(false);

const form = useForm({
    tag: '',
    name: '',
    asset_category_id: null as number | null,
    serial_number: '',
    location_id: null as number | null,
    spot: '',
    status: 'available',
    condition: 'good',
    purchased_on: '',
    purchase_cost: '',
    notes: '',
    assigned_user_id: null as number | null,
});

// Handing a new asset to somebody makes it assigned, which only an available
// asset can be.
watch(
    () => form.assigned_user_id,
    (userId) => {
        if (userId !== null) {
            form.status = 'available';
        }
    },
);

function openNew() {
    editing.value = null;
    form.defaults({
        tag: '',
        name: '',
        asset_category_id: props.categories[0]?.id ?? null,
        serial_number: '',
        location_id: null,
        spot: '',
        status: 'available',
        condition: 'good',
        purchased_on: '',
        purchase_cost: '',
        notes: '',
        assigned_user_id: null,
    });
    form.reset();
    form.clearErrors();
    editorOpen.value = true;
}

function openEdit(row: AssetRow) {
    editing.value = row;
    form.defaults({
        tag: row.tag,
        name: row.name,
        asset_category_id: row.asset_category_id,
        serial_number: row.serial_number ?? '',
        location_id: row.location_id,
        spot: row.spot ?? '',
        // An asset somebody holds reads "available" on the form: the form
        // only ever takes it out of use.
        status: row.status === 'assigned' ? 'available' : row.status,
        condition: row.condition,
        purchased_on: row.purchased_on ?? '',
        purchase_cost: row.purchase_cost ?? '',
        notes: row.notes ?? '',
        assigned_user_id: null,
    });
    form.reset();
    form.clearErrors();
    assignForm.reset();
    assignForm.clearErrors();
    editorOpen.value = true;
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editorOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(`/admin/assets/${editing.value.id}`, options);
    } else {
        form.post('/admin/assets', options);
    }
}

const assignForm = useForm({ user_id: null as number | null, note: '' });

function assign() {
    if (!editing.value) {
        return;
    }

    assignForm.post(`/admin/assets/${editing.value.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => {
            editorOpen.value = false;
        },
    });
}

function takeBack() {
    if (!editing.value) {
        return;
    }

    router.post(
        `/admin/assets/${editing.value.id}/return`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                editorOpen.value = false;
            },
        },
    );
}

const categoriesOpen = ref(false);
const categoryForm = useForm({ name: '', description: '' });
const editingCategory = ref<Category | null>(null);

function editCategory(row: Category | null) {
    editingCategory.value = row;
    categoryForm.defaults({
        name: row?.name ?? '',
        description: row?.description ?? '',
    });
    categoryForm.reset();
    categoryForm.clearErrors();
}

function saveCategory() {
    const options = {
        preserveScroll: true,
        onSuccess: () => editCategory(null),
    };

    if (editingCategory.value) {
        categoryForm.put(
            `/admin/asset-categories/${editingCategory.value.id}`,
            options,
        );
    } else {
        categoryForm.post('/admin/asset-categories', options);
    }
}

function removeCategory(row: Category) {
    router.delete(`/admin/asset-categories/${row.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Assets" />

    <AppLayout
        heading="Assets"
        lede="What the company owns, where it is kept, and who has it."
    >
        <template #toolbar>
            <div class="flex gap-2">
                <AppButton
                    size="sm"
                    variant="ghost"
                    @click="
                        editCategory(null);
                        categoriesOpen = true;
                    "
                >
                    Categories
                </AppButton>
                <AppButton
                    size="sm"
                    :disabled="categories.length === 0"
                    :title="
                        categories.length === 0
                            ? 'Add a category before adding an asset'
                            : undefined
                    "
                    @click="openNew()"
                >
                    Add an asset
                </AppButton>
            </div>
        </template>

        <div class="space-y-6">
            <div
                v-if="categories.length === 0"
                role="alert"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brass/30 bg-brass-soft px-4 py-3 text-[13px] leading-snug text-brass"
            >
                <p>
                    <span class="font-semibold">No categories yet.</span>
                    Every asset is filed under one, so assets cannot be added
                    until there is at least one category.
                </p>
                <AppButton
                    size="sm"
                    @click="
                        editCategory(null);
                        categoriesOpen = true;
                    "
                >
                    Add a category
                </AppButton>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Available"
                    :value="counts.available ?? 0"
                    tone="signal"
                />
                <StatTile label="Assigned" :value="counts.assigned ?? 0" />
                <StatTile
                    label="In repair"
                    :value="counts.in_repair ?? 0"
                    tone="brass"
                />
                <StatTile label="Retired" :value="counts.retired ?? 0" />
            </div>

            <Panel flush>
                <template #action>
                    <div class="flex flex-wrap items-center gap-2">
                        <TextField
                            v-model="search"
                            placeholder="Tag, name or serial"
                        />
                        <SelectField
                            v-model="status"
                            :options="[
                                { value: '', label: 'Every status' },
                                ...all_statuses,
                            ]"
                        />
                        <SelectField
                            v-model="category"
                            :options="categoryOptions"
                        >
                            <option :value="null">Every category</option>
                        </SelectField>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Asset</th>
                                <th class="px-5 py-3 font-medium">Category</th>
                                <th class="px-5 py-3 font-medium">Kept at</th>
                                <th class="px-5 py-3 font-medium">With</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="assets.data.length === 0">
                                <td colspan="5">
                                    <EmptyState
                                        :title="
                                            categories.length === 0
                                                ? 'Add a category first'
                                                : 'No assets'
                                        "
                                        :message="
                                            categories.length === 0
                                                ? 'Every asset is filed under a category, such as laptops or vehicles.'
                                                : 'Nothing matches this filter.'
                                        "
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in assets.data"
                                :key="row.id"
                                class="cursor-pointer transition-colors hover:bg-sunken/40"
                                @click="openEdit(row)"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ row.name }}</p>
                                    <p class="text-[12px] text-faint">
                                        {{ row.tag }}
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
                                    {{ row.assignee ?? '-' }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill :tone="row.status_tone" dot>
                                        {{ row.status_label }}
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :links="assets.links"
                    :per-page="assets.per_page"
                    :from="assets.from"
                    :to="assets.to"
                    :total="assets.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="editorOpen"
            width="lg"
            :title="
                editing ? `${editing.tag} · ${editing.name}` : 'Add an asset'
            "
            :subtitle="
                editing?.assignee
                    ? `With ${editing.assignee} since ${dateTime(editing.assigned_at)}`
                    : undefined
            "
            @close="editorOpen = false"
        >
            <div class="space-y-6">
                <div
                    v-if="editing && editing.status !== 'retired'"
                    class="space-y-3 rounded-xl bg-sunken/50 p-4"
                >
                    <p class="text-[13px] font-medium">
                        {{
                            editing.assignee
                                ? 'Hand it to somebody else, or take it back'
                                : 'Hand it to somebody'
                        }}
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <SelectField
                            v-model="assignForm.user_id"
                            :options="people"
                            :error="assignForm.errors.user_id"
                        >
                            <option :value="null" disabled>
                                Pick somebody
                            </option>
                        </SelectField>
                        <TextField
                            v-model="assignForm.note"
                            placeholder="Note, such as the charger went too"
                            :error="assignForm.errors.note"
                        />
                    </div>
                    <div class="flex gap-2">
                        <AppButton
                            size="sm"
                            :disabled="!assignForm.user_id"
                            :loading="assignForm.processing"
                            @click="assign"
                        >
                            Assign
                        </AppButton>
                        <AppButton
                            v-if="editing.assignee"
                            size="sm"
                            variant="ghost"
                            @click="takeBack"
                        >
                            Take it back
                        </AppButton>
                    </div>
                </div>

                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                    <TextField
                        v-model="form.tag"
                        label="Tag"
                        required
                        :error="form.errors.tag"
                    />
                    <TextField
                        v-model="form.name"
                        label="Name"
                        required
                        :error="form.errors.name"
                    />
                    <SelectField
                        v-model="form.asset_category_id"
                        label="Category"
                        required
                        :options="categoryOptions"
                        :error="form.errors.asset_category_id"
                    />
                    <TextField
                        v-model="form.serial_number"
                        label="Serial number"
                        :error="form.errors.serial_number"
                    />
                    <SelectField
                        v-model="form.location_id"
                        label="Site"
                        :options="locations"
                        :error="form.errors.location_id"
                    >
                        <option :value="null">No site</option>
                    </SelectField>
                    <TextField
                        v-model="form.spot"
                        label="Where on site"
                        placeholder="Room, desk or store"
                        :error="form.errors.spot"
                    />
                    <SelectField
                        v-model="form.status"
                        label="Status"
                        required
                        :options="statuses"
                        :disabled="!editing && form.assigned_user_id !== null"
                        :hint="
                            editing?.assignee
                                ? 'In repair or retired takes it back from them.'
                                : !editing && form.assigned_user_id !== null
                                  ? 'Marked assigned once it is handed over.'
                                  : undefined
                        "
                        :error="form.errors.status"
                    />
                    <SelectField
                        v-model="form.condition"
                        label="Condition"
                        required
                        :options="conditions"
                        :error="form.errors.condition"
                    />
                    <div v-if="!editing" class="sm:col-span-2">
                        <SelectField
                            v-model="form.assigned_user_id"
                            label="Hand it to"
                            :options="people"
                            hint="Optional. Leave it unassigned to keep it in store."
                            :error="form.errors.assigned_user_id"
                        >
                            <option :value="null">Nobody yet</option>
                        </SelectField>
                    </div>
                    <TextField
                        v-model="form.purchased_on"
                        label="Bought on"
                        type="date"
                        :error="form.errors.purchased_on"
                    />
                    <TextField
                        v-model="form.purchase_cost"
                        label="Cost (NGN)"
                        type="number"
                        min="0"
                        step="0.01"
                        :error="form.errors.purchase_cost"
                    />
                    <div class="sm:col-span-2">
                        <TextareaField
                            v-model="form.notes"
                            label="Notes"
                            :error="form.errors.notes"
                        />
                    </div>
                </form>

                <div v-if="editing && editing.history.length" class="space-y-2">
                    <p class="text-[13px] font-medium">Who has had it</p>
                    <ul class="space-y-1 text-[12.5px] text-muted">
                        <li
                            v-for="(spell, index) in editing.history"
                            :key="index"
                        >
                            <span class="font-medium text-text">
                                {{ spell.user }}
                            </span>
                            from {{ dateTime(spell.assigned_at) }}
                            {{
                                spell.returned_at
                                    ? `to ${dateTime(spell.returned_at)}`
                                    : '(still has it)'
                            }}
                            <template v-if="spell.note">
                                · {{ spell.note }}
                            </template>
                        </li>
                    </ul>
                </div>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="editorOpen = false">
                    Close
                </AppButton>
                <AppButton :loading="form.processing" @click="save">
                    {{ editing ? 'Save changes' : 'Add to the register' }}
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="categoriesOpen"
            width="lg"
            title="Asset categories"
            @close="categoriesOpen = false"
        >
            <div class="space-y-5">
                <ul class="divide-y divide-line-soft text-[13.5px]">
                    <li v-if="categories.length === 0" class="py-2 text-faint">
                        No categories yet.
                    </li>
                    <li
                        v-for="row in categories"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <div>
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-[12px] text-faint">
                                {{ row.assets_count }} assets
                                <template v-if="row.description">
                                    · {{ row.description }}
                                </template>
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="editCategory(row)"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                v-if="row.assets_count === 0"
                                size="sm"
                                variant="ghost"
                                @click="removeCategory(row)"
                            >
                                Remove
                            </AppButton>
                        </div>
                    </li>
                </ul>

                <form
                    class="space-y-3 border-t border-line-soft pt-4"
                    @submit.prevent="saveCategory"
                >
                    <p class="text-[13px] font-medium">
                        {{
                            editingCategory
                                ? `Edit ${editingCategory.name}`
                                : 'Add a category'
                        }}
                    </p>
                    <TextField
                        v-model="categoryForm.name"
                        label="Name"
                        required
                        :error="categoryForm.errors.name"
                    />
                    <TextField
                        v-model="categoryForm.description"
                        label="Description"
                        :error="categoryForm.errors.description"
                    />
                    <div class="flex gap-2">
                        <AppButton
                            size="sm"
                            type="submit"
                            :loading="categoryForm.processing"
                        >
                            {{ editingCategory ? 'Save' : 'Add' }}
                        </AppButton>
                        <AppButton
                            v-if="editingCategory"
                            size="sm"
                            variant="ghost"
                            @click="editCategory(null)"
                        >
                            Cancel
                        </AppButton>
                    </div>
                </form>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="categoriesOpen = false">
                    Done
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
