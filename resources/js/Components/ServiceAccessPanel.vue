<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Copy, Eye, EyeOff, ExternalLink, Key, Lock, RefreshCw } from 'lucide-vue-next';
import { safeInvitationUrl } from '@/composables/useServiceAccess';
import type { ServiceAccess, ServiceAccessStatus } from '@/types/service-access';

const props = defineProps<{
    access: ServiceAccess | null;
    status: ServiceAccessStatus | null;
    loading: boolean;
    error: string | null;
    timedOut: boolean;
}>();
const emit = defineEmits<{ retry: [] }>();
const showPassword = ref(false);
const copied = ref<'email' | 'password' | null>(null);
const clipboardError = ref(false);
let feedbackTimer: ReturnType<typeof setTimeout> | undefined;
let copyGeneration = 0;
const credentials = computed(() => props.status === 'ready' && props.access?.status === 'ready'
    && props.access.mode === 'credentials' ? props.access.credentials : null);
const invitation = computed(() => props.status === 'ready' && props.access?.status === 'ready'
    && props.access.mode === 'invitation' ? props.access.invitation : null);
const invitationUrl = computed(() => invitation.value?.channel === 'link' ? safeInvitationUrl(invitation.value.url) : null);
const emailInvitation = computed(() => invitation.value?.channel === 'provider_email'
    && invitation.value.url === null && invitation.value.recipient_email?.trim() ? invitation.value : null);
const available = computed(() => !props.error && !props.loading && (credentials.value || invitationUrl.value || emailInvitation.value));

function resetDisclosure() {
    copyGeneration++;
    clearTimeout(feedbackTimer);
    showPassword.value = false;
    copied.value = null;
    clipboardError.value = false;
}
watch(() => [props.access, props.status, props.error, props.loading], resetDisclosure, { flush: 'sync' });
onBeforeUnmount(resetDisclosure);

async function copy(field: 'email' | 'password') {
    if (!available.value) return;
    const value = credentials.value?.[field];
    if (!value) return;
    clearTimeout(feedbackTimer);
    const run = ++copyGeneration;
    copied.value = null;
    clipboardError.value = false;
    try {
        await navigator.clipboard.writeText(value);
        if (run !== copyGeneration) return;
        copied.value = field;
        feedbackTimer = setTimeout(() => { copied.value = null; }, 2_000);
    } catch {
        if (run === copyGeneration) clipboardError.value = true;
    }
}
</script>

