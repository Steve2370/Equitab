<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { Shield, Users, Lock } from 'lucide-vue-next';
import { getBrandGradient } from '@/config/brandGradients';
import StripeCardForm from '@/Components/StripeCardForm.vue';
import EquitabWordmark from '@/Components/Experience/EquitabWordmark.vue';
import { formatMoney } from '@/utils/money';

interface Group {
    id: number;
    name: string;
    subscriptionName: string;
    subscriptionSlug: string;
    description?: string | null;
    ownerName: string;
    ownerTrustScore: number | null;
    pricePerMember: number;
    currency: string;
    spotsAvailable: number;
    maxMembers: number;
}

interface Props {
    group: Group;
    inviteToken: string;
    accessState: 'guest' | 'verify_email' | 'checkout' | 'owner' | 'member' | 'full' | 'unavailable';
    continueUrl: string;
}

const props = defineProps<Props>();
const showForm = ref(false);
const canCheckout = computed(() => props.accessState === 'checkout');
watch(() => props.accessState, () => { showForm.value = false; });

const gradient = computed(() => getBrandGradient(props.group.subscriptionSlug));

const formattedPrice = computed(() =>
    formatMoney(props.group.pricePerMember, props.group.currency)
);

function onSuccess(): void {
    window.location.href = `/payment/success?group_id=${props.group.id}`;
}
</script>

<template>
    <Head :title="`Invitation — ${group.subscriptionName} — Equitab`" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-6">
        <div class="w-full max-w-md">

            <div class="text-center mb-8">
                <Link href="/" aria-label="EquitAb — accueil"><EquitabWordmark /></Link>
                <p class="mt-1 text-sm text-gray-500">Vous avez reçu une invitation privée</p>
            </div>

            <div class="rounded-2xl overflow-hidden border border-gray-100 bg-white shadow-sm mb-4">
                <div
                    class="p-6 text-white"
                    :style="{ background: `linear-gradient(135deg, ${gradient.from}, ${gradient.to})` }"
                >
                    <p class="text-xs font-medium opacity-70 uppercase tracking-wider mb-1">
                        Invitation privée
                    </p>
                    <p class="text-2xl font-semibold">{{ group.subscriptionName }}</p>
                    <p class="text-sm opacity-80 mt-1">Partagé par {{ group.ownerName }}</p>
                    <p v-if="group.description" class="mt-2 text-sm opacity-90 bg-white/10 rounded-lg px-2.5 py-1.5">
                        {{ group.description }}
                    </p>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-3xl font-bold">{{ formattedPrice }}</span>
                        <span class="text-sm opacity-80">/ mois</span>
                    </div>
                </div>

                <div class="p-5 space-y-3">
                    <div class="flex items-center gap-3 text-sm text-gray-600">
                        <Users class="h-4 w-4 text-gray-400 shrink-0" />
                        <span>{{ group.spotsAvailable }} place{{ group.spotsAvailable > 1 ? 's' : '' }} disponible{{ group.spotsAvailable > 1 ? 's' : '' }} sur {{ group.maxMembers }}</span>
                    </div>

                    <div v-if="group.ownerTrustScore !== null" class="flex items-center gap-3 text-sm text-gray-600">
                        <Shield class="h-4 w-4 text-gray-400 shrink-0" />
                        <span>Score de confiance du propriétaire :
                            <strong
                                :class="{
                                    'text-equitab-emerald': group.ownerTrustScore >= 70,
                                    'text-amber-500': group.ownerTrustScore >= 40 && group.ownerTrustScore < 70,
                                    'text-red-500': group.ownerTrustScore < 40,
                                }"
                            >
                                {{ group.ownerTrustScore }}%
                            </strong>
                        </span>
                    </div>

                    <div class="flex items-center gap-3 text-sm text-gray-600">
                        <Lock class="h-4 w-4 text-gray-400 shrink-0" />
                        <span>Identifiants chiffrés sont accessibles après paiement</span>
                    </div>
                </div>
            </div>

            <div v-if="accessState === 'guest'" class="space-y-3">
                <p class="text-sm text-gray-600">Créez votre compte ou connectez-vous pour rejoindre ce groupe. Vous reviendrez ici après la confirmation de votre courriel.</p>
                <Link :href="`${continueUrl}?auth=register`" class="block w-full rounded-xl bg-equitab-emerald px-4 py-3.5 text-center text-sm font-semibold text-white hover:bg-equitab-emerald-dark">Créer mon compte</Link>
                <Link :href="`${continueUrl}?auth=login`" class="block w-full rounded-xl border border-gray-200 bg-white px-4 py-3.5 text-center text-sm font-semibold text-equitab-navy">J’ai déjà un compte</Link>
            </div>
            <div v-else-if="accessState === 'verify_email'" class="space-y-3">
                <p class="text-sm text-gray-600">Confirmez votre adresse courriel avant de payer. Votre invitation sera conservée pendant cette étape.</p>
                <Link :href="continueUrl" class="block w-full rounded-xl bg-equitab-emerald px-4 py-3.5 text-center text-sm font-semibold text-white hover:bg-equitab-emerald-dark">Confirmer mon courriel</Link>
            </div>
            <div v-else-if="accessState === 'full'" role="status" class="rounded-xl border border-gray-200 bg-white p-4 text-center text-sm text-gray-600">
                Ce groupe est complet. Aucune nouvelle place n’est disponible pour le moment.
            </div>
            <div v-else-if="accessState === 'owner' || accessState === 'member'" class="space-y-3">
                <p class="text-sm text-gray-600">{{ accessState === 'owner' ? 'Vous êtes le propriétaire de ce groupe.' : 'Vous avez déjà accès à ce groupe.' }}</p>
                <Link :href="accessState === 'owner' ? '/dashboard/subscriptions?tab=owned' : '/dashboard/subscriptions'" class="block w-full rounded-xl bg-equitab-emerald px-4 py-3.5 text-center text-sm font-semibold text-white">Voir dans mon espace</Link>
            </div>
            <div v-else-if="accessState === 'unavailable'" role="status" class="rounded-xl border border-gray-200 bg-white p-4 text-center text-sm text-gray-600">
                Cette invitation ne vous permet pas de rejoindre ce groupe. Consultez votre espace ou contactez le soutien.
            </div>
            <div v-else-if="canCheckout && !showForm">
                <button
                    @click="showForm = true"
                    class="w-full rounded-xl bg-equitab-emerald py-3.5 text-sm font-semibold text-white hover:bg-equitab-emerald-dark"
                >
                    Continuer vers le paiement
                </button>
            </div>

            <div v-if="canCheckout && showForm" class="rounded-2xl border border-gray-100 bg-white p-5">
                <StripeCardForm
                    :group-id="group.id"
                    :price-per-member="group.pricePerMember"
                    :currency="group.currency"
                    :subscription-name="group.subscriptionName"
                    :invite-token="inviteToken"
                    @success="onSuccess"
                    @cancel="showForm = false"
                />
            </div>

            <p class="mt-6 text-center text-xs text-gray-400">
                Paiements traités par Stripe · Commission 5% · Les accès au service restent réservés aux membres dont le paiement est confirmé.
            </p>
        </div>
    </div>
</template>
