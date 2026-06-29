<script setup lang="ts">
import { ShieldCheck } from 'lucide-vue-next';

interface Props {
    isVerified: boolean;
}

defineProps<Props>();
</script>

<template>
    <span
        v-if="isVerified"
        class="inline-flex items-center gap-1 rounded-full bg-equitab-emerald/10 px-2 py-0.5 text-xs font-medium text-equitab-emerald"
    >
        <ShieldCheck class="h-3.5 w-3.5" />
        Vérifié
    </span>
    <span
        v-else
        class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500"
    >
        Non vérifié
    </span>
</template>
