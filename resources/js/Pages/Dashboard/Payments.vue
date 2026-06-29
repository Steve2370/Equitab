<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import { CheckCircle, Clock, AlertCircle, Download, Flag } from 'lucide-vue-next';

interface Payment {
    id: number;
    groupName: string;
    amount: number;
    status: string;
    paidAt: string | null;
    dueDate: string;
}

interface PaginatedPayments {
    data: Payment[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    payments: PaginatedPayments;
}

const props = defineProps<Props>();

const activeFilter = ref<'all' | 'completed' | 'pending' | 'failed'>('all');
const showDisputeModal = ref(false);
const isSubmittingDispute = ref(false);
const selectedPaymentId = ref<number | null>(null);

const disputeForm = ref({
    reason: 'no_access',
    description: '',
});

const filteredPayments = computed(() => {
    if (activeFilter.value === 'all') return props.payments.data;
    return props.payments.data.filter(p => p.status === activeFilter.value);
});

const totalPaid = computed(() =>
    props.payments.data
        .filter(p => p.status === 'completed')
        .reduce((sum, p) => sum + p.amount, 0)
);

function formatAmount(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}

function statusIcon(status: string) {
    if (status === 'completed') return CheckCircle;
    if (status === 'pending') return Clock;
    return AlertCircle;
}

function statusClass(status: string): string {
    const classes: Record<string, string> = {
        completed: 'text-equitab-emerald',
        pending: 'text-amber-500',
        failed: 'text-red-500',
    };
    return classes[status] ?? 'text-gray-400';
}

function statusBgClass(status: string): string {
    const classes: Record<string, string> = {
        completed: 'bg-equitab-emerald/10',
        pending: 'bg-amber-50',
        failed: 'bg-red-50',
    };
    return classes[status] ?? 'bg-gray-50';
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        completed: 'Payé',
        pending: 'En attente',
        failed: 'Échoué',
        refunded: 'Remboursé',
    };
    return labels[status] ?? status;
}

function openDispute(paymentId: number): void {
    selectedPaymentId.value = paymentId;
    disputeForm.value = { reason: 'no_access', description: '' };
    showDisputeModal.value = true;
}

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.split('; ')
            .find(r => r.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? ''
    );
}

async function submitDispute(): Promise<void> {
    if (!selectedPaymentId.value) return;
    isSubmittingDispute.value = true;
    try {
        const response = await fetch(`/api/payments/${selectedPaymentId.value}/dispute`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(disputeForm.value),
        });
        const data = await response.json();
        if (response.ok) {
            showDisputeModal.value = false;
        }
        alert(data.message);
    } finally {
        isSubmittingDispute.value = false;
    }
}

const filters: { key: 'all' | 'completed' | 'pending' | 'failed'; label: string }[] = [
    { key: 'all', label: 'Tous' },
    { key: 'completed', label: 'Payés' },
    { key: 'pending', label: 'En attente' },
    { key: 'failed', label: 'Échoués' },
];
</script>

