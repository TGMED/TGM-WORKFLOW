<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ModalShell from '@/components/ui/ModalShell.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Panel from '@/components/ui/Panel.vue';
import SelectField from '@/components/ui/SelectField.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import TextareaField from '@/components/ui/TextareaField.vue';
import { usePaginated } from '@/composables/usePaginated';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';

type Tone = 'signal' | 'brass' | 'alert' | 'beacon' | 'neutral';

type AboutMe = {
    id: number;
    rating: number;
    body: string;
    created_at: string | null;
};

type Written = {
    id: number;
    subject: string;
    rating: number;
    body: string;
    visibility: string;
    visibility_label: string;
    visibility_tone: Tone;
    created_at: string | null;
};

type PersonOption = { value: number; label: string; may_be_public: boolean };

const props = defineProps<{
    about_me: AboutMe[];
    written: Written[];
    people: PersonOption[];
}>();

const modalOpen = ref(false);

const form = useForm({
    subject_user_id: null as number | null,
    rating: 3,
    visibility: 'public',
    body: '',
});

const personOptions = computed(() =>
    props.people.map((person) => ({
        value: person.value,
        label: person.may_be_public
            ? person.label
            : `${person.label} (HR only)`,
    })),
);

const ratingOptions = [5, 4, 3, 2, 1].map((value) => ({
    value,
    label: `${value} of 5`,
}));

const visibilityOptions = [
    { value: 'public', label: 'Share with them, without my name' },
    { value: 'private', label: 'HR only' },
];

const chosen = computed(() =>
    props.people.find((person) => person.value === form.subject_user_id),
);

// Somebody with no working tie to the subject is heard by HR alone, so the
// choice is taken away rather than offered and then overruled.
const hrOnly = computed(() => chosen.value?.may_be_public === false);

watch(hrOnly, (only) => {
    if (only) {
        form.visibility = 'private';
    }
});

function open() {
    form.clearErrors();
    form.reset();
    modalOpen.value = true;
}

function submit() {
    form.post('/reviews', {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    });
}

const aboutPages = usePaginated(() => props.about_me);
const writtenPages = usePaginated(() => props.written);
</script>

<template>
    <Head title="Reviews" />

    <AppLayout
        heading="Performance reviews"
        lede="What colleagues say about your work, and what you have said about theirs. You never see who wrote a review of you."
    >
        <template #toolbar>
            <AppButton
                size="sm"
                :disabled="people.length === 0"
                @click="open()"
            >
                Write a review
            </AppButton>
        </template>

        <div class="space-y-6">
            <Panel flush title="About you">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">Rating</th>
                                <th class="px-5 py-3 font-medium">Review</th>
                                <th class="px-5 py-3 font-medium">Written</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="about_me.length === 0">
                                <td colspan="3">
                                    <EmptyState
                                        title="No reviews yet"
                                        message="When a colleague shares a review of your work, it shows here."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in aboutPages.paged"
                                :key="row.id"
                                class="align-top"
                            >
                                <td
                                    class="px-5 py-3.5 font-medium whitespace-nowrap"
                                >
                                    {{ row.rating }} of 5
                                </td>
                                <td class="px-5 py-3.5">
                                    <p
                                        class="max-w-[36rem] text-[13px] leading-relaxed whitespace-pre-line text-muted"
                                    >
                                        {{ row.body }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(row.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="aboutPages.page"
                    v-model:per-page="aboutPages.perPage"
                    :last-page="aboutPages.lastPage"
                    :from="aboutPages.from"
                    :to="aboutPages.to"
                    :total="aboutPages.total"
                />
            </Panel>

            <Panel flush title="Reviews you have written">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-[13.5px]">
                        <thead
                            class="border-b border-line-soft text-left text-[12px] text-faint"
                        >
                            <tr>
                                <th class="px-5 py-3 font-medium">About</th>
                                <th class="px-5 py-3 font-medium">Review</th>
                                <th class="px-5 py-3 font-medium">Written</th>
                                <th class="px-5 py-3 font-medium">
                                    Who can read it
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <tr v-if="written.length === 0">
                                <td colspan="4">
                                    <EmptyState
                                        title="Nothing written"
                                        message="Say what a colleague did well, or what they could do better."
                                    />
                                </td>
                            </tr>
                            <tr
                                v-for="row in writtenPages.paged"
                                :key="row.id"
                                class="align-top"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-medium">{{ row.subject }}</p>
                                    <p class="text-[12px] text-faint">
                                        {{ row.rating }} of 5
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p
                                        class="max-w-[30rem] text-[12.5px] leading-relaxed whitespace-pre-line text-faint"
                                    >
                                        {{ row.body }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3.5 text-[12.5px] whitespace-nowrap text-muted"
                                >
                                    {{ dateTime(row.created_at) }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <StatusPill :tone="row.visibility_tone" dot>
                                        {{ row.visibility_label }}
                                    </StatusPill>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    v-model:page="writtenPages.page"
                    v-model:per-page="writtenPages.perPage"
                    :last-page="writtenPages.lastPage"
                    :from="writtenPages.from"
                    :to="writtenPages.to"
                    :total="writtenPages.total"
                />
            </Panel>
        </div>

        <ModalShell
            :open="modalOpen"
            title="Write a review"
            subtitle="The person never sees your name. HR does."
            @close="modalOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <SelectField
                    v-model="form.subject_user_id"
                    label="Who"
                    required
                    :options="personOptions"
                    :error="form.errors.subject_user_id"
                >
                    <option :value="null" disabled>Pick somebody</option>
                </SelectField>

                <div class="grid gap-4 sm:grid-cols-[140px_minmax(0,1fr)]">
                    <SelectField
                        v-model="form.rating"
                        label="Rating"
                        required
                        :options="ratingOptions"
                        :error="form.errors.rating"
                    />

                    <SelectField
                        v-model="form.visibility"
                        label="Who can read it"
                        required
                        :disabled="hrOnly"
                        :options="visibilityOptions"
                        :error="form.errors.visibility"
                    />
                </div>

                <p
                    v-if="hrOnly"
                    class="rounded-xl bg-sunken/60 px-3 py-2 text-[12.5px] text-brass"
                >
                    You do not work with them directly, so this goes to HR only.
                </p>

                <TextareaField
                    v-model="form.body"
                    label="Your review"
                    required
                    :rows="6"
                    placeholder="What they did, how it went, and what would make it better."
                    :error="form.errors.body"
                />
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">
                    Cancel
                </AppButton>
                <AppButton :loading="form.processing" @click="submit">
                    Save review
                </AppButton>
            </template>
        </ModalShell>
    </AppLayout>
</template>
