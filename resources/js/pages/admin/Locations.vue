<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import GeofenceMap from '@/components/GeofenceMap.vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextField from '@/components/ui/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { clockToMinutes, minutesToClock } from '@/lib/format';

type LocationRow = {
    id: number;
    name: string;
    address: string;
    city: string | null;
    latitude: number | null;
    longitude: number | null;
    radius_meters: number;
    max_accuracy_meters: number;
    work_starts_at: string;
    work_ends_at: string;
    grace_minutes: number;
    break_minutes: number;
    workdays: number[];
    timezone: string;
    is_active: boolean;
    accepts_signups: boolean;
    has_coordinates: boolean;
    staff_count: number;
    active_staff_count: number;
    today: { clocked_in: number; late: number };
};

const props = defineProps<{
    locations: LocationRow[];
    timezones: string[];
    unassigned_staff: number;
}>();

const days = [
    { number: 1, label: 'Mon' },
    { number: 2, label: 'Tue' },
    { number: 3, label: 'Wed' },
    { number: 4, label: 'Thu' },
    { number: 5, label: 'Fri' },
    { number: 6, label: 'Sat' },
    { number: 7, label: 'Sun' },
];

function fullAddress(location: LocationRow): string {
    return location.city
        ? `${location.address}, ${location.city}`
        : location.address;
}

const editing = ref<LocationRow | null>(null);
const modalOpen = ref(false);
const mapRef = ref<InstanceType<typeof GeofenceMap> | null>(null);

const confirming = ref<LocationRow | null>(null);
const toggling = ref(false);

const form = useForm({
    name: '',
    address: '',
    city: '',
    latitude: null as number | null,
    longitude: null as number | null,
    radius_meters: 150,
    max_accuracy_meters: 200,
    work_starts_at: '09:00',
    work_ends_at: '17:00',
    grace_minutes: 10,
    break_minutes: 60,
    workdays: [1, 2, 3, 4, 5] as number[],
    timezone: 'Africa/Lagos',
    accepts_signups: true,
});

async function open(location: LocationRow | null) {
    editing.value = location;

    form.clearErrors();
    form.defaults({
        name: location?.name ?? '',
        address: location?.address ?? '',
        city: location?.city ?? '',
        latitude: location?.latitude ?? null,
        longitude: location?.longitude ?? null,
        radius_meters: location?.radius_meters ?? 150,
        max_accuracy_meters: location?.max_accuracy_meters ?? 200,
        work_starts_at: location?.work_starts_at ?? '09:00',
        work_ends_at: location?.work_ends_at ?? '17:00',
        grace_minutes: location?.grace_minutes ?? 10,
        break_minutes: location?.break_minutes ?? 60,
        workdays: [...(location?.workdays ?? [1, 2, 3, 4, 5])],
        timezone: location?.timezone ?? 'Africa/Lagos',
        accepts_signups: location?.accepts_signups ?? true,
    });
    form.reset();

    modalOpen.value = true;

    // Let the modal finish animating before Leaflet measures its container.
    await nextTick();
    window.setTimeout(() => mapRef.value?.refresh(), 320);
}

function toggleDay(number: number) {
    form.workdays = form.workdays.includes(number)
        ? form.workdays.filter((day) => day !== number)
        : [...form.workdays, number].sort((a, b) => a - b);
}

function onPinMove(payload: { latitude: number; longitude: number }) {
    form.latitude = payload.latitude;
    form.longitude = payload.longitude;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
            editing.value = null;
        },
    };

    if (editing.value) {
        form.put(`/admin/locations/${editing.value.id}`, options);
    } else {
        form.post('/admin/locations', options);
    }
}

