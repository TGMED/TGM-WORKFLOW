<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import Avatar from '@/components/ui/Avatar.vue';
import ToastHost from '@/components/ui/ToastHost.vue';
import WhatsNewModal from '@/components/WhatsNewModal.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useToasts } from '@/composables/useToasts';
import type { Permission, SharedProps } from '@/types';

defineProps<{ heading?: string; lede?: string }>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const { appearance, toggle } = useAppearance();
const { push } = useToasts();

const mobileNavOpen = ref(false);
const userMenuOpen = ref(false);

type NavItem = {
    label: string;
    href: string;
    icon: string;
    /** Hidden unless the person's role holds this. */
    permission?: Permission;
    staffOnly?: boolean;
    approverOnly?: boolean;
    badge?: () => number;
    // A group heads a set of related pages and is not a destination itself.
    children?: NavItem[];
};

// Icons are single-path outlines, kept inline so there is no icon dependency.
const nav: NavItem[] = [
    {
        label: 'Dashboard',
        href: '/dashboard',
        icon: 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z',
    },
    {
        label: 'My attendance',
        href: '/attendance',
        staffOnly: true,
        icon: 'M8 3v3m8-3v3M3.5 9.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Z',
    },
    {
        label: 'My requests',
        href: '/leave',
        staffOnly: true,
        icon: 'M8 4h8a1.5 1.5 0 0 1 1.5 1.5v14L12 17l-5.5 2.5v-14A1.5 1.5 0 0 1 8 4Z',
        children: [
            {
                label: 'Leave',
                href: '/leave',
                icon: 'M4.5 6.5h15M6.5 6.5V4m11 2.5V4M3.5 10.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Zm3.5 9.5 2 2 4-4.5',
            },
            {
                label: 'Lateness',
                href: '/lateness',
                icon: 'M12 7v5.2l3.2 1.9M3.5 12a8.5 8.5 0 1 0 17 0 8.5 8.5 0 0 0-17 0Z',
            },
        ],
    },
    {
        label: "Who's away",
        href: '/away',
        icon: 'M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM16.5 8.5h5',
    },
    {
        label: 'Approvals',
        href: '/approvals',
        approverOnly: true,
        badge: () => page.props.pending_approvals ?? 0,
        icon: 'M9 12.5 11 14.5 15.5 10M6 3.5h12A1.5 1.5 0 0 1 19.5 5v15.5l-3.5-2-4 2-4-2-3.5 2V5A1.5 1.5 0 0 1 6 3.5Z',
    },
    {
        label: 'Admin console',
        href: '/admin',
        permission: 'admin.dashboard',
        icon: 'M3.5 5A1.5 1.5 0 0 1 5 3.5h14A1.5 1.5 0 0 1 20.5 5v14a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V5Zm3.5 10.5 3-3.5 2.5 2.5 4-5',
    },
    {
        label: 'Staff',
        href: '/admin/staff',
        permission: 'staff.manage',
        icon: 'M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM22 20v-1.5a4 4 0 0 0-3-3.87M16 3.63a4 4 0 0 1 0 7.75',
    },
    {
        label: 'Locations',
        href: '/admin/locations',
        permission: 'locations.manage',
        icon: 'M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Zm0-8.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
    },
    {
        label: 'Attendance',
        href: '/admin/attendance',
        permission: 'attendance.report',
        icon: 'M8 3v3m8-3v3M3.5 9.5h17M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 19 20.5H5A1.5 1.5 0 0 1 3.5 19V7A1.5 1.5 0 0 1 5 5.5Zm3.5 7.5 2 2 4.5-4.5',
    },
    {
        label: 'Clock attempts',
        href: '/admin/clock-attempts',
        permission: 'clock-attempts.view',
        icon: 'M12 8v4l2.5 2.5M3.5 12a8.5 8.5 0 1 0 17 0 8.5 8.5 0 0 0-17 0Z',
    },
    {
        label: 'Announcements',
        href: '/admin/announcements',
        permission: 'announcements.manage',
        icon: 'M3.5 10.5v3a1.5 1.5 0 0 0 1.5 1.5h2l5 4V5l-5 4H5a1.5 1.5 0 0 0-1.5 1.5Zm13-1.5a5 5 0 0 1 0 6',
    },
    {
        label: 'Request settings',
        href: '/admin/request-settings',
        permission: 'request-settings.manage',
        icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a7.4 7.4 0 0 0-2-1.2L14.5 3h-4l-.4 2.6a7.4 7.4 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.5a7.4 7.4 0 0 0 0 2.5l-2 1.5 2 3.4 2.4-1a7.4 7.4 0 0 0 2 1.2l.4 2.6h4l.4-2.6a7.4 7.4 0 0 0 2-1.2l2.4 1 2-3.4-2-1.5c.05-.4.1-.8.1-1.2Z',
    },
    {
        label: 'Roles',
        href: '/admin/roles',
        permission: 'roles.manage',
        icon: 'M12 3.5 4.5 6.5v5c0 4.3 3.1 7.9 7.5 9 4.4-1.1 7.5-4.7 7.5-9v-5L12 3.5Zm0 5.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm-3.5 8a3.5 3.5 0 0 1 7 0',
    },
    {
        label: 'Audit trail',
        href: '/admin/audit',
        permission: 'audit.view',
        icon: 'M8 3.5h5.5L18.5 8v11a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.5 19V5A1.5 1.5 0 0 1 8 3.5Zm5 0V8h4.5M9.5 13h6m-6 3h4',
    },
];

