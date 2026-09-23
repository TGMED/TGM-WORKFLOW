<script setup lang="ts">
defineProps<{ documents: Array<{ id: number; name: string }> }>();
</script>

<template>
    <ul v-if="documents.length" class="space-y-1">
        <li v-for="doc in documents" :key="doc.id">
            <a
                :href="`/attachments/${doc.id}`"
                class="text-[13px] font-medium text-beacon hover:underline"
            >
                {{ doc.name }}
            </a>
        </li>
    </ul>
    <p v-else class="text-[12.5px] text-faint">No documents attached.</p>
</template>
