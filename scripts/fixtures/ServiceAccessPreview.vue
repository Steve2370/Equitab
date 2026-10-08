<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PaymentSuccess from '@/Pages/PaymentSuccess.vue';
import CredentialsModal from '@/Components/CredentialsModal.vue';
import type { ServiceAccessSnapshot } from '@/types/service-access';

const scenarios = [
    { id: 1, name: 'En attente → identifiants (3 s)' },
    { id: 2, name: 'En attente → invitation (3 s)' },
    { id: 3, name: 'En attente → courriel Bitwarden (3 s)' },
    { id: 4, name: 'Attente continue → pause (2 min)' },
    { id: 5, name: 'Propriétaire → lien (15 s)' },
    { id: 6, name: 'Propriétaire → courriel (15 s)' },
    { id: 7, name: 'Accès refusé (403)' },
    { id: 8, name: 'Accès indisponible' },
    { id: 9, name: 'Panne temporaire (503)' },
    { id: 10, name: 'Délai réseau dépassé (10 s)' },
    { id: 11, name: 'Identifiants disponibles' },
    { id: 12, name: 'Lien disponible' },
    { id: 13, name: 'Courriel fournisseur envoyé' },
    { id: 14, name: 'Textes longs' },
];
const scenario = ref(1);
const run = ref(Math.floor(Math.random() * 80_000));
const currency = ref('CAD');
const surface = ref<'page' | 'modal'>('page');
const modalOpen = ref(false);
const navigationNotice = ref(false);
const invitationMode = computed(() => [2, 3, 5, 6, 12, 13].includes(scenario.value));
const emailMode = computed(() => [3, 6, 13].includes(scenario.value));
const group = computed(() => ({
    id: scenario.value * 100_000 + run.value,
    name: 'Groupe de démonstration',
    subscriptionName: emailMode.value ? 'Bitwarden · démonstration' : invitationMode.value ? 'Dropbox · démonstration' : 'Spotify · démonstration',
    subscriptionSlug: emailMode.value ? 'bitwarden-families' : invitationMode.value ? 'dropbox-family' : 'spotify',
    ownerName: 'Propriétaire fictif', pricePerMember: 789, currency: currency.value,
    renewalDate: '01 nov. 2026', memberStatus: 'pending_payment',
}));
const snapshot = computed<ServiceAccessSnapshot>(() => ({
    status: [1, 2, 3, 4].includes(scenario.value) ? 'payment_pending'
        : [5, 6].includes(scenario.value) ? 'awaiting_owner'
            : scenario.value >= 11 ? 'ready' : 'unavailable',
    mode: invitationMode.value ? 'invitation' : 'credentials', credentials: null, invitation: null,
}));
function restart() {
    modalOpen.value = false;
    run.value = (run.value + 1) % 99_999;
    navigationNotice.value = false;
}
function select(id: number) { scenario.value = id; restart(); }
function changeSurface(value: 'page' | 'modal') { surface.value = value; restart(); }
function preventNavigationNotice() { navigationNotice.value = true; }
onMounted(() => window.addEventListener('service-access-preview-navigation', preventNavigationNotice));
onBeforeUnmount(() => window.removeEventListener('service-access-preview-navigation', preventNavigationNotice));
</script>

<template>
    <header class="access-preview-toolbar">
        <strong>Recette locale · données fictives · aucun paiement, envoi ou accès réel</strong>
        <p>Les requêtes utilisent uniquement l’API fictive locale. Les liens de navigation et d’invitation ne quittent pas cet aperçu.</p>
        <div class="access-preview-controls">
            <button type="button" :aria-pressed="surface === 'page'" @click="changeSurface('page')">Page après paiement</button>
            <button type="button" :aria-pressed="surface === 'modal'" @click="changeSurface('modal')">Fenêtre d’accès</button>
            <label>Devise <select v-model="currency"><option>CAD</option><option>EUR</option></select></label>
            <button type="button" @click="restart">Rejouer le scénario</button>
        </div>
        <nav aria-label="Scénarios de recette" class="access-preview-controls">
            <button v-for="item in scenarios" :key="item.id" type="button" :aria-pressed="scenario === item.id" @click="select(item.id)">{{ item.name }}</button>
        </nav>
        <p v-if="navigationNotice" role="status">Navigation neutralisée : ceci est une démonstration locale.</p>
    </header>
    <PaymentSuccess v-if="surface === 'page'" :key="group.id" :group="group" :credentials="null" :service-access="snapshot" />
    <main v-else class="access-preview-modal-trigger">
        <h1>Fenêtre d’accès · {{ scenarios.find(item => item.id === scenario)?.name }}</h1>
        <p>Ouvrez la fenêtre puis testez Tab, Échap et la restitution du focus au bouton.</p>
        <button type="button" class="eq-button" @click="modalOpen = true">Ouvrir mes accès</button>
        <CredentialsModal v-if="modalOpen" :key="group.id" :group-id="group.id" :subscription-name="group.subscriptionName" @close="modalOpen = false" />
    </main>
</template>

<style>
.access-preview-toolbar { padding: 16px; border-bottom: 1px solid #dce5df; background: white; color: #303b37; font-size: 12px; }
.access-preview-toolbar p { margin-top: 8px; }
.access-preview-controls { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.access-preview-controls button, .access-preview-controls select { min-height: 44px; padding: 8px 12px; border: 1px solid #187a57; border-radius: 8px; background: white; }
.access-preview-controls button[aria-pressed="true"] { background: #187a57; color: white; }
.access-preview-controls label { display: flex; align-items: center; gap: 8px; }
.access-preview-controls :focus-visible { outline: 2px solid #187a57; outline-offset: 3px; }
.access-preview-modal-trigger { max-width: 620px; padding: 24px; margin: 24px auto; color: #303b37; }
.access-preview-modal-trigger p { margin: 16px 0; }
</style>
