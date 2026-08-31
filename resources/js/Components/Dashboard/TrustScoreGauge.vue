<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    score: number;
    size?: number;
}

const props = withDefaults(defineProps<Props>(), {
    size: 56,
});

const radius = 40;
const circumference = 2 * Math.PI * radius;

const clampedScore = computed(() => Math.min(100, Math.max(0, props.score)));

const dashOffset = computed(
    () => circumference - (clampedScore.value / 100) * circumference
);

const tier = computed(() => {
    if (clampedScore.value >= 80) return 'Or';
    if (clampedScore.value >= 60) return 'Argent';
    if (clampedScore.value >= 40) return 'Bronze';
    return 'Débutant';
});
</script>

<template>
    <div class="flex items-center gap-4">
        <svg :width="size" :height="size" viewBox="0 0 100 100" aria-hidden="true">
            <circle
                cx="50"
                cy="50"
                r="40"
                fill="none"
                stroke="currentColor"
                stroke-width="10"
                class="text-gray-100"
            />
            <circle
                cx="50"
                cy="50"
                r="40"
                fill="none"
                stroke="currentColor"
                stroke-width="10"
                stroke-linecap="round"
                class="text-equitab-emerald"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
                transform="rotate(-90 50 50)"
            />
            <text
                x="50"
                y="57"
                text-anchor="middle"
                font-size="26"
                font-weight="600"
                class="fill-equitab-navy"
            >
                {{ clampedScore }}
            </text>
        </svg>
        <div>
            <p class="text-sm text-gray-500">Score de confiance</p>
            <p class="text-sm font-semibold text-equitab-emerald">Palier {{ tier }}</p>
        </div>
    </div>
</template>
