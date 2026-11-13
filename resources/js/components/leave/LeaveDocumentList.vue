<script setup lang="ts">
import { dateTime } from '@/lib/format';
import type { LeaveDocument } from '@/types';

defineProps<{ documents: LeaveDocument[] }>();
</script>

<template>
    <ul v-if="documents.length" class="space-y-1">
        <li
            v-for="(document, index) in documents"
            :key="document.id ?? `booking-${index}`"
            class="text-[12px]"
        >
            <a
                :href="document.url"
                class="font-medium text-brand underline-offset-2 hover:underline"
            >
                {{ document.name }}
            </a>
            <span class="text-faint">
                ·
                {{
                    document.at_booking
                        ? 'attached at booking'
                        : `added${document.added_by ? ` by ${document.added_by}` : ''} ${dateTime(document.added_at)}`
                }}
            </span>
        </li>
    </ul>
</template>
