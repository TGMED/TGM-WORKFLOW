<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppButton from '@/components/ui/AppButton.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

/**
 * Where every error lands. Rendered by App\Support\ErrorPage, which has
 * already chosen words the reader can act on.
 */
defineProps<{
    status: number;
    title: string;
    message: string;
}>();

// A full load rather than an Inertia visit: whatever went wrong may have left
// the page's state in no condition to be carried forward.
function back() {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    window.location.assign('/dashboard');
}
</script>

<template>
    <Head :title="title" />

    <AuthLayout :eyebrow="`Error ${status}`" :heading="title" :lede="message">
        <div class="flex flex-col gap-3 sm:flex-row">
            <AppButton size="lg" variant="secondary" block @click="back">
                Go back
            </AppButton>
            <!-- The dashboard sends anybody signed out to sign in, so one
                 link serves both. -->
            <a href="/dashboard" class="block w-full">
                <AppButton size="lg" block>Go to the dashboard</AppButton>
            </a>
        </div>
    </AuthLayout>
</template>
