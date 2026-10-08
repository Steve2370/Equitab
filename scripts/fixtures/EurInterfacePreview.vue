<script setup lang="ts">
import { reactive, ref } from 'vue';
import OwnerPreparation from '@/Components/Owner/OwnerPreparation.vue';
import OwnerGroupPreview from '@/Components/Owner/OwnerGroupPreview.vue';
import CollectionCard from '@/Components/Experience/CollectionCard.vue';
import CatalogServiceCard from '@/Components/Experience/CatalogServiceCard.vue';
import StripeCardForm from '@/Components/StripeCardForm.vue';
import InvitePage from '@/Pages/InvitePage.vue';
import PaymentSuccess from '@/Pages/PaymentSuccess.vue';
import { createDraftController, createDraftState, isDraftSaved, serviceDefaults } from '@/utils/groupDraft';
import '@/Components/Owner/owner.css';

const mode = ref('Brouillon');
const modes = ['Brouillon', 'Cartes', 'Invitation', 'Paiement', 'Succès'];
const service = { id: 3, name: 'Netflix · démonstration', slug: 'netflix', tier: 'famille' as const, category: 'Test', monthly_price: 1599, currency: 'CAD', max_members: 6 };
const legacyData = { ...serviceDefaults(service), renewal_date: '2099-12-01' };
delete legacyData.currency;
const legacy = { id: 'synthetic', version: 1, status: 'draft' as const, data: legacyData, updated_at: '2026-10-07T00:00:00Z', published_group_id: null, preview: { currency: 'CAD', total_price: 1599, max_members: 6, full_group_share: 267 } };
const state = reactive(createDraftState(legacy));
const unavailable = async (): Promise<never> => { throw new Error('Transport désactivé dans la recette visuelle'); };
const controller = createDraftController(state, { create: unavailable, update: unavailable, publish: unavailable, reopen: unavailable, activate: unavailable }, () => 'synthetic');
const group = { id: 15, name: 'Groupe de démonstration', subscriptionName: 'Netflix · démonstration', subscriptionSlug: 'netflix', ownerName: 'Propriétaire fictif', ownerTrustScore: null, pricePerMember: 789, currency: 'EUR', spotsAvailable: 2, maxMembers: 6, renewalDate: '01/11/2026', memberStatus: 'active' };
</script>

<template>
    <header class="preview-toolbar">
        <strong>Recette locale · données fictives · aucun paiement ni enregistrement</strong>
        <nav aria-label="Écrans de recette"><button v-for="item in modes" :key="item" type="button" :aria-pressed="mode === item" @click="mode = item">{{ item }}</button></nav>
    </header>
    <main v-if="mode === 'Brouillon'" class="owner-flow preview-owner">
        <p role="status">{{ isDraftSaved(state) ? 'Ancien brouillon repris sans modification.' : 'Saisie modifiée.' }}</p>
        <div class="owner-content with-preview">
            <OwnerPreparation v-model="state.data" :subscription="service" :errors="state.errors" :disabled="false" :supported-currencies="['CAD', 'EUR']" :enabled-currencies="['CAD', 'EUR']" :price-notice="state.priceNotice" @currency-change="controller.changeCurrency($event, ['CAD', 'EUR'])" />
            <OwnerGroupPreview :data="state.data" :subscription="service" :preview="isDraftSaved(state) ? legacy.preview : null" />
        </div>
    </main>
    <main v-else-if="mode === 'Cartes'" class="preview-cards">
        <CatalogServiceCard :name="service.name" :slug="service.slug" category="Catalogue · démonstration" :monthly-price="service.monthly_price" :max-members="service.max_members" :currency="service.currency" :motion="false" />
        <CollectionCard name="Groupe EUR · démonstration" slug="netflix" :price="789" currency="EUR" :members="4" :capacity="6" />
        <CollectionCard name="Groupe CAD · démonstration" slug="spotify" :price="123456" currency="CAD" :members="4" :capacity="6" />
    </main>
    <InvitePage v-else-if="mode === 'Invitation'" :group="group" invite-token="synthetic" access-state="guest" continue-url="#" />
    <main v-else-if="mode === 'Paiement'" class="preview-payment"><StripeCardForm :group-id="15" subscription-name="Démonstration EUR" :amount-today="321" :price-per-member="789" currency="EUR" /></main>
    <PaymentSuccess v-else :group="group" :credentials="null" />
</template>

<style>
.preview-toolbar { padding: 16px; border-bottom: 1px solid #dce5df; font-size: 12px; }
.preview-toolbar nav { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
.preview-toolbar button { padding: 8px 12px; border: 1px solid #187a57; border-radius: 8px; }
.preview-toolbar button[aria-pressed="true"] { background: #187a57; color: white; }
.preview-owner { padding: 24px; margin: auto; }
.preview-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 24px; max-width: 1200px; margin: 24px auto; padding: 16px; }
.preview-payment { max-width: 540px; padding: 16px; margin: 24px auto; }
</style>
