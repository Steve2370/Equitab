<script setup lang="ts">
import { type Component } from "vue";
import { Link } from "@inertiajs/vue3";

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
    default: "text-eq-ink",
    success: "text-eq-green",
    info: "text-eq-ink",
};
</script>

<template>
    <div class="eq-panel metric-card p-6" :class="'metric-' + variant">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-eq-muted">{{ label }}</p>
            <component
                :is="icon"
                class="ml-2 h-4 w-4 shrink-0 text-eq-muted"
                aria-hidden="true"
            />
        </div>
        <p
            class="mt-5 text-3xl tracking-tight font-medium"
            :class="variantStyles[variant]"
        >
            {{ value }}
        </p>
        <Link
            v-if="sublabel && subhref"
            :href="subhref"
            class="mt-2 inline-flex text-xs text-eq-muted hover:text-eq-green hover:underline"
        >
            {{ sublabel }} →
        </Link>
        <p v-else-if="sublabel && !subhref" class="mt-2 text-xs text-eq-muted">
            {{ sublabel }}
        </p>
    </div>
</template>
<style scoped>
.metric-card {
    min-width: 0;
    background: #fff;
    border-color: var(--color-eq-line);
}
.metric-card > div > p {
    font-size: 11px;
}
.metric-card > p {
    overflow-wrap: anywhere;
}
</style>
