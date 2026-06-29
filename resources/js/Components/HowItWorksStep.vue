<script setup lang="ts">
import { type Component } from 'vue';

interface Props {
    icon: Component;
    title: string;
    description?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="flex items-start gap-4 border-b border-gray-100 py-5 last:border-b-0">
        <component :is="icon" class="mt-0.5 h-5 w-5 shrink-0 text-equitab-emerald" />
        <div>
            <p class="font-semibold text-equitab-navy">{{ title }}</p>
            <p v-if="description" class="mt-1 text-sm text-gray-500">{{ description }}</p>
        </div>
    </div>
</template>
