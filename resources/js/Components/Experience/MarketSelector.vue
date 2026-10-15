<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import CountryField from "./CountryField.vue";
import type { MarketState } from "@/types/market";

const page = usePage<{ market?: MarketState }>();
const market = computed(() => page.props.market);
const form = useForm({ country: market.value?.country ?? "" });
const saved = ref(false);
const dirty = computed(() => form.country !== (market.value?.country ?? ""));
watch(() => market.value?.country, (country) => { form.country = country ?? ""; });
watch(() => form.country, () => { saved.value = false; form.clearErrors(); });

function save(): void {
    if (form.processing || !form.country || !dirty.value) return;
    form.post("/market", {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { saved.value = true; },
    });
}
</script>

<template>
    <form v-if="market" class="market-selector" :aria-busy="form.processing" aria-label="Pays et devise de découverte" @submit.prevent="save">
        <CountryField v-model="form.country" label="Votre pays de découverte" :countries="market.countries" :error="form.errors.country" :disabled="form.processing" required />
        <button type="submit" :disabled="form.processing || !form.country || !dirty">{{ form.processing ? 'Enregistrement…' : 'Appliquer' }}</button>
        <p class="market-note" role="status">
            <template v-if="saved">Pays enregistré. </template>
            <template v-if="market.enabled">Ce choix propose les groupes dans cette devise, sans convertir vos abonnements ni modifier votre résidence Stripe.</template>
            <template v-else>Les nouveaux paiements en EUR sont temporairement indisponibles. Vos engagements existants restent inchangés.</template>
        </p>
    </form>
</template>

<style scoped>
.market-selector { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px 12px; align-items: start; width: 100%; max-width: 470px; min-width: 0; }
.market-selector button { margin-top: 24px; min-height: 44px; padding: 9px 14px; border: 1px solid #187a57; border-radius: 10px; background: #fff; color: #187a57; font-size: .8rem; font-weight: 600; }
.market-selector button:hover:not(:disabled) { background: #f3f8f5; }
.market-selector button:focus-visible { outline: 3px solid #187a57; outline-offset: 3px; }
.market-selector button:disabled { opacity: .55; cursor: not-allowed; }
.market-note { grid-column: 1 / -1; color: #59685f; font-size: .7rem; line-height: 1.5; overflow-wrap: anywhere; }
@media (max-width: 360px) { .market-selector { grid-template-columns: minmax(0, 1fr); } .market-selector button { margin-top: 0; } }
</style>
