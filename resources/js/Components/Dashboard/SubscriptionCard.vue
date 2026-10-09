<script setup lang="ts">
import { computed } from "vue";

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

const progressPercent = computed(() =>
    Math.min(
        100,
        Math.max(
            0,
            Math.round(
                ((props.cycleDays - props.daysUntilNextPayment) /
                    props.cycleDays) *
                    100,
            ),
        ),
    ),
);
</script>

<template>
    <div class="rounded-xl border border-gray-100 bg-white p-4">
        <div class="flex items-center gap-3">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-equitab-navy">
                    {{ serviceName }}
                </p>
                <p class="text-xs text-gray-400">{{ category }}</p>
            </div>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            Prochain paiement dans {{ daysUntilNextPayment }} jour{{
                daysUntilNextPayment > 1 ? "s" : ""
            }}
        </p>
        <div class="mt-1 h-1 overflow-hidden rounded-full bg-gray-100">
            <div
                class="h-full rounded-full bg-equitab-emerald"
                :style="{ width: `${progressPercent}%` }"
            />
        </div>
    </div>
</template>