function confirmToggle() {
    if (!confirming.value) {
        return;
    }

    toggling.value = true;

    router.patch(
        `/admin/locations/${confirming.value.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                confirming.value = null;
            },
        },
    );
}

const cutoff = computed(() =>
    minutesToClock(clockToMinutes(form.work_starts_at) + form.grace_minutes),
);

const totals = computed(() => ({
    sites: props.locations.length,
    active: props.locations.filter((l) => l.is_active).length,
    staff: props.locations.reduce((sum, l) => sum + l.active_staff_count, 0),
}));
</script>

<template>
    <Head title="Locations" />

    <AppLayout
        heading="Locations"
        :lede="`${totals.active} active of ${totals.sites} sites · ${totals.staff} staff assigned`"
    >
        <template #toolbar>
            <AppButton size="sm" @click="open(null)">
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
                Add location
            </AppButton>
        </template>

        <div class="space-y-5">
            <div
                v-if="unassigned_staff > 0"
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
                    {{ unassigned_staff }}
                    {{ unassigned_staff === 1 ? 'person has' : 'people have' }}
                    no work location and cannot clock in.
                </p>
                <Link href="/admin/staff?location=none">
                    <AppButton size="sm" variant="secondary">
                        Assign them
                    </AppButton>
                </Link>
            </div>

            <div
                v-if="locations.length"
                class="stagger grid gap-4 lg:grid-cols-2"
            >
                <article
                    v-for="location in locations"
                    :key="location.id"
                    :class="[
                        'overflow-hidden rounded-2xl border bg-panel shadow-panel transition-colors',
                        location.is_active
                            ? 'border-line hover:border-faint/50'
                            : 'border-line opacity-70',
                    ]"
                >
                    <div class="flex items-start justify-between gap-4 p-5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <h2
                                    class="truncate font-display text-[17px] font-semibold tracking-tight"
                                >
                                    {{ location.name }}
                                </h2>
                                <StatusPill
                                    v-if="!location.is_active"
                                    tone="neutral"
                                >
                                    Retired
                                </StatusPill>
                                <StatusPill
                                    v-else-if="!location.has_coordinates"
                                    tone="alert"
                                >
                                    No pin set
                                </StatusPill>
                                <StatusPill
                                    v-if="
                                        location.is_active &&
                                        !location.accepts_signups
                                    "
                                    tone="brass"
                                >
                                    Closed to signups
                                </StatusPill>
                            </div>

                            <p class="mt-1 text-[13px] leading-snug text-muted">
                                {{ fullAddress(location) }}
                            </p>

                            <p
                                class="tabular mt-2 font-mono text-[11.5px] text-faint"
                            >
                                {{ location.work_starts_at }}–{{
                                    location.work_ends_at
                                }}
                                · {{ location.grace_minutes }}m grace ·
                                {{
                                    location.break_minutes > 0
                                        ? `${location.break_minutes}m break`
                                        : 'no break'
                                }}
                                · {{ location.radius_meters }}m radius
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <AppButton
                                size="sm"
                                variant="secondary"
                                @click="open(location)"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="confirming = location"
                            >
                                {{ location.is_active ? 'Retire' : 'Restore' }}
                            </AppButton>
                        </div>
                    </div>

                    <dl
                        class="grid grid-cols-3 divide-x divide-line-soft border-t border-line-soft bg-sunken/40"
                    >
                        <div class="px-5 py-3">
                            <dt class="eyebrow">Staff</dt>
                            <dd
                                class="tabular mt-1 font-mono text-[18px] font-semibold"
                            >
                                {{ location.active_staff_count }}
                            </dd>
                        </div>
                        <div class="px-5 py-3">
                            <dt class="eyebrow">In today</dt>
                            <dd
                                class="tabular mt-1 font-mono text-[18px] font-semibold text-signal"
                            >
                                {{ location.today.clocked_in }}
                            </dd>
                        </div>
                        <div class="px-5 py-3">
                            <dt class="eyebrow">Late today</dt>
                            <dd
                                class="tabular mt-1 font-mono text-[18px] font-semibold"
                                :class="
                                    location.today.late > 0
                                        ? 'text-brass'
                                        : 'text-text'
                                "
                            >
                                {{ location.today.late }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>

            <Panel v-else flush>
                <EmptyState
                    title="No locations yet"
                    message="Add the site your team clocks in at. Staff pick from these when they sign up, and every punch is measured against the one they chose."
                >
                    <template #action>
                        <AppButton @click="open(null)">
                            Add the first location
                        </AppButton>
                    </template>
                </EmptyState>
            </Panel>
        </div>

        <!-- Add / edit -->
        <ModalShell
            :open="modalOpen"
            width="xl"
            :title="editing ? `Edit ${editing.name}` : 'Add a location'"
            subtitle="Staff assigned here are measured against this pin and these hours."
            @close="modalOpen = false"
        >
            <form id="location-form" class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.name"
                        label="Location name"
                        placeholder="TGM Head Office"
                        required
                        :error="form.errors.name"
                    />
                    <TextField
                        v-model="form.city"
                        label="City"
                        placeholder="Lagos"
                        :error="form.errors.city"
                    />
                </div>

                <TextField
                    v-model="form.address"
                    label="Street address"
                    placeholder="1 Adeola Odeku Street, Victoria Island"
                    required
                    :error="form.errors.address"
                    hint="Shown to staff on the signup form, so make it recognisable."
                />

                <div>
                    <p class="mb-2 text-[13px] font-medium text-muted">
                        Clock-in point
                    </p>
                    <GeofenceMap
                        ref="mapRef"
                        :latitude="form.latitude"
                        :longitude="form.longitude"
                        :radius="form.radius_meters"
                        @move="onPinMove"
                    />
                    <p
                        v-if="form.errors.latitude"
                        class="mt-2 text-[13px] text-alert"
                    >
                        {{ form.errors.latitude }}
                    </p>
                </div>

                <div>
                    <div class="flex items-baseline justify-between gap-3">
                        <label
                            for="radius"
                            class="text-[13px] font-medium text-muted"
                        >
                            Allowed radius
                        </label>
                        <span
                            class="tabular font-mono text-[15px] font-semibold"
                        >
                            {{ form.radius_meters }}m
                        </span>
                    </div>

                    <!--
                        Bound explicitly rather than with v-model: the browser
                        clamps a range value against whatever min/max it has at
                        the time, so the stored radius must be written after
                        those bounds exist.
                    -->
                    <input
                        id="radius"
                        type="range"
                        min="20"
                        max="1000"
                        step="10"
                        :value="form.radius_meters"
                        class="mt-3 h-1.5 w-full cursor-pointer appearance-none rounded-full bg-line accent-signal"
                        @input="
                            form.radius_meters = Number(
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <p
                        v-if="form.errors.radius_meters"
                        class="mt-1 text-[13px] text-alert"
                    >
                        {{ form.errors.radius_meters }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model.number="form.latitude"
                        label="Latitude"
                        type="number"
                        step="any"
                        required
                    />
                    <TextField
                        v-model.number="form.longitude"
                        label="Longitude"
                        type="number"
                        step="any"
                        required
                        :error="form.errors.longitude"
                    />
                </div>

                <div class="h-px bg-line-soft" />

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <TextField
                        v-model="form.work_starts_at"
                        label="Opens"
                        type="time"
                        required
                        :error="form.errors.work_starts_at"
                    />
                    <TextField
                        v-model="form.work_ends_at"
                        label="Closes"
                        type="time"
                        required
                        :error="form.errors.work_ends_at"
                    />
                    <TextField
                        v-model.number="form.grace_minutes"
                        label="Grace (min)"
                        type="number"
                        min="0"
                        max="240"
                        required
                        :error="form.errors.grace_minutes"
                    />
                    <TextField
                        v-model.number="form.break_minutes"
                        label="Break (min)"
                        type="number"
                        min="0"
                        max="480"
                        required
                        :error="form.errors.break_minutes"
                    />
                </div>

                <p class="-mt-2 text-[12px] text-faint">
                    Anyone in by
                    <span class="font-mono text-text">{{ cutoff }}</span>
                    counts as on time here.
                    {{
                        form.break_minutes > 0
                            ? `Breaks may run to ${form.break_minutes} minutes and are deducted from hours worked; going over is flagged, not blocked.`
                            : 'Breaks are switched off at this site.'
                    }}
                </p>

                <div>
                    <p class="mb-2 text-[13px] font-medium text-muted">
                        Working days
                    </p>
                    <div class="grid grid-cols-7 gap-1">
                        <button
                            v-for="day in days"
                            :key="day.number"
                            type="button"
                            :aria-pressed="form.workdays.includes(day.number)"
                            :class="[
                                'h-9 rounded-lg border px-1 text-[12px] font-medium',
                                'transition-all duration-200 active:scale-95',
                                form.workdays.includes(day.number)
                                    ? 'border-signal bg-signal text-[#06231b]'
                                    : 'border-line bg-panel-raised text-muted hover:border-faint',
                            ]"
                            @click="toggleDay(day.number)"
                        >
                            {{ day.label }}
                        </button>
                    </div>
                    <p
                        v-if="form.errors.workdays"
                        class="mt-1.5 text-[13px] text-alert"
                    >
                        {{ form.errors.workdays }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.timezone"
                        label="Timezone"
                        :options="
                            timezones.map((tz) => ({ value: tz, label: tz }))
                        "
                        required
                        :error="form.errors.timezone"
                    />
                    <TextField
                        v-model.number="form.max_accuracy_meters"
                        label="Reject GPS vaguer than (m)"
                        type="number"
                        min="10"
                        max="5000"
                        required
                        :error="form.errors.max_accuracy_meters"
                    />
                </div>

                <label
                    class="flex cursor-pointer items-start gap-3 rounded-xl border border-line bg-panel-raised p-3.5"
                >
                    <input
                        v-model="form.accepts_signups"
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0 appearance-none rounded-[5px] border border-line bg-panel transition-colors checked:border-signal checked:bg-signal"
                    />
                    <span class="min-w-0">
                        <span class="block text-[13px] font-medium">
                            Offer this location at signup
                        </span>
                        <span class="mt-0.5 block text-[12px] text-muted">
                            Turn off to keep the site running for existing staff
                            while hiding it from new registrations.
                        </span>
                    </span>
                </label>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="location-form"
                    :loading="form.processing"
                >
                    {{ editing ? 'Save location' : 'Add location' }}
                </AppButton>
            </template>
        </ModalShell>

        <!-- Retire / restore -->
        <ModalShell
            :open="!!confirming"
            width="md"
            :title="
                confirming?.is_active
                    ? 'Retire this location?'
                    : 'Restore this location?'
            "
            @close="confirming = null"
        >
            <p class="text-[13.5px] leading-relaxed text-muted">
                <template v-if="confirming?.is_active">
                    <span class="font-medium text-text">{{
                        confirming?.name
                    }}</span>
                    will be hidden from signup and its
                    {{ confirming?.active_staff_count }} staff will not be able
                    to clock in until they are moved to another site. All
                    history is kept.
                </template>
                <template v-else>
                    <span class="font-medium text-text">{{
                        confirming?.name
                    }}</span>
                    will accept clock-ins again straight away.
                </template>
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="confirming = null">
                    Cancel
                </AppButton>
                <AppButton
                    :variant="confirming?.is_active ? 'danger' : 'primary'"
                    :loading="toggling"
                    @click="confirmToggle"
                >
                    {{ confirming?.is_active ? 'Retire location' : 'Restore' }}
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
