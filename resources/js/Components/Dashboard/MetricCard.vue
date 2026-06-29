<script setup lang="ts">
import { type Component } from "vue";

interface Props {
    label: string;
    value: string;
    sublabel?: string;
    subhref?: string;
    icon: Component;
    variant?: "default" | "success" | "info";
}

withDefaults(defineProps<Props>(), {
    variant: "default",
});

const variantStyles = {
    default: "text-equitab-navy",
    success: "text-equitab-emerald",
    info: "text-blue-600",
};
</script>

<template>
    <div class="rounded-xl border border-gray-100 bg-white p-6">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-gray-500">{{ label }}</p>
            <component :is="icon" class="h-4 w-4 text-gray-400" />
        </div>
        <p class="mt-2 text-2xl font-semibold" :class="variantStyles[variant]">
            {{ value }}
        </p>
        <a
            v-if="sublabel && subhref"
            :href="subhref"
            class="mt-1 text-xs text-gray-400 hover:text-gray-600"
        >
            {{ sublabel }} ->
        </a>
        <p v-else-if="sublabel && !subhref" class="mt-1 text-xs text-gray-400">
            {{ sublabel }}
        </p>
    </div>
</template>
