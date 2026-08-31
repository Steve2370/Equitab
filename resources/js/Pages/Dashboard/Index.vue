<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import MetricCard from '@/Components/Dashboard/MetricCard.vue';
import TrustScoreGauge from '@/Components/Dashboard/TrustScoreGauge.vue';
import SubscriptionCard from '@/Components/Dashboard/SubscriptionCard.vue';
import BadgeChip from '@/Components/Dashboard/BadgeChip.vue';
import {
    TrendingDown,
    Wallet,
    RefreshCcw,
    CheckCircle,
    Clock,
    AlertCircle,
    Flame,
} from 'lucide-vue-next';

interface Payment {
    id: number;
    groupName: string;
    amount: number;
    status: string;
    paidAt: string | null;
    dueDate: string;
}

interface Subscription {
    id: number;
    serviceName: string;
    category: string;
    brandColor: string;
    daysUntilNextPayment: number;
}

interface Badge {
    id: number;
    label: string;
    icon: 'award' | 'clock' | 'users';
}

interface Props {
    userName: string;
    totalSavings: number;
    monthlySpend: number;
    upcomingPayments: Payment[];
    activeSubscriptionsCount: number;
    trustScore?: number;
    currentStreak?: number;
    subscriptions?: Subscription[];
    badges?: Badge[];
}

const props = withDefaults(defineProps<Props>(), {
    trustScore: 0,
    currentStreak: 0,
    subscriptions: () => [],
    badges: () => [],
});

const firstName = computed(() => props.userName.split(' ')[0]);

const formattedSavings = computed(() =>
    new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(props.totalSavings)
);

const formattedSpend = computed(() =>
    new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(props.monthlySpend)
);

function formatAmount(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}

function statusIcon(status: string) {
    return status === 'completed' ? CheckCircle : status === 'pending' ? Clock : AlertCircle;
}

function statusClass(status: string): string {
    const classes: Record<string, string> = {
        completed: 'text-equitab-emerald',
        pending: 'text-amber-500',
        failed: 'text-red-500',
    };
    return classes[status] ?? 'text-gray-400';
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        completed: 'Payé',
        pending: 'En attente',
        failed: 'Échoué',
    };
    return labels[status] ?? status;
}
</script>

<template>
    <Head title="Tableau de bord - Equitab" />

    <DashboardLayout>
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-equitab-navy">
                    Bonjour, <span class="text-equitab-emerald">{{ firstName }}</span>
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ activeSubscriptionsCount }}
                    abonnement{{ activeSubscriptionsCount > 1 ? 's' : '' }}
                    actif{{ activeSubscriptionsCount > 1 ? 's' : '' }}
                </p>
            </div>

            <span
                v-if="currentStreak > 0"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-600"
            >
                <Flame class="h-4 w-4" />
                {{ currentStreak }} mois d'affilée
            </span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <MetricCard
                label="Économies réalisées"
                :value="formattedSavings"
                :icon="TrendingDown"
                variant="success"
                sublabel="Voir mes abonnements"
                subhref="/dashboard/subscriptions"
            />
            <MetricCard
                label="Dépenses ce mois-ci"
                :value="formattedSpend"
                :icon="Wallet"
                sublabel="Voir mes paiements"
                subhref="/dashboard/payments"
            />
            <MetricCard
                label="Renouvellements à venir"
                :value="`${upcomingPayments.length}`"
                :icon="RefreshCcw"
                variant="info"
                :sublabel="upcomingPayments.length > 0 ? `Prochain : ${upcomingPayments[0]?.dueDate}` : 'Aucun pour le moment'"
            />
            <div class="rounded-xl border border-gray-100 bg-white p-5">
                <TrustScoreGauge :score="trustScore" />
            </div>
        </div>

        <div v-if="subscriptions.length > 0" class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold text-equitab-navy">Tes abonnements</h2>
                <Link href="/dashboard/subscriptions" class="text-sm text-equitab-emerald hover:underline">
                    Voir tout →
                </Link>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <SubscriptionCard
                    v-for="subscription in subscriptions"
                    :key="subscription.id"
                    :service-name="subscription.serviceName"
                    :category="subscription.category"
                    :brand-color="subscription.brandColor"
                    :days-until-next-payment="subscription.daysUntilNextPayment"
                />
            </div>
        </div>

        <div v-if="badges.length > 0" class="mt-6 flex flex-wrap gap-2">
            <BadgeChip
                v-for="badge in badges"
                :key="badge.id"
                :label="badge.label"
                :icon="badge.icon"
            />
        </div>

        <div class="mt-8 rounded-xl border border-gray-100 bg-white">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <h2 class="font-semibold text-equitab-navy">Paiements récents</h2>
                <Link
                    href="/dashboard/payments"
                    class="text-sm text-equitab-emerald hover:underline"
                >
                    Voir tout →
                </Link>
            </div>

            <div
                v-if="upcomingPayments.length === 0"
                class="flex flex-col items-center justify-center py-16 text-center"
            >
                <div class="rounded-full bg-gray-50 p-4">
                    <CheckCircle class="h-8 w-8 text-gray-300" />
                </div>
                <p class="mt-3 text-sm text-gray-400">Aucun paiement en attente.</p>
                <Link
                    href="/"
                    class="mt-2 text-sm font-medium text-equitab-emerald hover:underline"
                >
                    Parcourir les abonnements →
                </Link>
            </div>

            <div v-else class="divide-y divide-gray-50">
                <div
                    v-for="payment in upcomingPayments"
                    :key="payment.id"
                    class="flex items-center gap-4 px-6 py-4"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                        :class="payment.status === 'completed'
                            ? 'bg-equitab-emerald/10'
                            : payment.status === 'pending'
                            ? 'bg-amber-50'
                            : 'bg-red-50'"
                    >
                        <component
                            :is="statusIcon(payment.status)"
                            class="h-4 w-4"
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
                        <p
                            class="text-xs font-medium"
                            :class="statusClass(payment.status)"
                        >
                            {{ statusLabel(payment.status) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-dashed border-equitab-emerald/30 bg-equitab-emerald/5 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-semibold text-equitab-navy">
                        Partagez vos abonnements et économisez
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        Créez un groupe et invitez vos proches à partager les frais.
                    </p>
                </div>
                <Link
                    href="/dashboard/subscriptions"
                    class="shrink-0 rounded-md bg-equitab-emerald px-4 py-2 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
                >
                    Commencer
                </Link>
            </div>
        </div>
    </DashboardLayout>
</template>
