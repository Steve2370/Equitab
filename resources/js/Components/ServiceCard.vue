<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getBrandGradient } from '@/config/brandGradients';

interface Props {
    name: string;
    slug: string;
    pricePerMember: number;
    discountPercent: number;
}

const props = defineProps<Props>();

const gradient = computed(() => getBrandGradient(props.slug));

const gradientStyle = computed(() => ({
    background: `linear-gradient(135deg, ${gradient.value.from}, ${gradient.value.to})`,
}));

const formattedPrice = computed(() =>
    new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(
        props.pricePerMember,
    ),
);
</script>

<template>
    <Link
        :href="`/groups/service/${slug}`"
        :style="gradientStyle"
        class="flex w-64 shrink-0 flex-col justify-between rounded-xl p-6 text-white transition-transform hover:scale-[1.02]"
    >
        <div>
            <p class="text-lg font-semibold">{{ name }}</p>
        </div>
        <div class="mt-8">
            <p class="text-2xl font-semibold">{{ formattedPrice }}<span class="text-sm font-normal"> / mois</span></p>
            <p class="mt-1 text-sm opacity-90">Économisez {{ discountPercent }} %</p>
        </div>
    </Link>
</template>