<template>
    <section class="min-w-0 space-y-4" aria-label="Accès au service" :aria-busy="loading">
        <div aria-live="polite" aria-atomic="true">
            <p v-if="error" role="alert" class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-equitab-navy">{{ error }}</p>
            <div v-else-if="status === 'payment_pending'" class="space-y-2 text-sm text-gray-600">
                <p class="font-semibold text-equitab-navy">Confirmation du paiement en cours</p>
                <p>Vos accès s’afficheront dès que le serveur aura confirmé votre paiement et activé votre abonnement. Ne payez pas une seconde fois.</p>
            </div>
            <div v-else-if="status === 'awaiting_owner'" class="space-y-2 text-sm text-gray-600">
                <p class="font-semibold text-equitab-navy">Accès en préparation</p>
                <p>Votre abonnement est actif. Le propriétaire doit encore fournir vos accès. Ils s’afficheront ici dès leur mise à disposition.</p>
            </div>
            <p v-else-if="status === 'unavailable' || (status === 'ready' && !available && !loading)" class="text-sm text-gray-600">
                Les accès ne sont pas disponibles. Consultez votre abonnement dans votre espace ou contactez le propriétaire.
            </p>
            <p v-else-if="!available" class="flex items-center gap-2 text-sm text-gray-600">
                <RefreshCw v-if="loading" class="h-4 w-4 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                Vérification de vos accès…
            </p>
            <p v-if="timedOut" class="mt-3 text-sm text-gray-600">
                La vérification automatique est en pause. Vous pouvez la relancer ou revenir depuis votre espace. Aucun nouveau paiement n’est nécessaire.
            </p>
        </div>

        <div v-if="available" class="space-y-3">
            <p class="flex items-start gap-2 rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
                <Lock class="h-4 w-4 shrink-0 text-equitab-emerald" aria-hidden="true" />
                Accès réservés à votre abonnement actif. Gardez ces informations confidentielles.
            </p>
            <template v-if="credentials">
                <h2 class="flex items-center gap-2 font-semibold text-equitab-navy"><Key class="h-4 w-4" aria-hidden="true" />Accès par identifiants</h2>
                <div v-if="credentials.email" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-4 py-3">
                    <div class="min-w-0 flex-1 basis-36">
                        <p class="text-xs text-gray-500">Courriel / Identifiant</p>
                        <p class="break-all font-medium text-equitab-navy">{{ credentials.email }}</p>
                    </div>
                    <button type="button" aria-label="Copier l’identifiant" @click="copy('email')" class="flex min-h-11 items-center gap-1 rounded-md px-2 text-xs text-equitab-navy hover:text-equitab-emerald focus-visible:outline-2 focus-visible:outline-equitab-emerald">
                        <Copy class="h-4 w-4" aria-hidden="true" />{{ copied === 'email' ? 'Copié !' : 'Copier' }}
                    </button>
                </div>
                <div v-if="credentials.password" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-4 py-3">
                    <div class="min-w-0 flex-1 basis-36">
                        <p class="text-xs text-gray-500">Mot de passe</p>
                        <p class="break-all font-mono font-medium text-equitab-navy">{{ showPassword ? credentials.password : '••••••••••' }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" :aria-pressed="showPassword" @click="showPassword = !showPassword" class="flex h-11 w-11 items-center justify-center rounded-md text-gray-600 hover:text-equitab-navy focus-visible:outline-2 focus-visible:outline-equitab-emerald">
                            <EyeOff v-if="showPassword" class="h-4 w-4" aria-hidden="true" /><Eye v-else class="h-4 w-4" aria-hidden="true" />
                        </button>
                        <button type="button" aria-label="Copier le mot de passe" @click="copy('password')" class="flex min-h-11 items-center gap-1 rounded-md px-2 text-xs text-equitab-navy hover:text-equitab-emerald focus-visible:outline-2 focus-visible:outline-equitab-emerald">
                            <Copy class="h-4 w-4" aria-hidden="true" />{{ copied === 'password' ? 'Copié !' : 'Copier' }}
                        </button>
                    </div>
                </div>
                <div v-if="credentials.notes" class="rounded-lg border border-gray-200 bg-white px-4 py-3">
                    <p class="text-xs font-medium text-gray-600">Note du propriétaire</p>
                    <p class="mt-1 whitespace-pre-wrap break-words text-sm text-equitab-navy">{{ credentials.notes }}</p>
                </div>
                <p v-if="copied" role="status" class="sr-only">{{ copied === 'email' ? 'Identifiant copié.' : 'Mot de passe copié.' }}</p>
                <p v-if="clipboardError" role="alert" class="text-sm text-gray-600">La copie n’a pas fonctionné. Vous pouvez sélectionner et copier le texte manuellement.</p>
                <p class="text-xs text-gray-600">Connectez-vous sur le site ou l’application du service avec ces identifiants. En cas de difficulté, contactez le propriétaire.</p>
            </template>
            <div v-else-if="emailInvitation" class="space-y-3">
                <h2 class="font-semibold text-equitab-navy">Invitation envoyée par le fournisseur</h2>
                <p class="text-sm text-gray-600">Le propriétaire indique qu’une invitation a été envoyée à <span class="break-all font-medium text-equitab-navy">{{ emailInvitation.recipient_email }}</span>. Consultez votre boîte courriel, y compris les indésirables, et acceptez l’invitation sur le site du fournisseur.</p>
                <p class="text-sm text-gray-600">Pour Bitwarden, le propriétaire doit ensuite confirmer votre adhésion dans Bitwarden. Cet envoi ne garantit pas encore que votre accès fonctionne.</p>
                <p class="text-xs text-gray-600">Vous n’avez rien à confirmer dans EquitAb. Si vous ne recevez pas le courriel ou ne pouvez pas accéder au service, contactez le propriétaire. Ne partagez jamais votre mot de passe maître.</p>
            </div>
            <div v-else-if="invitationUrl" class="space-y-3">
                <h2 class="font-semibold text-equitab-navy">Votre invitation est disponible</h2>
                <p class="text-sm text-gray-600">Ouvrez l’invitation et suivez les étapes du fournisseur avec votre compte personnel. Ne communiquez jamais votre mot de passe personnel ou votre mot de passe maître.</p>
                <a :href="invitationUrl" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-equitab-emerald px-4 py-3 text-center text-sm font-medium text-white hover:bg-equitab-emerald-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-equitab-emerald">
                    Ouvrir mon invitation <ExternalLink class="h-4 w-4 shrink-0" aria-hidden="true" /><span class="sr-only">(nouvel onglet)</span>
                </a>
                <p class="text-xs text-gray-600">Si l’invitation est invalide ou expirée, contactez le propriétaire depuis votre espace.</p>
            </div>
        </div>
        <button type="button" :disabled="loading" @click="emit('retry')" class="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-equitab-navy hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-equitab-emerald disabled:cursor-wait disabled:opacity-60">
            <RefreshCw class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ loading ? 'Vérification en cours…' : available ? 'Actualiser les accès' : 'Vérifier à nouveau' }}
        </button>
    </section>
</template>
