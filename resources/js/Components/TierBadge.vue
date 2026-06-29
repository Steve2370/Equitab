<script setup lang="ts">
interface Props {
    tier: 'standard' | 'premium' | 'famille';
}

const props = defineProps<Props>();

const labels: Record<Props['tier'], string> = {
    standard: 'Standard',
    premium: 'Premium',
    famille: 'Famille',
};

const styles: Record<Props['tier'], string> = {
    standard: 'bg-gray-100 text-gray-700',
    premium: 'bg-equitab-emerald/10 text-equitab-emerald',
    famille: 'bg-equitab-navy/10 text-equitab-navy',
};
</script>

<template>
    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="styles[tier]">
        {{ labels[tier] }}
    </span>
</template>
