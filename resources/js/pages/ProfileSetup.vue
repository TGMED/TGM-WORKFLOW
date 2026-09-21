<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AddressModal from '@/components/profile/AddressModal.vue';
import BankTab from '@/components/profile/BankTab.vue';
import FamilyTab from '@/components/profile/FamilyTab.vue';
import ProfileTab from '@/components/profile/ProfileTab.vue';
import RelationModal from '@/components/profile/RelationModal.vue';
import AppButton from '@/components/ui/AppButton.vue';
import { useProfileEditors } from '@/composables/useProfileEditors';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    EmployeeAddress,
    EmployeeProfile,
    EmployeeRelation,
    ProfileOptions,
    RelationKind,
} from '@/types';

const props = defineProps<{
    profile: EmployeeProfile;
    relations: Record<RelationKind, EmployeeRelation[]>;
    addresses: EmployeeAddress[];
    options: ProfileOptions;
    missing_fields: string[];
    is_complete: boolean;
}>();

type Step = 'profile' | 'family' | 'bank' | 'done';

/**
 * The first step is the only one the app will not let anybody past: it holds
 * what the people team cannot run payroll or an emergency call without. The
 * others are asked for while they are here, and can be skipped.
 */
const steps: Array<{ id: Exclude<Step, 'done'>; label: string; lede: string }> =
    [
        {
            id: 'profile',
            label: 'About you',
            lede: 'Your name, date of birth, where you are from and a number we can reach you on. This part is required.',
        },
        {
            id: 'family',
            label: 'Family',
            lede: 'Who to call in an emergency, and anyone else the people team should know about. You can skip this and come back to it.',
        },
        {
            id: 'bank',
            label: 'Bank account',
            lede: 'Where your pay goes. You can skip this for now, but payroll will need it before your first payslip.',
        },
    ];

function stepFromUrl(): Step {
    const wanted = new URLSearchParams(window.location.search).get('step');

    // Nobody skips the required step by editing the address bar.
    if (!props.is_complete) {
        return 'profile';
    }

    return wanted === 'family' || wanted === 'bank' || wanted === 'done'
        ? wanted
        : 'family';
}

const step = ref<Step>(stepFromUrl());

const index = computed(() => steps.findIndex((item) => item.id === step.value));
const current = computed(() => steps[index.value] ?? null);
const progress = computed(() =>
    step.value === 'done'
        ? 100
        : Math.round(((index.value + 1) / (steps.length + 1)) * 100),
);

/**
 * Saving a step reloads the page's props, so the URL is what carries the step
 * across that reload. Moving between steps is local and only rewrites it.
 */
function go(next: Step) {
    step.value = next;

    const url = new URL(window.location.href);
    url.searchParams.set('step', next);
    window.history.replaceState({}, '', url);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function afterProfile() {
    if (props.is_complete) {
        go('family');
    }
}

const hasFamily = computed(() =>
    Object.values(props.relations).some((list) => list.length > 0),
);

const {
    relationOpen,
    relationKind,
    editingRelation,
    addressOpen,
    editingAddress,
    addRelation,
    editRelation,
    removeRelation,
    editAddress,
    removeAddress,
} = useProfileEditors();
</script>

<template>
    <Head title="Getting set up" />

    <AppLayout
        heading="Getting set up"
        lede="A few details for the people team, one step at a time"
    >
        <!-- Where they are, and how far there is to go. -->
        <div class="mb-6">
            <div class="flex items-baseline justify-between gap-4">
                <p class="eyebrow">
                    <template v-if="current">
                        Step {{ index + 1 }} of {{ steps.length }}
                    </template>
                    <template v-else>Done</template>
                </p>
                <p class="tabular text-[12px] text-faint">{{ progress }}%</p>
            </div>

            <div
                class="mt-2 h-1.5 overflow-hidden rounded-full bg-line-soft"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-brand transition-[width] duration-500 ease-out"
                    :style="{ width: `${progress}%` }"
                />
            </div>

            <ol class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                <li
                    v-for="(item, position) in steps"
                    :key="item.id"
                    class="flex items-center gap-1.5 text-[12.5px]"
                    :class="
                        item.id === step
                            ? 'font-medium text-text'
                            : position < index || step === 'done'
                              ? 'text-muted'
                              : 'text-faint'
                    "
                >
                    <span
                        class="grid size-4 place-items-center rounded-full text-[10px]"
                        :class="
                            position < index || step === 'done'
                                ? 'bg-signal text-white'
                                : item.id === step
                                  ? 'bg-brand text-white'
                                  : 'bg-line-soft text-faint'
                        "
                        aria-hidden="true"
                    >
                        {{
                            position < index || step === 'done'
                                ? '✓'
                                : position + 1
                        }}
                    </span>
                    {{ item.label }}
                </li>
            </ol>
        </div>

        <template v-if="current">
            <div class="mb-5">
                <h2
                    class="font-display text-[19px] font-semibold tracking-tight"
                >
                    {{ current.label }}
                </h2>
                <p class="mt-1 max-w-2xl text-[13.5px] text-muted">
                    {{ current.lede }}
                </p>
            </div>

            <ProfileTab
                v-if="step === 'profile'"
                :profile="profile"
                :addresses="addresses"
                :options="options"
                :missing-fields="missing_fields"
                @edit-address="editAddress"
                @saved="afterProfile"
            />

            <template v-else-if="step === 'family'">
                <FamilyTab
                    :relations="relations"
                    :options="options"
                    @add="addRelation"
                    @edit="editRelation"
                />

                <div class="mt-6 flex items-center justify-between gap-3">
                    <AppButton variant="ghost" @click="go('profile')">
                        Back
                    </AppButton>
                    <AppButton @click="go('bank')">
                        {{ hasFamily ? 'Continue' : 'Skip for now' }}
                    </AppButton>
                </div>
            </template>

            <template v-else-if="step === 'bank'">
                <BankTab
                    :profile="profile"
                    :options="options"
                    @saved="go('done')"
                />

                <div class="mt-6 flex items-center justify-between gap-3">
                    <AppButton variant="ghost" @click="go('family')">
                        Back
                    </AppButton>
                    <AppButton variant="secondary" @click="go('done')">
                        Skip for now
                    </AppButton>
                </div>
            </template>
        </template>

        <!-- The end: the dashboard shows them round the first time they
             land on it, so nothing more is needed here. -->
        <div
            v-else
            class="rounded-2xl border border-line bg-panel px-6 py-10 text-center shadow-panel"
        >
            <span
                class="mx-auto grid size-11 place-items-center rounded-full bg-signal/15 text-signal"
                aria-hidden="true"
            >
                <svg
                    class="size-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
            </span>
            <h2
                class="mt-4 font-display text-[21px] font-semibold tracking-tight"
            >
                You are all set
            </h2>
            <p class="mx-auto mt-1.5 max-w-md text-[13.5px] text-muted">
                Anything you skipped can be filled in later from My profile, in
                the menu under your name. We will show you round the dashboard
                when you get there.
            </p>
            <Link href="/dashboard" class="mt-6 inline-block">
                <AppButton size="lg">Go to the dashboard</AppButton>
            </Link>
        </div>

        <RelationModal
            :open="relationOpen"
            :kind="relationKind"
            :relation="editingRelation"
            :options="options"
            @close="relationOpen = false"
            @removed="removeRelation"
        />

        <AddressModal
            :open="addressOpen"
            :address="editingAddress"
            :options="options"
            @close="addressOpen = false"
            @removed="removeAddress"
        />
    </AppLayout>
</template>
