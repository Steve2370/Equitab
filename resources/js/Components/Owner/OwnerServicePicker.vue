<script setup lang="ts">
import { computed, ref } from "vue";
import ServiceBrandMark from "@/Components/Experience/ServiceBrandMark.vue";
import type { OwnerSubscription } from "@/types/group-draft";
import { formatGroupMoney, memberLimit } from "@/utils/groupDraft";

const props = defineProps<{ subscriptions: OwnerSubscription[]; selected: number | null; disabled: boolean; error?: string }>();
defineEmits<{ select: [subscription: OwnerSubscription] }>();
const search = ref("");
const filtered = computed(() => {
    const needle = search.value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("fr-CA").trim();
    return props.subscriptions.filter((service) => `${service.name} ${service.category}`
        .normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("fr-CA").includes(needle));
});
</script>

<template>
    <section aria-labelledby="owner-service-title">
        <p class="owner-eyebrow">01 · Le service</p>
        <h2 id="owner-service-title" tabindex="-1" class="owner-step-title">Quel abonnement partagez-vous ?</h2>
        <p class="owner-intro">Choisissez votre service. Vous pourrez ajuster le prix et préparer votre groupe avant d’activer les paiements.</p>
        <label class="owner-label" for="owner-service-search">Rechercher un service</label>
        <input id="owner-service-search" v-model="search" class="owner-input" type="search" placeholder="Nom du service ou catégorie" :disabled="disabled" />
        <p id="owner-error-subscription_id" v-if="error" class="owner-error">{{ error }}</p>
        <p class="owner-hint" role="status">{{ filtered.length }} service{{ filtered.length > 1 ? 's' : '' }}</p>
        <div class="owner-service-grid" role="group" aria-label="Services disponibles" :aria-describedby="error ? 'owner-error-subscription_id' : undefined">
            <button v-for="service in filtered" :key="service.id" type="button" class="owner-service-card"
                :class="{ 'is-selected': selected === service.id }" :aria-pressed="selected === service.id"
                :disabled="disabled || memberLimit(service) < 2" @click="$emit('select', service)">
                <span class="owner-service-top"><ServiceBrandMark :slug="service.slug" :name="service.name" /><span class="owner-selection" aria-hidden="true">{{ selected === service.id ? '✓' : '+' }}</span></span>
                <span class="owner-service-name">{{ service.name }}</span>
                <span class="owner-hint">{{ service.category }} · {{ service.tier }}</span>
                <span class="owner-service-price">{{ formatGroupMoney(service.monthly_price, service.currency) }} <span>/ mois</span></span>
                <span class="owner-hint">Prix du catalogue · {{ service.currency }}</span>
                <span class="owner-service-capacity">{{ memberLimit(service) >= 2 ? `Jusqu’à ${memberLimit(service)} personnes, vous compris` : 'Partage indisponible pour cette offre' }}</span>
            </button>
        </div>
        <div v-if="!filtered.length" class="owner-empty">
            <p>{{ subscriptions.length ? 'Aucun service ne correspond à votre recherche.' : 'Le catalogue est momentanément vide. Vous pouvez enregistrer votre brouillon et revenir plus tard.' }}</p>
            <button v-if="search" type="button" class="owner-button owner-button-secondary" @click="search = ''">Effacer la recherche</button>
        </div>
    </section>
</template>
