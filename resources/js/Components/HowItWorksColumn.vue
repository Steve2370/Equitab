<script setup lang="ts">
import { type Component } from 'vue';
import HowItWorksStep from '@/Components/HowItWorksStep.vue';

interface Step {
    icon: Component;
    title: string;
    description?: string;
}

interface Props {
    label: string;
    accentColor: 'navy' | 'emerald';
    steps: Step[];
}

defineProps<Props>();
</script>

<template>
    <div class="flex-1">
        <span
            class="inline-block rounded-full px-4 py-1.5 text-sm font-semibold"
            :class="accentColor === 'emerald'
                ? 'bg-equitab-emerald/10 text-equitab-emerald'
                : 'bg-equitab-navy/10 text-equitab-navy'"
        >
            {{ label }}
        </span>

        <div class="mt-4 rounded-xl border border-gray-100 px-5">
            <HowItWorksStep
                v-for="(step, i) in steps"
                :key="i"
                :icon="step.icon"
                :title="step.title"
                :description="step.description"
            />
        </div>
    </div>
</template>
