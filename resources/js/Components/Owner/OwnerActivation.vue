<script setup lang="ts">
import type { OwnerActivation, OwnerReadiness } from "@/types/group-draft";
defineProps<{ readiness: OwnerReadiness; disabled: boolean; operation: string }>();
defineEmits<{ activate: [kind: OwnerActivation]; refresh: [] }>();

function identityLabel(status: string): string {
    return ({ pending: "Vérification en cours", processing: "Vérification en cours", requires_input: "Renseignements à compléter", unverified: "À vérifier", rejected: "Vérification à reprendre" })[status] ?? "Vérification à compléter";
}
function connectLabel(status: string): string {
    return ({ pending: "Activation en cours", restricted: "Renseignements à compléter", incomplete: "Configuration à compléter", not_started: "À activer" })[status] ?? "Activation à compléter";
}
</script>

<template>
    <section aria-label="Activation du compte propriétaire" class="owner-activation">
        <p v-if="readiness.ready && readiness.identityVerified && readiness.connectActive" class="owner-ready">✓ Votre identité est vérifiée et vos paiements sont actifs. Vous pouvez passer à la publication.</p>
        <template v-else>
            <p class="owner-intro">Pour publier, votre identité doit être vérifiée et votre compte de paiements actif. Votre brouillon sera enregistré avant chaque départ vers Stripe.</p>
            <div class="owner-activation-row">
                <div><h3>1. Vérifier votre identité</h3><p class="owner-hint">{{ readiness.identityVerified ? 'Identité vérifiée' : identityLabel(readiness.identityStatus) }}</p></div>
                <span v-if="readiness.identityVerified" class="owner-done">✓ Vérifiée</span>
                <button v-else type="button" class="owner-button owner-button-secondary" :disabled="disabled" @click="$emit('activate', 'identity')">{{ operation === 'identity' ? 'Enregistrement et ouverture…' : 'Vérifier mon identité' }}</button>
            </div>
            <div class="owner-activation-row">
                <div><h3>2. Activer les paiements</h3><p class="owner-hint">{{ readiness.connectActive ? 'Compte de paiements actif' : connectLabel(readiness.connectStatus) }}</p></div>
                <span v-if="readiness.connectActive" class="owner-done">✓ Actifs</span>
                <button v-else type="button" class="owner-button owner-button-secondary" :disabled="disabled" @click="$emit('activate', 'connect')">{{ operation === 'connect' ? 'Enregistrement et ouverture…' : 'Activer avec Stripe' }}</button>
            </div>
            <p class="owner-hint">Vous reviendrez à votre brouillon après l’activation des paiements. Après la vérification d’identité, reprenez-le depuis votre profil. Aucun retour ne publie le groupe automatiquement.</p>
            <button type="button" class="owner-text-button" :disabled="disabled" @click="$emit('refresh')">J’ai terminé : actualiser mon statut</button>
        </template>
    </section>
</template>
