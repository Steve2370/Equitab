<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Shield, Users, Lock } from 'lucide-vue-next';
import { getBrandGradient } from '@/config/brandGradients';
import StripeCardForm from '@/Components/StripeCardForm.vue';

interface Group {
    id: number;
    name: string;
    subscriptionName: string;
    subscriptionSlug: string;
    ownerName: string;
    ownerTrustScore: number;
    pricePerMember: number;
    spotsAvailable: number;
    maxMembers: number;
}

interface Props {
    group: Group;
}

const props = defineProps<Props>();
const showForm = ref(false);

const gradient = computed(() => getBrandGradient(props.group.subscriptionSlug));

const formattedPrice = computed(() =>
    new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(
        props.group.pricePerMember / 100
    )
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
                <a href="/" class="text-2xl font-semibold text-equitab-navy">Equitab</a>
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

                    <div class="flex items-center gap-3 text-sm text-gray-600">
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

            <div v-if="!showForm">
                <button
                    v-if="group.spotsAvailable > 0"
                    @click="showForm = true"
                    class="w-full rounded-xl bg-equitab-emerald py-3.5 text-sm font-semibold text-white hover:bg-equitab-emerald-dark"
                >
                    Rejoindre ce groupe
                </button>
                <div v-else class="rounded-xl bg-red-50 border border-red-100 p-4 text-center text-sm text-red-600">
                    Ce groupe est complet; aucune place disponible.
                </div>
            </div>

            <div v-if="showForm" class="rounded-2xl border border-gray-100 bg-white p-5">
                <StripeCardForm
                    :group-id="group.id"
                    :price-per-member="group.pricePerMember"
                    :subscription-name="group.subscriptionName"
                    @success="onSuccess"
                    @cancel="showForm = false"
                />
            </div>

            <p class="mt-6 text-center text-xs text-gray-400">
                Paiements sécurisés par Stripe · Commission 5% · Remboursement garanti sous 48h si accès non fourni
            </p>
        </div>
    </div>
</template>
