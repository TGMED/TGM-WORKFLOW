<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type Change = {
    field: string;
    label: string;
    from: string | null;
    to: string | null;
};

type AuditRow = {
    id: number;
    event: string;
    event_label: string;
    event_tone: 'signal' | 'brass' | 'alert' | 'neutral';
    type: string;
    type_label: string;
    subject: string;
    actor: string | null;
    actor_id: number | null;
    ip_address: string | null;
    url: string | null;
    created_at: string | null;
    changes: Change[];
};

const props = defineProps<{
    audits: {
        data: AuditRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        event: string | null;
        type: string | null;
        user: number | null;
        from: string | null;
        to: string | null;
    };
    types: Array<{ value: string; label: string }>;
    events: Array<{ value: string; label: string }>;
    actors: Array<{ value: number; label: string }>;
}>();

const event = ref(props.filters.event ?? '');
const type = ref(props.filters.type ?? '');
const actor = ref<number | string>(props.filters.user ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

const expanded = ref<number | null>(null);

function applyFilters() {
    router.get(
        '/admin/audit',
        {
            event: event.value || undefined,
            type: type.value || undefined,
            user: actor.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

watch([event, type, actor, from, to], applyFilters);

/** A change made by a scheduled job or a console command carries no actor. */
function initialsOf(name: string | null): string {
    if (!name) {
        return 'SYS';
    }

    return (
        name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((word) => word[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

function clear() {
    event.value = '';
    type.value = '';
    actor.value = '';
    from.value = '';
    to.value = '';
}
</script>

<template>
    <Head title="Audit trail" />

    <AppLayout
        heading="Audit trail"
        lede="Who changed what, and when. Read-only by design."
    >
        <div class="space-y-5">
            <Panel title="Filter">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <SelectField
                        v-model="event"
                        label="Event"
                        :options="events"
                    >
                        <option value="">Any event</option>
                    </SelectField>
                    <SelectField v-model="type" label="Record" :options="types">
                        <option value="">Any record</option>
                    </SelectField>
                    <SelectField
                        v-model="actor"
                        label="Changed by"
                        :options="actors"
                    >
                        <option value="">Anyone</option>
                    </SelectField>
                    <TextField v-model="from" label="From" type="date" />
                    <TextField v-model="to" label="To" type="date" />
                </div>

                <button
                    type="button"
                    class="mt-3 text-[12.5px] text-faint transition-colors hover:text-text"
                    @click="clear"
                >
                    Clear filters
                </button>
            </Panel>

            <Panel
                title="Changes"
                :subtitle="`${audits.total} entr${audits.total === 1 ? 'y' : 'ies'}`"
                flush
            >
                <EmptyState
                    v-if="audits.data.length === 0"
                    title="Nothing recorded yet"
                    message="Changes to staff, roles, sites, leave types and requests will appear here."
                />

                <div v-else class="divide-y divide-line-soft">
                    <div
                        v-for="row in audits.data"
                        :key="row.id"
                        class="px-5 py-4"
                    >
                        <button
                            type="button"
                            class="flex w-full items-start gap-3 text-left"
                            @click="
                                expanded = expanded === row.id ? null : row.id
                            "
                        >
                            <Avatar
                                :initials="initialsOf(row.actor)"
                                :name="row.actor ?? 'System'"
                                size="sm"
                                class="mt-0.5 shrink-0"
                            />

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <StatusPill :tone="row.event_tone">
                                        {{ row.event_label }}
                                    </StatusPill>
                                    <span
                                        class="text-[13.5px] font-medium text-text"
                                    >
                                        {{ row.type_label }}
                                    </span>
                                    <span class="text-[13.5px] text-muted">
                                        {{ row.subject }}
                                    </span>
                                </div>

                                <p class="mt-1 text-[12.5px] text-faint">
                                    {{ row.actor ?? 'System' }}
                                    <template v-if="row.created_at">
                                        · {{ dateTime(row.created_at) }}
                                    </template>
                                    <template v-if="row.changes.length">
                                        · {{ row.changes.length }} field(s)
                                        changed
                                    </template>
                                </p>
                            </div>

                            <svg
                                class="mt-1 size-4 shrink-0 text-faint transition-transform duration-200"
                                :class="expanded === row.id && 'rotate-180'"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                aria-hidden="true"
                            >
                                <path
                                    d="m6 9 6 6 6-6"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </button>

                        <div v-if="expanded === row.id" class="mt-3 pl-11">
                            <div
                                v-if="row.changes.length"
                                class="overflow-x-auto rounded-xl border border-line"
                            >
                                <table class="w-full text-left text-[12.5px]">
                                    <thead
                                        class="bg-panel-raised text-faint uppercase"
                                    >
                                        <tr>
                                            <th class="px-3.5 py-2 font-medium">
                                                Field
                                            </th>
                                            <th class="px-3.5 py-2 font-medium">
                                                Was
                                            </th>
                                            <th class="px-3.5 py-2 font-medium">
                                                Became
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line-soft">
                                        <tr
                                            v-for="change in row.changes"
                                            :key="change.field"
                                        >
                                            <td class="px-3.5 py-2 text-muted">
                                                {{ change.label }}
                                            </td>
                                            <td class="px-3.5 py-2 text-faint">
                                                {{ change.from ?? '—' }}
                                            </td>
                                            <td class="px-3.5 py-2 text-text">
                                                {{ change.to ?? '—' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="text-[12.5px] text-faint">
                                No field values were recorded for this entry.
                            </p>

                            <p class="mt-2 text-[12px] text-faint">
                                <template v-if="row.ip_address">
                                    From {{ row.ip_address }}
                                </template>
                                <template v-if="row.url">
                                    · {{ row.url }}
                                </template>
                            </p>
                        </div>
                    </div>
                </div>

                <Pagination
                    :links="audits.links"
                    :from="audits.from"
                    :to="audits.to"
                    :total="audits.total"
                />
            </Panel>
        </div>
    </AppLayout>
</template>
