<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Panel from '@/components/ui/Panel.vue';
import StatusPill from '@/components/ui/StatusPill.vue';
import { usePush } from '@/composables/usePush';
import AppLayout from '@/layouts/AppLayout.vue';
import type { NotificationTopicSetting, PushState } from '@/types';

const props = defineProps<{
    topics: NotificationTopicSetting[];
    push: PushState;
}>();

const form = useForm({
    topics: props.topics.map((topic) => ({
        topic: topic.topic,
        email: topic.email,
        push: topic.push,
    })),
});

const { supported, busy, error, enable, disable } = usePush(props.push.config);

const pushOn = computed(() => props.push.devices > 0);

// Push switches mean nothing until this browser is registered, so they are
// held off rather than left on screen pretending to work.
const pushUsable = computed(
    () => props.push.configured && supported && pushOn.value,
);

function locked(index: number): boolean {
    return props.topics[index].required;
}

function submit() {
    form.put('/settings/notifications', { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <AppLayout
        heading="Notifications"
        lede="Choose what we write to you about, and how"
    >
        <div class="max-w-2xl space-y-6">
            <Panel
                eyebrow="This browser"
                title="Push notifications"
                :subtitle="
                    push.configured
                        ? 'Alerts arrive even with the tab closed.'
                        : 'Push is not set up on this deployment yet.'
                "
            >
                <template #action>
                    <StatusPill
                        :tone="pushOn ? 'signal' : 'neutral'"
                        dot
                        :pulse="pushOn"
                    >
                        {{ pushOn ? 'On' : 'Off' }}
                    </StatusPill>
                </template>

                <div class="space-y-4">
                    <p v-if="!push.configured" class="text-[13px] text-muted">
                        Ask an administrator to add the Firebase keys to the
                        environment. Everything below still works by email.
                    </p>

                    <p v-else-if="!supported" class="text-[13px] text-muted">
                        This browser cannot receive push notifications. Try a
                        recent Chrome, Edge or Firefox.
                    </p>

                    <template v-else>
                        <p class="text-[13px] text-muted">
                            {{
                                push.devices === 0
                                    ? 'Push is off. Turning it on asks your browser for permission.'
                                    : `Push is on in ${push.devices} browser${push.devices === 1 ? '' : 's'}.`
                            }}
                        </p>

                        <p
                            v-if="error"
                            class="rounded-xl bg-alert-soft px-3.5 py-2.5 text-[13px] text-alert"
                        >
                            {{ error }}
                        </p>

                        <div class="flex gap-2">
                            <AppButton
                                v-if="!pushOn"
                                size="sm"
                                :loading="busy"
                                @click="enable"
                            >
                                Turn on for this browser
                            </AppButton>

                            <template v-else>
                                <AppButton
                                    size="sm"
                                    variant="secondary"
                                    :loading="busy"
                                    @click="enable"
                                >
                                    Add this browser
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="ghost"
                                    :loading="busy"
                                    @click="disable"
                                >
                                    Turn off everywhere
                                </AppButton>
                            </template>
                        </div>
                    </template>
                </div>
            </Panel>

            <Panel
                eyebrow="Topics"
                title="What we write to you about"
                subtitle="Switch off anything you would rather not hear."
                flush
            >
                <form @submit.prevent="submit">
                    <ul class="divide-y divide-line-soft">
                        <li
                            v-for="(topic, index) in topics"
                            :key="topic.topic"
                            class="flex items-start gap-4 px-5 py-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p
                                    class="flex items-center gap-2 text-[14px] font-semibold tracking-tight"
                                >
                                    {{ topic.label }}
                                    <StatusPill v-if="topic.required">
                                        Always on
                                    </StatusPill>
                                </p>
                                <p
                                    class="mt-0.5 text-[13px] leading-relaxed text-muted"
                                >
                                    {{ topic.description }}
                                </p>
                            </div>

                            <div class="flex shrink-0 gap-4 pt-1">
                                <label
                                    class="flex items-center gap-2 text-[13px] text-muted"
                                >
                                    <input
                                        v-model="form.topics[index].email"
                                        type="checkbox"
                                        class="size-4 rounded border-line accent-brand disabled:opacity-40"
                                        :disabled="locked(index)"
                                    />
                                    Email
                                </label>

                                <label
                                    class="flex items-center gap-2 text-[13px] text-muted"
                                    :title="
                                        pushUsable
                                            ? undefined
                                            : 'Turn push on for this browser first.'
                                    "
                                >
                                    <input
                                        v-model="form.topics[index].push"
                                        type="checkbox"
                                        class="size-4 rounded border-line accent-brand disabled:opacity-40"
                                        :disabled="locked(index) || !pushUsable"
                                    />
                                    Push
                                </label>
                            </div>
                        </li>
                    </ul>

                    <div
                        class="flex justify-end border-t border-line-soft bg-sunken/40 px-5 py-4"
                    >
                        <AppButton type="submit" :loading="form.processing">
                            Save settings
                        </AppButton>
                    </div>
                </form>
            </Panel>
        </div>
    </AppLayout>
</template>