<template>
    <Head title="Paiements — Equitab" />

    <DashboardLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-equitab-navy">Mes paiements</h1>
            <div class="text-right">
                <p class="text-xs text-gray-400">Total payé ce mois</p>
                <p class="text-lg font-semibold text-equitab-navy">{{ formatAmount(totalPaid) }}</p>
            </div>
        </div>

        <div class="mb-4 flex gap-2">
            <button
                v-for="filter in filters"
                :key="filter.key"
                @click="activeFilter = filter.key"
                class="rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
                :class="activeFilter === filter.key
                    ? 'bg-equitab-navy text-white'
                    : 'bg-white border border-gray-200 text-gray-500 hover:border-equitab-navy hover:text-equitab-navy'"
            >
                {{ filter.label }}
            </button>
        </div>

        <div class="rounded-xl border border-gray-100 bg-white">
            <div
                v-if="filteredPayments.length === 0"
                class="flex flex-col items-center justify-center py-16 text-center"
            >
                <div class="rounded-full bg-gray-50 p-4">
                    <CheckCircle class="h-8 w-8 text-gray-300" />
                </div>
                <p class="mt-3 text-sm text-gray-400">Aucun paiement trouvé.</p>
            </div>

            <div v-else class="divide-y divide-gray-50">
                <div
                    v-for="payment in filteredPayments"
                    :key="payment.id"
                    class="flex items-center gap-4 px-6 py-4"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                        :class="statusBgClass(payment.status)"
                    >
                        <component
                            :is="statusIcon(payment.status)"
                            class="h-5 w-5"
                            :class="statusClass(payment.status)"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <p class="truncate font-medium text-equitab-navy">
                            {{ payment.groupName }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ payment.status === 'completed' && payment.paidAt
                                ? `Payé le ${payment.paidAt}`
                                : `Échéance : ${payment.dueDate}` }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="font-semibold text-equitab-navy">
                            {{ formatAmount(payment.amount) }}
                        </p>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-equitab-emerald/10 text-equitab-emerald': payment.status === 'completed',
                                'bg-amber-50 text-amber-700': payment.status === 'pending',
                                'bg-red-50 text-red-600': payment.status === 'failed',
                                'bg-gray-100 text-gray-500': payment.status === 'refunded',
                            }"
                        >
                            {{ statusLabel(payment.status) }}
                        </span>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="payment.status === 'completed'"
                            class="rounded-lg border border-gray-200 p-2 text-gray-400 hover:border-equitab-emerald hover:text-equitab-emerald"
                            title="Télécharger le reçu"
                        >
                            <Download class="h-4 w-4" />
                        </button>

                        <button
                            v-if="payment.status === 'completed'"
                            @click="openDispute(payment.id)"
                            class="rounded-lg border border-red-100 p-2 text-red-400 hover:border-red-400 hover:text-red-500"
                            title="Signaler un problème"
                        >
                            <Flag class="h-4 w-4" />
                        </button>

                        <div v-if="payment.status !== 'completed'" class="w-9" />
                    </div>
                </div>
            </div>

            <div
                v-if="payments.last_page > 1"
                class="flex items-center justify-between border-t border-gray-100 px-6 py-4"
            >
                <p class="text-sm text-gray-400">
                    {{ payments.total }} paiement{{ payments.total > 1 ? 's' : '' }} au total
                </p>
                <span class="text-sm text-gray-400">
                    Page {{ payments.current_page }} / {{ payments.last_page }}
                </span>
            </div>
        </div>

        <div v-if="showDisputeModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="showDisputeModal = false" />
            <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="font-semibold text-equitab-navy mb-1">Signaler un problème</h3>
                <p class="text-sm text-gray-500 mb-4">Décrivez le problème rencontré — nous répondrons sous 48h.</p>

                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Raison</label>
                        <select
                            v-model="disputeForm.reason"
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                        >
                            <option value="no_access">Je n'ai pas reçu les identifiants d'accès</option>
                            <option value="invalid_credentials">Les identifiants fournis sont invalides</option>
                            <option value="service_down">Le service a été suspendu ou annulé</option>
                            <option value="other">Autre raison</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700">Description</label>
                        <textarea
                            v-model="disputeForm.description"
                            rows="3"
                            placeholder="Décrivez votre problème en détail..."
                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                        />
                    </div>

                    <div class="rounded-lg bg-amber-50 p-3 text-xs text-amber-700">
                        Si votre problème est confirmé, vous serez remboursé intégralement sous 48h.
                    </div>
                </div>

                <div class="mt-5 flex gap-3">
                    <button
                        @click="showDisputeModal = false"
                        class="flex-1 rounded-lg border border-gray-200 py-2.5 text-sm text-gray-600 hover:bg-gray-50"
                    >
                        Annuler
                    </button>
                    <button
                        @click="submitDispute"
                        :disabled="!disputeForm.reason || !disputeForm.description || isSubmittingDispute"
                        class="flex-1 rounded-lg bg-red-500 py-2.5 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50"
                    >
                        {{ isSubmittingDispute ? 'Envoi...' : 'Soumettre' }}
                    </button>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>
