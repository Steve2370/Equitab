<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    serviceName: string;
    category: string;
    brandColor: string;
    daysUntilNextPayment: number;
    cycleDays?: number;
}

const props = withDefaults(defineProps<Props>(), {
    cycleDays: 30,
});

const initial = computed(() => props.serviceName.charAt(0).toUpperCase());

const progressPercent = computed(() =>
    Math.min(
        100,
        Math.max(
            0,
            Math.round(((props.cycleDays - props.daysUntilNextPayment) / props.cycleDays) * 100)
        )
    )
);

function contrastColor(hex: string): string {
    const clean = hex.replace('#', '');
    const r = parseInt(clean.substring(0, 2), 16);
    const g = parseInt(clean.substring(2, 4), 16);
    const b = parseInt(clean.substring(4, 6), 16);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#111827' : '#ffffff';
}

const badgeTextColor = computed(() => contrastColor(props.brandColor));
</script>

<template>
    <div class="rounded-xl border border-gray-100 bg-white p-4">
        <div class="flex items-center gap-3">
            <div
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-semibold"
                :style="{ backgroundColor: brandColor, color: badgeTextColor }"
            >
                {{ initial }}
            </div>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-equitab-navy">{{ serviceName }}</p>
                <p class="text-xs text-gray-400">{{ category }}</p>
            </div>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            Prochain paiement dans {{ daysUntilNextPayment }}
            jour{{ daysUntilNextPayment > 1 ? 's' : '' }}
        </p>
        <div class="mt-1 h-1 overflow-hidden rounded-full bg-gray-100">
            <div
                class="h-full rounded-full"
                :style="{ width: `${progressPercent}%`, backgroundColor: brandColor }"
            />
        </div>
    </div>
</template>
