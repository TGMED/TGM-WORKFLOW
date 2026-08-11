<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/ui/AppButton.vue';
import Avatar from '@/components/ui/Avatar.vue';

defineProps<{ avatarUrl: string | null; initials: string }>();

const input = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);

/**
 * The photo posts on its own the moment it is picked, rather than riding
 * along with the profile form. A file input cannot be repopulated after a
 * validation error, so keeping it separate means a rejected form never
 * silently drops the photo someone chose.
 */
function onPick(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    error.value = null;
    uploading.value = true;

    router.post(
        '/profile/photo',
        { photo: file },
        {
            preserveScroll: true,
            forceFormData: true,
            onError: (errors) => {
                error.value = errors.photo ?? 'That photo could not be saved.';
            },
            onFinish: () => {
                uploading.value = false;

                if (input.value) {
                    input.value.value = '';
                }
            },
        },
    );
}

function remove() {
    router.delete('/profile/photo', { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col items-center gap-3">
        <img
            v-if="avatarUrl"
            :src="avatarUrl"
            alt=""
            class="size-20 rounded-xl object-cover ring-1 ring-line"
        />
        <Avatar v-else :initials="initials" size="xl" />

        <div class="flex items-center gap-2">
            <AppButton
                size="sm"
                variant="secondary"
                :loading="uploading"
                @click="input?.click()"
            >
                Choose file...
            </AppButton>

            <AppButton
                v-if="avatarUrl"
                size="sm"
                variant="ghost"
                @click="remove"
            >
                Remove
            </AppButton>
        </div>

        <input
            ref="input"
            type="file"
            class="sr-only"
            accept="image/jpeg,image/png,image/webp"
            @change="onPick"
        />

        <p v-if="error" class="animate-fade-in text-[13px] text-alert">
            {{ error }}
        </p>
        <p v-else class="text-[12.5px] text-faint">
            JPG, PNG or WebP, under 4 MB.
        </p>
    </div>
</template>
