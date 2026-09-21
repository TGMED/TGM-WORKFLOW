<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/ui/AppButton.vue';
import PasswordChecklist from '@/components/ui/PasswordChecklist.vue';
import TextField from '@/components/ui/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const props = defineProps<{
    token: string;
    valid: boolean;
    name: string | null;
    email: string | null;
}>();

const form = useForm({
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(`/invitation/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Welcome" />

    <AuthLayout
        v-if="valid"
        eyebrow="Welcome"
        :heading="`Welcome, ${name?.split(' ')[0] ?? 'in'}`"
        lede="Choose a password to get in. After that we will ask for a few details the people team needs, one step at a time."
    >
        <form class="space-y-4" @submit.prevent="submit">
            <TextField
                size="lg"
                :model-value="email ?? ''"
                label="Work email"
                type="email"
                autocomplete="username"
                disabled
            />

            <TextField
                size="lg"
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />

            <TextField
                size="lg"
                v-model="form.password_confirmation"
                label="Confirm password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <PasswordChecklist :password="form.password" />

            <AppButton
                type="submit"
                size="lg"
                block
                :loading="form.processing"
                class="!mt-6"
            >
                Get started
            </AppButton>
        </form>
    </AuthLayout>

    <AuthLayout
        v-else
        eyebrow="Invitation"
        heading="This link no longer works"
        lede="It has already been used, a newer one has been sent, or it has run out. Ask the people team to send you another. If you have signed in before, you can sign in as usual."
    >
        <a href="/login">
            <AppButton size="lg" block>Go to sign in</AppButton>
        </a>
    </AuthLayout>
</template>
