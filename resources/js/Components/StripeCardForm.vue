<script setup lang="ts">
import { ref, onMounted, nextTick, onUnmounted } from 'vue';
import { CreditCard, Lock } from 'lucide-vue-next';

interface Props {
    groupId: number;
    pricePerMember: number;
    subscriptionName: string;
    amountToday?: number;
    nextBillingDate?: string;
}

interface Emits {
    (e: 'success', subscriptionId: string): void;
    (e: 'cancel'): void;
}

const props = defineProps<Props>();
const emit = defineEmits<Emits>();

const isLoading = ref(false);
const errorMessage = ref('');
let stripe: any = null;
let cardElement: any = null;

function formatCurrency(amountInCents: number): string {
    return new Intl.NumberFormat('fr-CA', {
        style: 'currency',
        currency: 'CAD',
    }).format(amountInCents / 100);
}

onMounted(async() => {
    await nextTick();
    stripe = (window as any).Stripe(import.meta.env.VITE_STRIPE_KEY);
    const elements = stripe.elements();

    cardElement = elements.create('card', {
        style: {
            base: {
                fontFamily: 'Montserrat, sans-serif',
                fontSize: '16px',
                color: '#0B1929',
                '::placeholder': { color: '#9CA3AF' },
            },
            invalid: { color: '#EF4444' },
        },
    });

    cardElement.mount('#stripe-card-element');
});

onUnmounted(() => {
    if (cardElement) cardElement.destroy();
});

async function confirmOnBackend(subscriptionId: string): Promise<void> {
    console.log('confirmOnBackend appelé avec:', subscriptionId);
    const response = await fetch(`/api/subscriptions/confirm`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify({ subscription_id: subscriptionId }),
    });
    const data = await response.json();
    console.log('confirmOnBackend réponse:', response.status, JSON.stringify(data));
}

async function handleSubmit(): Promise<void> {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const { paymentMethod, error } = await stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
        });

        if (error) {
            errorMessage.value = error.message ?? 'Erreur lors de la validation de la carte.';
            return;
        }

        const response = await fetch(`/api/groups/${props.groupId}/subscribe`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ payment_method_id: paymentMethod.id }),
        });

        const data = await response.json();
        console.log('Réponse subscribe:', JSON.stringify(data));

        if (! response.ok) {
            errorMessage.value = data.message ?? 'Une erreur est survenue.';
            return;
        }

        // Paiement direct réussi sans 3DS
        if (data.status === 'active') {
            await confirmOnBackend(data.subscription_id);
            emit('success', data.subscription_id);
            window.location.href = `/payment/success?group_id=${props.groupId}`;
            return;
        }

        // Confirmation 3DS nécessaire
        if (data.client_secret) {
            const { error: confirmError, paymentIntent } = await stripe.confirmCardPayment(
                data.client_secret,
                { payment_method: paymentMethod.id }
            );

            if (confirmError) {
                errorMessage.value = confirmError.message ?? 'Paiement refusé.';
                return;
            }

            if (paymentIntent?.status === 'succeeded') {
                await confirmOnBackend(data.subscription_id);
                emit('success', data.subscription_id);
                window.location.href = `/payment/success?group_id=${props.groupId}`;
                return;
            }
        }

        // Fallback — rediriger quand même
        emit('success', data.subscription_id);
        window.location.href = `/payment/success?group_id=${props.groupId}`;

    } finally {
        isLoading.value = false;
    }
}

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
    );
}
</script>

<template>
    <div class="rounded-xl border border-gray-100 bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <p class="font-semibold text-equitab-navy">{{ subscriptionName }}</p>
                <p class="text-sm text-gray-500">{{ formatCurrency(pricePerMember) }} / mois</p>
            </div>
            <div class="flex items-center gap-1 text-xs text-gray-400">
                <Lock class="h-3 w-3" />
                Sécurisé par Stripe
            </div>
        </div>

        <div
            id="stripe-card-element"
            class="rounded-lg border border-gray-200 px-4 py-3 focus-within:border-equitab-emerald"
        ></div>

        <p v-if="errorMessage" class="mt-2 text-sm text-red-500">
            {{ errorMessage }}
        </p>

        <div class="mt-4 flex gap-3">
            <button
                type="button"
                @click="handleSubmit"
                :disabled="isLoading"
                class="flex flex-1 items-center justify-center gap-2 rounded-md bg-equitab-emerald px-4 py-3 text-sm font-medium text-white hover:bg-equitab-emerald-dark disabled:opacity-60"
            >
                <CreditCard class="h-4 w-4" />
                {{ isLoading ? 'Traitement...' : 'Confirmer l\'abonnement' }}
            </button>
            <button
                type="button"
                @click="$emit('cancel')"
                class="rounded-md border border-gray-200 px-4 py-3 text-sm font-medium text-gray-600 hover:bg-gray-50"
            >
                Annuler
            </button>
        </div>

        <p class="mt-3 text-center text-xs text-gray-400">
            Votre carte sera débitée automatiquement chaque mois. Annulable à tout moment.
        </p>
    </div>

    <div class="mb-4 rounded-lg bg-gray-50 p-4 text-sm">
        <div class="flex justify-between">
            <span class="text-gray-600">Aujourd'hui (pro-rata)</span>
            <span class="font-semibold text-equitab-navy">
                {{ formatCurrency(amountToday ?? pricePerMember) }}
            </span>
        </div>
        <div class="mt-1 flex justify-between text-gray-500">
            <span>Dès le 1er du mois prochain</span>
            <span>{{ formatCurrency(pricePerMember) }} / mois</span>
        </div>
        <div class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-400">
            Renouvellement automatique le 1er de chaque mois · Annulable à tout moment
        </div>
    </div>
</template>