function may(permission: Permission | undefined): boolean {
    return (
        permission === undefined ||
        user.value?.permissions.includes(permission) === true
    );
}

// Admins run the clock rather than punch it, so the personal attendance
// view is not theirs to see. And until a profile is finished every one of
// these bounces straight back to it, so the rail stays empty rather than
// offering doors that do not open.
const visibleNav = computed(() =>
    user.value?.profile_complete === false
        ? []
        : nav.filter(
              (item) =>
                  may(item.permission) &&
                  (!item.staffOnly || user.value?.clocks_in) &&
                  (!item.approverOnly || user.value?.can_use_approvals),
          ),
);

const currentUrl = computed(() => page.url.split('?')[0]);

function isCurrent(href: string): boolean {
    return currentUrl.value === href || currentUrl.value.startsWith(`${href}/`);
}

function holdsCurrent(item: NavItem): boolean {
    return (item.children ?? []).some((child) => isCurrent(child.href));
}

// Groups the visitor has opened by hand. A group holding the current page is
// open regardless, so you can always see where you are.
const openGroups = ref<string[]>([]);

function isExpanded(item: NavItem): boolean {
    return holdsCurrent(item) || openGroups.value.includes(item.label);
}

function toggleGroup(item: NavItem) {
    openGroups.value = openGroups.value.includes(item.label)
        ? openGroups.value.filter((label) => label !== item.label)
        : [...openGroups.value, item.label];
}

function signOut() {
    router.post('/logout');
}

// Server flashes surface as toasts.
watch(
    () => page.props.flash?.toast,
    (toast) => {
        if (toast) {
            push(toast);
        }
    },
    { immediate: true },
);

watch(currentUrl, () => {
    mobileNavOpen.value = false;
    userMenuOpen.value = false;
});
</script>

