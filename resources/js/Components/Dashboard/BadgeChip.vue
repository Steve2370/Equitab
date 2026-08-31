<script setup lang="ts">
import { computed } from 'vue';
import { Award, Clock, Users } from 'lucide-vue-next';

interface Props {
    label: string;
    icon: 'award' | 'clock' | 'users';
}

const props = defineProps<Props>();

const icons = { award: Award, clock: Clock, users: Users } as const;

const iconComponent = computed(() => icons[props.icon]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-600"
    >
        <component :is="iconComponent" class="h-3.5 w-3.5" />
        {{ label }}
    </span>
</template>
