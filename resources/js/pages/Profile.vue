<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AddressModal from '@/components/profile/AddressModal.vue';
import BankTab from '@/components/profile/BankTab.vue';
import FamilyTab from '@/components/profile/FamilyTab.vue';
import ProfileTab from '@/components/profile/ProfileTab.vue';
import RelationModal from '@/components/profile/RelationModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    EmployeeAddress,
    EmployeeProfile,
    EmployeeRelation,
    ProfileOptions,
    ProfileTab as Tab,
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

const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'profile', label: 'Profile' },
    { id: 'family', label: 'Family' },
    { id: 'bank', label: 'Bank Account' },
];

function tabFromUrl(): Tab {
    const wanted = new URLSearchParams(window.location.search).get('tab');

    return tabs.some((tab) => tab.id === wanted) ? (wanted as Tab) : 'profile';
}

const tab = ref<Tab>(tabFromUrl());

/**
 * Every tab's data ships with the page, so switching is a local move. The URL
 * follows along without a visit so a tab can still be linked to and survives
 * a reload.
 */
function open(next: Tab) {
    tab.value = next;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', next);
    window.history.replaceState({}, '', url);
}

const labels: Record<string, string> = {
    first_name: 'first name',
    last_name: 'last name',
    gender: 'gender',
    date_of_birth: 'date of birth',
    country_of_origin: 'country of origin',
    state_of_origin: 'state',
    phone: 'phone number',
    hired_at: 'joining date',
    address: 'home address',
};

const outstanding = computed(() =>
    props.missing_fields.map((field) => labels[field] ?? field).join(', '),
);

// Relations and addresses are edited in a modal. Null means adding a new one.
const relationOpen = ref(false);
const relationKind = ref<RelationKind>('next_of_kin');
const editingRelation = ref<EmployeeRelation | null>(null);

const addressOpen = ref(false);
const editingAddress = ref<EmployeeAddress | null>(null);

function addRelation(kind: RelationKind) {
    relationKind.value = kind;
    editingRelation.value = null;
    relationOpen.value = true;
}

function editRelation(relation: EmployeeRelation) {
    relationKind.value = relation.kind;
    editingRelation.value = relation;
    relationOpen.value = true;
}

function removeRelation() {
    if (!editingRelation.value) {
        return;
    }

    router.delete(`/profile/relations/${editingRelation.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (relationOpen.value = false),
    });
}

function editAddress(address: EmployeeAddress | null) {
    editingAddress.value = address;
    addressOpen.value = true;
}

function removeAddress() {
    if (!editingAddress.value) {
        return;
    }

    router.delete(`/profile/addresses/${editingAddress.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (addressOpen.value = false),
    });
}
</script>

<template>
    <Head title="My profile" />

    <AppLayout
        heading="My profile"
        lede="Your record with the people team, kept by you"
    >
        <!-- The gate that sent them here says why, so the page is not just a
             form they were dropped into for no visible reason. -->
        <div
            v-if="!is_complete"
            class="mb-5 flex items-start gap-3 rounded-2xl border border-brass/40 bg-brass-soft px-4 py-3.5"
        >
            <svg
                class="mt-0.5 size-[18px] shrink-0 text-brass"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
            >
                <circle cx="12" cy="12" r="8.5" />
                <path d="M12 8v4.5M12 16h.01" />
            </svg>

            <div class="min-w-0 text-[13px] leading-relaxed">
                <p class="font-semibold">Finish your profile to carry on</p>
                <p class="mt-0.5 text-muted">
                    The rest of the app opens up once we have your
                    {{ outstanding }}. Everything else on this page is optional
                    and can wait.
                </p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="mb-6 flex gap-1 overflow-x-auto border-b border-line">
            <button
                v-for="item in tabs"
                :key="item.id"
                type="button"
                :aria-current="tab === item.id ? 'page' : undefined"
                :class="[
                    'relative -mb-px shrink-0 px-4 py-3 text-[13.5px] font-medium',
                    'transition-colors duration-200 ease-out',
                    tab === item.id
                        ? 'text-text'
                        : 'text-muted hover:text-text',
                ]"
                @click="open(item.id)"
            >
                {{ item.label }}
                <span
                    :class="[
                        'absolute inset-x-3 bottom-0 h-[2px] rounded-t-full bg-brand',
                        'transition-all duration-300 ease-out',
                        tab === item.id ? 'opacity-100' : 'scale-x-0 opacity-0',
                    ]"
                />
            </button>
        </div>

        <ProfileTab
            v-if="tab === 'profile'"
            :profile="profile"
            :addresses="addresses"
            :options="options"
            :missing-fields="missing_fields"
            @edit-address="editAddress"
        />

        <FamilyTab
            v-else-if="tab === 'family'"
            :relations="relations"
            :options="options"
            @add="addRelation"
            @edit="editRelation"
        />

        <BankTab v-else :profile="profile" :options="options" />

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