<template>
    <div class="min-h-dvh bg-base">
        <!-- Mobile scrim -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="mobileNavOpen"
                class="fixed inset-0 z-30 bg-[#050912]/70 backdrop-blur-sm lg:hidden"
                @click="mobileNavOpen = false"
            />
        </Transition>

        <!-- Rail -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 flex w-[264px] flex-col border-r border-line bg-panel',
                'transition-transform duration-300 ease-out lg:translate-x-0',
                mobileNavOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-16 items-center border-b border-line-soft px-5">
                <BrandMark size="sm" />
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
                <template v-for="item in visibleNav" :key="item.href">
                    <!-- A group of related pages, opened in place. -->
                    <div v-if="item.children">
                        <button
                            type="button"
                            :aria-expanded="isExpanded(item)"
                            :class="[
                                'relative flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-[13.5px] font-medium',
                                'transition-all duration-200 ease-out',
                                holdsCurrent(item)
                                    ? 'text-text'
                                    : 'text-muted hover:bg-line-soft/60 hover:text-text',
                            ]"
                            @click="toggleGroup(item)"
                        >
                            <svg
                                class="size-[18px] shrink-0"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path :d="item.icon" />
                            </svg>
                            <span class="truncate">{{ item.label }}</span>
                            <svg
                                class="ml-auto size-4 shrink-0 text-faint transition-transform duration-200"
                                :class="isExpanded(item) && 'rotate-180'"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div
                            v-if="isExpanded(item)"
                            class="mt-0.5 ml-[26px] space-y-0.5 border-l border-line-soft pl-2"
                        >
                            <Link
                                v-for="child in item.children"
                                :key="child.href"
                                :href="child.href"
                                :class="[
                                    'relative flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium',
                                    'transition-all duration-200 ease-out',
                                    isCurrent(child.href)
                                        ? 'bg-line-soft text-text'
                                        : 'text-muted hover:bg-line-soft/60 hover:text-text',
                                ]"
                            >
                                <span
                                    :class="[
                                        'absolute top-1/2 -left-[9px] h-4 w-[3px] -translate-y-1/2 rounded-r-full bg-brand',
                                        'transition-all duration-300 ease-out',
                                        isCurrent(child.href)
                                            ? 'opacity-100'
                                            : 'scale-y-0 opacity-0',
                                    ]"
                                />
                                <span class="truncate">{{ child.label }}</span>
                            </Link>
                        </div>
                    </div>

                    <Link
                        v-else
                        :href="item.href"
                        :class="[
                            'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13.5px] font-medium',
                            'transition-all duration-200 ease-out',
                            isCurrent(item.href)
                                ? 'bg-line-soft text-text'
                                : 'text-muted hover:bg-line-soft/60 hover:text-text',
                        ]"
                    >
                        <span
                            :class="[
                                'absolute top-1/2 left-0 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-brand',
                                'transition-all duration-300 ease-out',
                                isCurrent(item.href)
                                    ? 'opacity-100'
                                    : 'scale-y-0 opacity-0',
                            ]"
                        />
                        <svg
                            class="size-[18px] shrink-0"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path :d="item.icon" />
                        </svg>
                        <span class="truncate">{{ item.label }}</span>
                        <span
                            v-if="item.badge && item.badge() > 0"
                            class="ml-auto rounded-full bg-brand px-1.5 py-0.5 text-[11px] font-semibold text-white"
                        >
                            {{ item.badge() }}
                        </span>
                    </Link>
                </template>
            </nav>

            <div class="border-t border-line-soft p-3">
                <div class="relative">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors hover:bg-line-soft"
                        :aria-expanded="userMenuOpen"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <Avatar
                            v-if="user"
                            :initials="user.initials"
                            :name="user.name"
                            :src="user.avatar_url"
                            size="sm"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[13px] font-semibold tracking-tight"
                            >
                                {{ user?.name }}
                            </span>
                            <span
                                class="block truncate text-[11.5px] text-faint"
                            >
                                {{ user?.location?.name ?? user?.role_label }}
                            </span>
                        </span>
                        <svg
                            class="size-4 shrink-0 text-faint transition-transform duration-200"
                            :class="userMenuOpen && 'rotate-180'"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                        >
                            <path d="m6 15 6-6 6 6" />
                        </svg>
                    </button>

                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-2"
                        leave-active-class="transition duration-150 ease-in"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 bottom-full left-0 mb-2 overflow-hidden rounded-xl border border-line bg-panel-raised p-1 shadow-lift"
                        >
                            <Link
                                href="/profile"
                                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] text-muted transition-colors hover:bg-line-soft hover:text-text"
                            >
                                <svg
                                    class="size-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path
                                        d="M19 20v-1.5a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4V20M12 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"
                                    />
                                </svg>
                                My profile
                                <span
                                    v-if="user && !user.profile_complete"
                                    class="ml-auto size-2 rounded-full bg-brass"
                                    aria-label="Incomplete"
                                />
                            </Link>
                            <Link
                                href="/settings/notifications"
                                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] text-muted transition-colors hover:bg-line-soft hover:text-text"
                            >
                                <svg
                                    class="size-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path
                                        d="M18 8.5a6 6 0 1 0-12 0c0 5-2 6.5-2 6.5h16s-2-1.5-2-6.5ZM13.7 18.5a2 2 0 0 1-3.4 0"
                                    />
                                </svg>
                                Notifications
                            </Link>
                            <Link
                                href="/settings/password"
                                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] text-muted transition-colors hover:bg-line-soft hover:text-text"
                            >
                                <svg
                                    class="size-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linecap="round"
                                >
                                    <rect
                                        x="4"
                                        y="10.5"
                                        width="16"
                                        height="9.5"
                                        rx="2"
                                    />
                                    <path
                                        d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5"
                                    />
                                </svg>
                                Change password
                            </Link>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-[13px] text-muted transition-colors hover:bg-alert-soft hover:text-alert"
                                @click="signOut"
                            >
                                <svg
                                    class="size-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path
                                        d="M15 17l5-5-5-5M20 12H9M12 20H6.5A2.5 2.5 0 0 1 4 17.5v-11A2.5 2.5 0 0 1 6.5 4H12"
                                    />
                                </svg>
                                Sign out
                            </button>
                        </div>
                    </Transition>
                </div>
            </div>
        </aside>

        <!-- Content -->
        <div class="lg:pl-[264px]">
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-base/85 px-4 backdrop-blur-md sm:px-6"
            >
                <button
                    type="button"
                    aria-label="Open navigation"
                    class="-ml-1 grid size-9 place-items-center rounded-lg text-muted transition-colors hover:bg-line-soft hover:text-text lg:hidden"
                    @click="mobileNavOpen = true"
                >
                    <svg
                        class="size-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.9"
                        stroke-linecap="round"
                    >
                        <path d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                </button>

                <div class="min-w-0 flex-1">
                    <h1
                        v-if="heading"
                        class="truncate font-display text-[19px] font-semibold tracking-tight"
                    >
                        {{ heading }}
                    </h1>
                    <p v-if="lede" class="truncate text-[12.5px] text-muted">
                        {{ lede }}
                    </p>
                </div>

                <slot name="toolbar" />

                <button
                    type="button"
                    :aria-label="`Switch to ${appearance === 'dark' ? 'light' : 'dark'} theme`"
                    class="grid size-9 place-items-center rounded-lg text-muted transition-colors hover:bg-line-soft hover:text-text"
                    @click="toggle"
                >
                    <svg
                        class="size-[18px]"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                    >
                        <template v-if="appearance === 'dark'">
                            <circle cx="12" cy="12" r="4" />
                            <path
                                d="M12 2.5v2M12 19.5v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2.5 12h2M19.5 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"
                            />
                        </template>
                        <path
                            v-else
                            d="M20 13.5A8 8 0 1 1 10.5 4a6.5 6.5 0 0 0 9.5 9.5Z"
                        />
                    </svg>
                </button>
            </header>

            <main class="mx-auto w-full max-w-[1240px] p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>

        <ToastHost />
        <WhatsNewModal />
    </div>
</template>
