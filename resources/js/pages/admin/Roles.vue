<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type RoleRow = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    is_system: boolean;
    /** Super admins hold the catalogue implicitly and cannot be narrowed. */
    holds_everything: boolean;
    permissions: string[];
    users_count: number;
};

type CatalogueGroup = {
    group: string;
    permissions: Array<{ value: string; label: string; description: string }>;
};

const props = defineProps<{
    roles: RoleRow[];
    catalogue: CatalogueGroup[];
}>();

const modalOpen = ref(false);
const editing = ref<RoleRow | null>(null);
const deleting = ref<RoleRow | null>(null);
const dropping = ref(false);

const form = useForm({
    name: '',
    description: '',
    permissions: [] as string[],
});

const totalPermissions = computed(() =>
    props.catalogue.reduce((sum, group) => sum + group.permissions.length, 0),
);

// Super admins are shown every box ticked and locked: their permissions are
// not stored, so there is nothing here that could be taken away.
const locked = computed(() => editing.value?.holds_everything === true);

function open(role: RoleRow | null) {
    editing.value = role;

    form.clearErrors();
    form.defaults({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: [...(role?.permissions ?? [])],
    });
    form.reset();
    modalOpen.value = true;
}

function submit() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
            editing.value = null;
        },
    };

    if (editing.value) {
        form.put(`/admin/roles/${editing.value.id}`, done);
    } else {
        form.post('/admin/roles', done);
    }
}

function drop() {
    if (!deleting.value) {
        return;
    }

    dropping.value = true;

    router.delete(`/admin/roles/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            dropping.value = false;
            deleting.value = null;
        },
    });
}

function toggleAll(group: CatalogueGroup, on: boolean) {
    const values = group.permissions.map((permission) => permission.value);

    form.permissions = on
        ? [...new Set([...form.permissions, ...values])]
        : form.permissions.filter((held) => !values.includes(held));
}

function groupIsFull(group: CatalogueGroup): boolean {
    return group.permissions.every((permission) =>
        form.permissions.includes(permission.value),
    );
}

function labelFor(value: string): string {
    for (const group of props.catalogue) {
        const found = group.permissions.find(
            (permission) => permission.value === value,
        );

        if (found) {
            return found.label;
        }
    }

    return value;
}
</script>

<template>
    <Head title="Roles" />

    <AppLayout
        heading="Roles"
        lede="What each role may do. Assign a role to somebody from the staff page."
    >
        <div class="space-y-5">
            <div class="flex justify-end">
                <AppButton @click="open(null)">Add a role</AppButton>
            </div>

            <Panel
                title="Roles"
                :subtitle="`${roles.length} role(s). Super admins hold every permission and cannot be narrowed.`"
            >
                <div class="grid gap-4 lg:grid-cols-2">
                    <article
                        v-for="role in roles"
                        :key="role.id"
                        class="rounded-2xl border border-line bg-panel p-5 shadow-panel"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p
                                    class="truncate font-display text-[15px] font-semibold tracking-tight"
                                >
                                    {{ role.name }}
                                </p>
                                <p
                                    v-if="role.description"
                                    class="mt-0.5 text-[12.5px] text-muted"
                                >
                                    {{ role.description }}
                                </p>
                            </div>
                            <StatusPill
                                :tone="role.is_system ? 'brass' : 'neutral'"
                            >
                                {{ role.is_system ? 'System' : 'Custom' }}
                            </StatusPill>
                        </div>

                        <div class="mt-4 flex items-baseline gap-4">
                            <span class="text-[12.5px] text-muted">
                                <span
                                    class="tabular font-display text-lg font-semibold text-text"
                                >
                                    {{ role.users_count }}
                                </span>
                                on this role
                            </span>
                            <span class="text-[12.5px] text-muted">
                                <span
                                    class="tabular font-display text-lg font-semibold text-text"
                                >
                                    {{ role.permissions.length }}
                                </span>
                                of {{ totalPermissions }} permissions
                            </span>
                        </div>

                        <div
                            v-if="role.holds_everything"
                            class="mt-3 rounded-xl bg-brass-soft px-3 py-2 text-[12.5px] text-brass"
                        >
                            Holds every permission, including any added later.
                        </div>
                        <div
                            v-else-if="role.permissions.length"
                            class="mt-3 flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="permission in role.permissions"
                                :key="permission"
                                class="rounded-lg bg-line-soft px-2 py-1 text-[11.5px] text-muted"
                            >
                                {{ labelFor(permission) }}
                            </span>
                        </div>
                        <p v-else class="mt-3 text-[12.5px] text-faint">
                            May sign in and raise their own requests, nothing
                            more.
                        </p>

                        <div class="mt-4 flex items-center gap-2">
                            <AppButton
                                variant="ghost"
                                size="sm"
                                @click="open(role)"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                v-if="!role.is_system"
                                variant="ghost"
                                size="sm"
                                @click="deleting = role"
                            >
                                Delete
                            </AppButton>
                        </div>
                    </article>
                </div>
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            width="lg"
            :title="editing ? `Edit ${editing.name}` : 'Add a role'"
            subtitle="Tick what this role may do. Anything left unticked is hidden from the people who hold it."
            @close="modalOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <TextField
                    v-model="form.name"
                    label="Name"
                    required
                    placeholder="Department head"
                    :error="form.errors.name"
                />

                <TextField
                    v-model="form.description"
                    label="Description"
                    placeholder="Shown on this page to explain the role."
                    :error="form.errors.description"
                />

                <p
                    v-if="locked"
                    class="rounded-xl bg-brass-soft px-3.5 py-2.5 text-[13px] text-brass"
                >
                    Super admins hold every permission, including any added in
                    future. That cannot be narrowed here — it is what stops the
                    system being locked out of itself.
                </p>

                <div
                    v-for="group in catalogue"
                    :key="group.group"
                    class="space-y-2.5"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="text-[13px] font-medium text-muted">
                            {{ group.group }}
                        </h3>
                        <button
                            v-if="!locked"
                            type="button"
                            class="text-[12.5px] text-faint transition-colors hover:text-text"
                            @click="toggleAll(group, !groupIsFull(group))"
                        >
                            {{ groupIsFull(group) ? 'Clear' : 'Select all' }}
                        </button>
                    </div>

                    <label
                        v-for="permission in group.permissions"
                        :key="permission.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border border-line bg-panel-raised px-3.5 py-3 transition-colors hover:border-line"
                    >
                        <input
                            v-model="form.permissions"
                            type="checkbox"
                            :value="permission.value"
                            :checked="locked || undefined"
                            :disabled="locked"
                            class="mt-0.5 size-4 rounded border-line text-brand focus:ring-brand/30 disabled:opacity-50"
                        />
                        <span class="min-w-0">
                            <span class="block text-[13.5px]">
                                {{ permission.label }}
                            </span>
                            <span class="block text-[12px] text-faint">
                                {{ permission.description }}
                            </span>
                        </span>
                    </label>
                </div>

                <p
                    v-if="form.errors.permissions"
                    class="text-[13px] text-alert"
                >
                    {{ form.errors.permissions }}
                </p>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Add role' }}
                </AppButton>
            </template>
        </ModalShell>

        <ModalShell
            :open="deleting !== null"
            width="md"
            title="Delete this role?"
            :subtitle="deleting?.name"
            @close="deleting = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                The role and its permissions are removed. Anyone still holding
                it has to be moved to another role first.
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="deleting = null">
                    Keep it
                </AppButton>
                <AppButton variant="danger" :loading="dropping" @click="drop">
                    Delete
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
