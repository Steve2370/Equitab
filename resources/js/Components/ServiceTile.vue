<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowUpRight, Users } from "lucide-vue-next";
import { getBrandGradient } from "@/config/brandGradients";
defineProps<{
    name: string;
    slug: string;
    category?: string;
    monthlyPrice?: number;
    maxMembers?: number;
}>();
const formatPrice = (cents: number) =>
    new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(cents / 100);
</script>

<template>
    <Link
        :href="`/groups/service/${slug}`"
        class="eq-panel group flex h-full flex-col p-5 transition-colors hover:border-eq-green"
    >
        <div class="flex items-start justify-between gap-3">
            <span
                aria-hidden="true"
                class="flex h-12 w-12 items-center justify-center rounded-xl text-xl font-bold text-white"
                :style="{ backgroundColor: getBrandGradient(slug).to }"
                >{{ name.charAt(0) }}</span
            >
            <ArrowUpRight
                :size="18"
                class="text-eq-muted transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                aria-hidden="true"
            />
        </div>
        <h3 class="mt-5 text-base font-semibold tracking-tight">{{ name }}</h3>
        <p v-if="category" class="mt-1 text-xs text-eq-muted">{{ category }}</p>
        <div
            v-if="monthlyPrice !== undefined && maxMembers && maxMembers > 0"
            class="mt-5 border-t border-eq-line pt-4"
        >
            <p class="text-[11px] text-eq-muted">
                Part indicative · groupe complet
            </p>
            <p class="mt-1 text-xl font-semibold tracking-tight">
                {{ formatPrice(Math.round(monthlyPrice / maxMembers)) }}
                <span class="text-xs font-normal text-eq-muted"
                    >CAD / mois</span
                >
            </p>
            <p class="mt-3 flex items-center gap-1.5 text-xs text-eq-muted">
                <Users :size="14" aria-hidden="true" /> Jusqu’à
                {{ maxMembers }} membres
            </p>
        </div>
        <p class="mt-auto pt-5 text-xs font-semibold text-eq-green">
            Voir les groupes <span aria-hidden="true">→</span>
        </p>
    </Link>
</template>
