<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import PeoplePicker from '@/components/ui/PeoplePicker.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type Person = {
    id: number;
    name: string;
    position: string | null;
    department_id: number | null;
    team_id: number | null;
};

type Staff = Person & { department: string | null };

type TeamRow = {
    id: number;
    name: string;
    lead: { id: number; name: string } | null;
    lead_user_id: number | null;
    member_ids: number[];
    members: Person[];
};

type DepartmentRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_active: boolean;
    head: { id: number; name: string; position: string | null } | null;
    head_user_id: number | null;
    member_ids: number[];
    members: Person[];
    teams: TeamRow[];
};

const props = defineProps<{
    departments: DepartmentRow[];
    staff: Staff[];
    unassigned: number;
}>();

// Departments.

const editing = ref<DepartmentRow | null>(null);
const departmentOpen = ref(false);
const removing = ref<DepartmentRow | null>(null);

const form = useForm({
    name: '',
    description: '',
    is_active: true,
    head_user_id: null as number | null,
    members: [] as number[],
});

function openDepartment(department: DepartmentRow | null) {
    editing.value = department;

    form.clearErrors();
    form.defaults({
        name: department?.name ?? '',
        description: department?.description ?? '',
        is_active: department?.is_active ?? true,
        head_user_id: department?.head_user_id ?? null,
        members: [...(department?.member_ids ?? [])],
    });
    form.reset();

    departmentOpen.value = true;
}

function submitDepartment() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            departmentOpen.value = false;
            editing.value = null;
        },
    };

    if (editing.value) {
        form.put(`/admin/departments/${editing.value.id}`, options);
    } else {
        form.post('/admin/departments', options);
    }
}

// Only somebody already in the department may head it, so the picker offers
// exactly the people currently chosen as members.
const headOptions = computed(() =>
    props.staff
        .filter((person) => form.members.includes(person.id))
        .map((person) => ({
            value: person.id,
            label: person.position
                ? `${person.name} · ${person.position}`
                : person.name,
        })),
);

// Teams.

const teamEditing = ref<TeamRow | null>(null);
const teamDepartment = ref<DepartmentRow | null>(null);
const teamOpen = ref(false);
const disbanding = ref<TeamRow | null>(null);

const teamForm = useForm({
    name: '',
    lead_user_id: null as number | null,
    members: [] as number[],
});

function openTeam(department: DepartmentRow, team: TeamRow | null) {
    teamDepartment.value = department;
    teamEditing.value = team;

    teamForm.clearErrors();
    teamForm.defaults({
        name: team?.name ?? '',
        lead_user_id: team?.lead_user_id ?? null,
        members: [...(team?.member_ids ?? [])],
    });
    teamForm.reset();

    teamOpen.value = true;
}

function submitTeam() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            teamOpen.value = false;
            teamEditing.value = null;
        },
    };

    if (teamEditing.value) {
        teamForm.put(`/admin/teams/${teamEditing.value.id}`, options);
    } else {
        teamForm.post(
            `/admin/departments/${teamDepartment.value?.id}/teams`,
            options,
        );
    }
}

// A team is drawn from its own department, so only that department's people
// are on offer.
const teamCandidates = computed<Staff[]>(() => {
    const department = teamDepartment.value;

    if (!department) {
        return [];
    }

    return props.staff.filter(
        (person) => person.department_id === department.id,
    );
});

const teamLeadOptions = computed(() =>
    teamCandidates.value
        .filter((person) => teamForm.members.includes(person.id))
        .map((person) => ({
            value: person.id,
            label: person.position
                ? `${person.name} · ${person.position}`
                : person.name,
        })),
);

const totals = computed(() => ({
    departments: props.departments.length,
    headed: props.departments.filter((d) => d.head).length,
    teams: props.departments.reduce((sum, d) => sum + d.teams.length, 0),
}));
</script>

