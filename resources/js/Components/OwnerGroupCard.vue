<script setup lang="ts">
import { ref, computed } from 'vue';
import { Users, Calendar } from 'lucide-vue-next';
import VerifiedBadge from '@/Components/VerifiedBadge.vue';
import TierBadge from '@/Components/TierBadge.vue';
import StripeCardForm from '@/Components/StripeCardForm.vue';

interface Props {
    groupId: number;
    ownerName: string;
    ownerIdentityStatus: string;
    ownerActiveGroupsCount: number;
    ownerTrustScore: number;
    tier: 'standard' | 'premium' | 'famille';
    pricePerMember: number;
    spotsAvailable: number;
    maxMembers: number;
    createdAt: string;
    subscriptionName: string;
}

const props = defineProps<Props>();

const showForm = ref(false);
const subscribed = ref(false);

const isVerified = computed(() => props.ownerIdentityStatus === 'verified');

const formattedPrice = computed(() =>
    new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(
        props.pricePerMember / 100
    ),
);

const prorationData = ref<{
    amount_today: number;
    amount_recurring: number;
    next_billing_date: string;
} | null>(null);

async function openSubscribeForm(): Promise<void> {
    console.log('openSubscribeForm appelé');
    const response = await fetch(`/api/groups/${props.groupId}/proration`, {
        headers: { 'Accept': 'application/json' },
    });
    prorationData.value = await response.json();
    console.log('showForm avant:', showForm.value);
    showForm.value = true;
    console.log('showForm après:', showForm.value);
}

function onSuccess(_subscriptionId: string): void {
    showForm.value = false;
    subscribed.value = true;
}
</script>

<template>
    <div class="flex flex-col rounded-xl border border-gray-100 p-5">
        <div class="flex items-start justify-between">
            <div>
                <p class="font-semibold text-equitab-navy">{{ ownerName }}</p>
                <div class="mt-1 flex items-center gap-2">
                    <VerifiedBadge :is-verified="isVerified" />
                    <TierBadge :tier="tier" />
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="{
                                'bg-equitab-emerald': ownerTrustScore >= 70,
                                'bg-amber-400': ownerTrustScore >= 40 && ownerTrustScore < 70,
                                'bg-red-400': ownerTrustScore < 40,
                            }"
                            :style="{ width: ownerTrustScore + '%' }"
                        />
                    </div>
                    <span class="text-xs font-medium shrink-0"
                        :class="{
                            'text-equitab-emerald': ownerTrustScore >= 70,
                            'text-amber-500': ownerTrustScore >= 40 && ownerTrustScore < 70,
                            'text-red-500': ownerTrustScore < 40,
                        }"
                    >
                        {{ ownerTrustScore }}% confiance
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-4 text-sm text-gray-500">
            <span class="flex items-center gap-1">
                <Users class="h-4 w-4" />
                {{ ownerActiveGroupsCount }} partage{{ ownerActiveGroupsCount > 1 ? 's' : '' }} actif{{ ownerActiveGroupsCount > 1 ? 's' : '' }}
            </span>
            <span class="flex items-center gap-1">
                <Calendar class="h-4 w-4" />
                Depuis le {{ createdAt }}
            </span>
        </div>

        <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-4">
            <div>
                <p class="text-2xl font-semibold text-equitab-navy">
                    {{ formattedPrice }}<span class="text-sm font-normal text-gray-500"> / mois</span>
                </p>
                <p class="text-xs text-gray-500">
                    {{ spotsAvailable }} place{{ spotsAvailable > 1 ? 's' : '' }} sur {{ maxMembers }}
                </p>
            </div>

            <div v-if="subscribed" class="text-sm font-medium text-equitab-emerald">
                Abonnement actif
            </div>
            <button
                v-else-if="!showForm"
                @click="openSubscribeForm"
                class="rounded-md bg-equitab-emerald px-5 py-2.5 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
            >
                S'abonner
            </button>
        </div>

        <div v-show="showForm" class="mt-4">
            <StripeCardForm
                :group-id="groupId"
                :price-per-member="pricePerMember"
                :subscription-name="subscriptionName"
                @success="onSuccess"
                @cancel="showForm = false"
            />
        </div>
    </div>
</template>
