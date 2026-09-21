<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/ui/AppButton.vue';
import TextField from '@/components/ui/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

defineProps<{ canResetPassword: boolean; status: string | null }>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <AuthLayout
        eyebrow="Staff access"
        heading="Sign in to TGM HR"
        lede="Use the work email your administrator set up for you."
    >
        <div
            v-if="status"
            class="animate-pop-in mb-5 rounded-xl border border-signal/30 bg-signal-soft px-4 py-3.5 text-sm text-signal"
        >
            {{ status }}
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <TextField
                size="lg"
                v-model="form.email"
                label="Work email"
                type="email"
                placeholder="you@tgm.test"
                autocomplete="username"
                required
                :error="form.errors.email"
            />

            <TextField
                size="lg"
                v-model="form.password"
                label="Password"
                type="password"
                placeholder="••••••••••"
                autocomplete="current-password"
                required
                :error="form.errors.password"
            />

            <div class="flex items-center justify-between gap-4 pt-1">
                <label
                    class="inline-flex cursor-pointer items-center gap-2.5 text-sm text-muted select-none"
                >
                    <span class="relative grid place-items-center">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="peer size-4 appearance-none rounded-[5px] border border-line bg-panel-raised transition-colors checked:border-signal checked:bg-signal"
                        />
                        <svg
                            class="pointer-events-none absolute size-3 scale-50 text-[#06231b] opacity-0 transition-all peer-checked:scale-100 peer-checked:opacity-100"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="3.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="m5 12.5 4.5 4.5L19 7" />
                        </svg>
                    </span>
                    Keep me signed in
                </label>

                <Link
                    v-if="canResetPassword"
                    href="/forgot-password"
                    class="text-sm font-medium text-beacon transition-opacity hover:opacity-75"
                >
                    Forgot password?
                </Link>
            </div>

            <AppButton
                type="submit"
                size="lg"
                block
                :loading="form.processing"
                class="!mt-6"
            >
                Sign in
            </AppButton>
        </form>

        <p class="mt-8 text-sm text-muted">
            New here? Your account is set up by the people team. Ask them if you
            have not heard from us yet.
        </p>
    </AuthLayout>
</template>