<template>
    <Head title="Departments" />

    <AppLayout
        heading="Departments"
        :lede="`${totals.departments} ${totals.departments === 1 ? 'department' : 'departments'} · ${totals.headed} with a head · ${totals.teams} ${totals.teams === 1 ? 'team' : 'teams'}`"
    >
        <template #toolbar>
            <AppButton size="sm" @click="openDepartment(null)">
                <svg
                    class="size-4"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Add department
            </AppButton>
        </template>

        <div class="space-y-5">
            <div
                class="rounded-2xl border border-line bg-panel px-4 py-3.5 text-[13px] text-muted"
            >
                Naming a head of department or a team lead here is what grants
                them the role, and it is the only place that does. That is
                deliberate: the role only means anything alongside the people it
                covers, so both are settled in one go.
            </div>

            <div
                v-if="unassigned > 0"
                class="flex flex-wrap items-center gap-3 rounded-2xl border border-brass/40 bg-brass-soft px-4 py-3.5"
            >
                <svg
                    class="size-5 shrink-0 text-brass"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                >
                    <path d="M12 8.5v4.5M12 16.5h.01" />
                    <circle cx="12" cy="12" r="8.5" />
                </svg>
                <p class="min-w-0 flex-1 text-[13px] text-brass">
                    {{ unassigned }}
                    {{ unassigned === 1 ? 'person is' : 'people are' }}
                    in no department, so nobody sees their numbers.
                </p>
                <Link href="/admin/staff">
                    <AppButton size="sm" variant="secondary">
                        Open the staff list
                    </AppButton>
                </Link>
            </div>

            <div v-if="departments.length" class="stagger space-y-4">
                <article
                    v-for="department in departments"
                    :key="department.id"
                    :class="[
                        'overflow-hidden rounded-2xl border bg-panel shadow-panel',
                        department.is_active
                            ? 'border-line'
                            : 'border-line-soft opacity-70',
                    ]"
                >
                    <header
                        class="flex flex-wrap items-start justify-between gap-4 border-b border-line-soft px-5 py-4"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2
                                    class="truncate font-display text-[17px] font-semibold tracking-tight"
                                >
                                    {{ department.name }}
                                </h2>
                                <StatusPill
                                    v-if="!department.is_active"
                                    tone="neutral"
                                >
                                    Inactive
                                </StatusPill>
                            </div>
                            <p
                                v-if="department.description"
                                class="mt-0.5 text-[13px] text-muted"
                            >
                                {{ department.description }}
                            </p>
                            <p class="mt-1 text-[13px] text-faint">
                                <template v-if="department.head">
                                    Headed by
                                    <span class="text-muted">{{
                                        department.head.name
                                    }}</span>
                                </template>
                                <template v-else>
                                    <span class="text-brass"
                                        >No head named</span
                                    >
                                </template>
                                ·
                                {{ department.members.length }}
                                {{
                                    department.members.length === 1
                                        ? 'person'
                                        : 'people'
                                }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="openTeam(department, null)"
                            >
                                Add team
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="secondary"
                                @click="openDepartment(department)"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="removing = department"
                            >
                                Remove
                            </AppButton>
                        </div>
                    </header>

                    <div
                        v-if="department.teams.length"
                        class="divide-y divide-line-soft"
                    >
                        <div
                            v-for="team in department.teams"
                            :key="team.id"
                            class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5"
                        >
                            <div class="min-w-0">
                                <p class="text-[13.5px] font-medium">
                                    {{ team.name }}
                                </p>
                                <p class="text-[12.5px] text-faint">
                                    <template v-if="team.lead">
                                        Led by {{ team.lead.name }}
                                    </template>
                                    <template v-else>
                                        <span class="text-brass"
                                            >No lead named</span
                                        >
                                    </template>
                                    ·
                                    {{ team.members.length }}
                                    {{
                                        team.members.length === 1
                                            ? 'member'
                                            : 'members'
                                    }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <AppButton
                                    size="sm"
                                    variant="ghost"
                                    @click="openTeam(department, team)"
                                >
                                    Edit
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="ghost"
                                    @click="disbanding = team"
                                >
                                    Disband
                                </AppButton>
                            </div>
                        </div>
                    </div>

                    <p v-else class="px-5 py-4 text-[13px] text-faint">
                        No teams yet. A department does not need any — teams are
                        for when one head is too far from the day to day.
                    </p>
                </article>
            </div>

            <EmptyState
                v-else
                title="No departments yet"
                message="A department is what gives a head somebody to be responsible for, and what the dashboards count against."
            />
        </div>

        <!-- Creating or editing a department. -->
        <ModalShell
            :open="departmentOpen"
            :title="editing ? `Edit ${editing.name}` : 'Add a department'"
            subtitle="Naming a head grants them the role. It needs people to cover, so the two are set together."
            width="xl"
            @close="departmentOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submitDepartment">
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.name"
                        label="Name"
                        required
                        :error="form.errors.name"
                    />
                    <TextField
                        v-model="form.description"
                        label="Description"
                        :error="form.errors.description"
                        hint="Optional. What this part of the company does."
                    />
                </div>

                <PeoplePicker
                    v-model="form.members"
                    label="Who is in this department"
                    :people="staff"
                    :current-department="editing?.name ?? null"
                    :error="
                        form.errors.members ??
                        (form.errors as Record<string, string>)['members.0']
                    "
                    hint="Choosing somebody already in another department moves them here."
                />

                <SelectField
                    v-model="form.head_user_id"
                    label="Head of department"
                    :options="headOptions"
                    :error="form.errors.head_user_id"
                    :hint="
                        headOptions.length
                            ? 'They see their department\'s numbers and decide on its requests.'
                            : 'Choose who is in the department first — a head is one of its own people.'
                    "
                >
                    <option :value="null">No head for now</option>
                </SelectField>

                <label class="flex items-center gap-2.5 text-[13px]">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 rounded border-line text-beacon focus:ring-2 focus:ring-beacon/30"
                    />
                    Active
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton
                        type="button"
                        variant="secondary"
                        @click="departmentOpen = false"
                    >
                        Cancel
                    </AppButton>
                    <AppButton type="submit" :disabled="form.processing">
                        {{ editing ? 'Save changes' : 'Create department' }}
                    </AppButton>
                </div>
            </form>
        </ModalShell>

        <!-- Creating or editing a team. -->
        <ModalShell
            :open="teamOpen"
            :title="
                teamEditing
                    ? `Edit ${teamEditing.name}`
                    : `Add a team to ${teamDepartment?.name}`
            "
            subtitle="A team is drawn from its own department, and its lead is one of its own members."
            width="xl"
            @close="teamOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submitTeam">
                <TextField
                    v-model="teamForm.name"
                    label="Name"
                    required
                    :error="teamForm.errors.name"
                />

                <PeoplePicker
                    v-model="teamForm.members"
                    label="Who is on this team"
                    :people="teamCandidates"
                    :current-department="teamDepartment?.name ?? null"
                    :error="
                        teamForm.errors.members ??
                        (teamForm.errors as Record<string, string>)['members.0']
                    "
                    empty-message="Nobody is in this department yet. Add people to it first."
                />

                <SelectField
                    v-model="teamForm.lead_user_id"
                    label="Team lead"
                    :options="teamLeadOptions"
                    :error="teamForm.errors.lead_user_id"
                    :hint="
                        teamLeadOptions.length
                            ? 'Their team\'s requests come to them first, before the head of department.'
                            : 'Choose who is on the team first — a lead is one of its own members.'
                    "
                >
                    <option :value="null">No lead for now</option>
                </SelectField>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton
                        type="button"
                        variant="secondary"
                        @click="teamOpen = false"
                    >
                        Cancel
                    </AppButton>
                    <AppButton type="submit" :disabled="teamForm.processing">
                        {{ teamEditing ? 'Save changes' : 'Create team' }}
                    </AppButton>
                </div>
            </form>
        </ModalShell>

        <!-- Removing a department. -->
        <ModalShell
            :open="removing !== null"
            :title="`Remove ${removing?.name}?`"
            subtitle="Its teams go with it. The people stay, in no department, until they are placed again."
            width="md"
            @close="removing = null"
        >
            <div class="flex justify-end gap-2">
                <AppButton variant="secondary" @click="removing = null">
                    Cancel
                </AppButton>
                <Link
                    v-if="removing"
                    :href="`/admin/departments/${removing.id}`"
                    method="delete"
                    as="button"
                    preserve-scroll
                    @success="removing = null"
                >
                    <AppButton variant="secondary">Remove it</AppButton>
                </Link>
            </div>
        </ModalShell>

        <!-- Disbanding a team. -->
        <ModalShell
            :open="disbanding !== null"
            :title="`Disband ${disbanding?.name}?`"
            subtitle="Its members stay in the department. The lead keeps the role only if they still lead something else."
            width="md"
            @close="disbanding = null"
        >
            <div class="flex justify-end gap-2">
                <AppButton variant="secondary" @click="disbanding = null">
                    Cancel
                </AppButton>
                <Link
                    v-if="disbanding"
                    :href="`/admin/teams/${disbanding.id}`"
                    method="delete"
                    as="button"
                    preserve-scroll
                    @success="disbanding = null"
                >
                    <AppButton variant="secondary">Disband it</AppButton>
                </Link>
            </div>
        </ModalShell>
    </AppLayout>
</template>
