<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle, Clock, Lock, MessageSquare, LayoutDashboard } from 'lucide-vue-next';
import ServiceAccessPanel from '@/Components/ServiceAccessPanel.vue';
import { useServiceAccess } from '@/composables/useServiceAccess';
import type { ServiceAccessSnapshot, ServiceCredentials } from '@/types/service-access';
import { getBrandGradient } from '@/config/brandGradients';
import { formatMoney } from '@/utils/money';

interface Group {
    id: number;
    name: string;
    subscriptionName: string;
    subscriptionSlug: string;
    ownerName: string;
    pricePerMember: number;
    currency: string;
    renewalDate: string;
    memberStatus?: string;
}

const props = defineProps<{
    group: Group;
    // Compatibility only: secrets come from the authorized no-store endpoint.
    credentials: ServiceCredentials | null;
    serviceAccess?: ServiceAccessSnapshot | null;
}>();
const { access, status, loading, error, timedOut, retry } = useServiceAccess(() => props.group.id, () => props.serviceAccess);
const gradient = computed(() => getBrandGradient(props.group.subscriptionSlug));
const formattedPrice = computed(() => formatMoney(props.group.pricePerMember, props.group.currency));
const confirmed = computed(() => status.value === 'ready' || status.value === 'awaiting_owner');
const title = computed(() => confirmed.value ? 'Paiement confirmé'
    : status.value === 'payment_pending' ? 'Confirmation du paiement en cours'
        : status.value === 'unavailable' ? 'Accès indisponible' : 'Vérification de votre abonnement');
</script>

<template>
    <Head :title="title + ' — Equitab'" />
    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-6">
        <div class="w-full max-w-lg min-w-0">
            <div class="mb-6 flex flex-col items-center text-center" aria-live="polite">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-equitab-emerald/10">
                    <CheckCircle v-if="confirmed" class="h-8 w-8 text-equitab-emerald" aria-hidden="true" />
                    <Lock v-else-if="status === 'unavailable'" class="h-8 w-8 text-equitab-navy" aria-hidden="true" />
                    <Clock v-else class="h-8 w-8 text-equitab-navy" aria-hidden="true" />
                </div>
                <h1 class="mt-4 text-2xl font-semibold text-equitab-navy">{{ title }}</h1>
                <p class="mt-2 text-sm text-gray-500">{{ confirmed ? 'Votre abonnement est actif. Retrouvez les informations d’accès ci-dessous.' : 'Nous vérifions votre abonnement auprès du serveur EquitAb.' }}</p>
            </div>
            <div class="rounded-xl border border-gray-100 bg-white overflow-hidden mb-4">
                <div class="p-5 text-white" :style="{ background: `linear-gradient(135deg, ${gradient.from}, ${gradient.to})` }">
                    <p class="break-words text-lg font-semibold">{{ group.subscriptionName }}</p>
                    <p class="break-words text-sm opacity-80">Partagé par {{ group.ownerName }}</p>
                    <div class="mt-3 flex flex-wrap items-baseline gap-1"><span class="text-2xl font-bold">{{ formattedPrice }}</span><span class="text-sm opacity-80">/ mois</span></div>
                    <p v-if="confirmed && group.renewalDate" class="mt-1 text-xs opacity-70">Renouvellement automatique le {{ group.renewalDate }}</p>
                </div>
                <div class="p-5">
                    <ServiceAccessPanel :access="access" :status="status" :loading="loading" :error="error" :timed-out="timedOut" @retry="retry" />
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link href="/dashboard/chat" class="flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-equitab-navy hover:bg-gray-50">
                    <MessageSquare class="h-4 w-4 shrink-0" aria-hidden="true" />Contacter le propriétaire
                </Link>
                <Link href="/dashboard/subscriptions" class="flex items-center justify-center gap-2 rounded-xl bg-equitab-emerald px-4 py-3 text-sm font-medium text-white hover:bg-equitab-emerald-dark">
                    <LayoutDashboard class="h-4 w-4 shrink-0" aria-hidden="true" />Accéder à mon espace
                </Link>
            </div>
        </div>
    </div>
</template>
