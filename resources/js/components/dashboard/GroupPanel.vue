<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import TurnoutBars from '@/components/dashboard/TurnoutBars.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Panel from '@/components/ui/Panel.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import type { GroupMetrics } from '@/types';

const props = defineProps<{
    group: GroupMetrics;
    /** Only somebody who may manage staff gets links into the records. */
    canOpenRecords: boolean;
}>();

// Worst timekeeping first: a list of forty people sorted by name is a list
// nobody reads. The ones who need a word are the ones at the top.
const members = computed(() =>
    [...props.group.members].sort((a, b) => {
        if (b.late_minutes !== a.late_minutes) {
            return b.late_minutes - a.late_minutes;
        }

        return a.name.localeCompare(b.name);
    }),
);

const noun = computed(() =>
    props.group.kind === 'department' ? 'department' : 'team',
);
</script>

<template>
    <section class="space-y-5">
        <div>
            <p class="eyebrow">Your {{ noun }}</p>
            <h2
                class="mt-1 font-display text-[19px] font-semibold tracking-tight"
            >
                {{ group.label }}
            </h2>
            <p class="mt-0.5 text-[13px] text-muted">
                {{ group.headcount }}
                {{ group.headcount === 1 ? 'person' : 'people' }} you are
                responsible for. These are their numbers this month.
            </p>
        </div>

        <EmptyState
            v-if="group.headcount === 0"
            title="Nobody here yet"
            message="Once people are placed in this group, their attendance and requests will appear here."
        />

        <template v-else>
            <div
                class="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
            >
                <StatTile
                    label="In today"
                    :value="`${group.headline.clocked_in_today}/${group.headcount}`"
                    caption="Clocked in"
                />
                <StatTile
                    label="Late today"
                    :value="group.headline.late_today"
                    :tone="group.headline.late_today > 0 ? 'brass' : 'default'"
                />
                <StatTile
                    label="On leave"
                    :value="group.headline.on_leave_today"
                    caption="Today"
                />
                <StatTile
                    label="Open requests"
                    :value="group.headline.open_requests"
                    :tone="
                        group.headline.open_requests > 0 ? 'brass' : 'default'
                    "
                    caption="Waiting on a decision"
                />
                <StatTile
                    label="Punctuality"
                    :value="
                        group.headline.punctuality === null
                            ? '-'
                            : `${group.headline.punctuality}%`
                    "
                    caption="This month"
                />
                <StatTile
                    label="Minutes lost"
                    :value="group.headline.late_minutes_this_month"
                    caption="To lateness this month"
                />
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <Panel
                    class="xl:col-span-2"
                    title="Turnout"
                    :subtitle="`The last fortnight across your ${noun}`"
                >
                    <TurnoutBars :days="group.trend" />
                </Panel>

                <Panel
                    title="Needs a word"
                    subtitle="Most minutes lost to lateness this month"
                    flush
                >
                    <div
                        v-if="members.some((m) => m.late_minutes > 0)"
                        class="divide-y divide-line-soft"
                    >
                        <div
                            v-for="member in members
                                .filter((m) => m.late_minutes > 0)
                                .slice(0, 6)"
                            :key="member.id"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-medium">
                                    {{ member.name }}
                                </p>
                                <p class="text-[12px] text-faint">
                                    {{ member.days_late }} late
                                    {{
                                        member.days_late === 1 ? 'day' : 'days'
                                    }}
                                    of {{ member.days_present }}
                                </p>
                            </div>
                            <StatusPill tone="brass">
                                {{ member.late_minutes }}m
                            </StatusPill>
                        </div>
                    </div>
                    <p v-else class="px-5 py-6 text-[13px] text-faint">
                        Nobody has lost a minute this month.
                    </p>
                </Panel>
            </div>

            <Panel
                title="Your people"
                :subtitle="`This month. ${group.headcount} ${group.headcount === 1 ? 'person' : 'people'}, worst timekeeping first.`"
                flush
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th
                                    class="px-5 py-2.5 text-[12px] font-medium text-faint"
                                >
                                    Name
                                </th>
                                <th
                                    class="px-3 py-2.5 text-[12px] font-medium text-faint"
                                >
                                    Today
                                </th>
                                <th
                                    class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                                >
                                    Days in
                                </th>
                                <th
                                    class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                                >
                                    Late
                                </th>
                                <th
                                    class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                                >
                                    Punctuality
                                </th>
                                <th
                                    class="px-3 py-2.5 text-right text-[12px] font-medium text-faint"
                                >
                                    Leave taken
                                </th>
                                <th
                                    class="px-5 py-2.5 text-right text-[12px] font-medium text-faint"
                                >
                                    Open
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr
                                v-for="member in members"
                                :key="member.id"
                                class="transition-colors hover:bg-panel-raised"
                            >
                                <td class="px-5 py-3">
                                    <component
                                        :is="canOpenRecords ? Link : 'span'"
                                        :href="
                                            canOpenRecords
                                                ? `/admin/staff/${member.id}`
                                                : undefined
                                        "
                                        class="block min-w-0"
                                    >
                                        <span
                                            class="block truncate text-[13.5px] font-medium"
                                        >
                                            {{ member.name }}
                                        </span>
                                        <span
                                            class="block truncate text-[12px] text-faint"
                                        >
                                            {{
                                                member.position ??
                                                'No job title'
                                            }}
                                            <template v-if="member.team">
                                                · {{ member.team }}
                                            </template>
                                        </span>
                                    </component>
                                </td>
                                <td class="px-3 py-3">
                                    <StatusPill
                                        v-if="member.late_today"
                                        tone="brass"
                                    >
                                        Late
                                    </StatusPill>
                                    <StatusPill
                                        v-else-if="member.clocked_in_today"
                                        tone="signal"
                                    >
                                        In
                                    </StatusPill>
                                    <span
                                        v-else
                                        class="text-[12.5px] text-faint"
                                    >
                                        Not in
                                    </span>
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right text-[13px]"
                                >
                                    {{ member.days_present }}
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right text-[13px]"
                                    :class="
                                        member.days_late > 0 && 'text-brass'
                                    "
                                >
                                    {{ member.days_late }}
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right text-[13px]"
                                >
                                    {{
                                        member.punctuality === null
                                            ? '-'
                                            : `${member.punctuality}%`
                                    }}
                                </td>
                                <td
                                    class="tabular px-3 py-3 text-right text-[13px]"
                                >
                                    {{ member.leave_days_taken }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 text-right text-[13px]"
                                >
                                    {{ member.open_requests || '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Panel>
        </template>
    </section>
</template>
