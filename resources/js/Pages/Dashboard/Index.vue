<script setup lang="ts">
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import DashboardLayout from "@/Layouts/DashboardLayout.vue";
import MetricCard from "@/Components/Dashboard/MetricCard.vue";
import TrustScoreGauge from "@/Components/Dashboard/TrustScoreGauge.vue";
import SubscriptionCard from "@/Components/Dashboard/SubscriptionCard.vue";
import BadgeChip from "@/Components/Dashboard/BadgeChip.vue";
import ServiceArtwork from "@/Components/Experience/ServiceArtwork.vue";
import {
    TrendingDown,
    Wallet,
    RefreshCcw,
    CheckCircle,
    Clock,
    AlertCircle,
    Flame,
} from "lucide-vue-next";

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
    icon: "award" | "clock" | "users";
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
    currentStreak: 0,
    subscriptions: () => [],
    badges: () => [],
});

const firstName = computed(() => props.userName.split(" ")[0]);

const formattedSavings = computed(() =>
    new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(props.totalSavings),
);

const formattedSpend = computed(() =>
    new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(props.monthlySpend),
);

function formatAmount(cents: number): string {
    return new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(cents / 100);
}

function statusIcon(status: string) {
    return status === "completed"
        ? CheckCircle
        : status === "pending"
          ? Clock
          : AlertCircle;
}

function statusClass(status: string): string {
    const classes: Record<string, string> = {
        completed: "text-equitab-emerald",
        pending: "text-amber-500",
        failed: "text-red-500",
    };
    return classes[status] ?? "text-gray-400";
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        completed: "Payé",
        pending: "En attente",
        failed: "Échoué",
    };
    return labels[status] ?? status;
}
</script>

<template>
    <Head title="Tableau de bord - Equitab" />

    <DashboardLayout>
        <div class="eq-page-heading">
            <div>
                <p class="eq-eyebrow mb-3 text-eq-muted">
                    Votre quotidien, en plus léger
                </p>
                <h1 class="eq-title text-3xl text-eq-ink sm:text-4xl">
                    Bonjour, {{ firstName }}.
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ activeSubscriptionsCount }}
                    abonnement{{
                        activeSubscriptionsCount > 1 ? "s" : ""
                    }}
                    actif{{ activeSubscriptionsCount > 1 ? "s" : "" }}
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

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                label="Économies mensuelles"
                :value="formattedSavings"
                :icon="TrendingDown"
                variant="success"
                sublabel="Voir mes abonnements"
                subhref="/dashboard/subscriptions"
            />
            <MetricCard
                label="Budget mensuel"
                :value="formattedSpend"
                :icon="Wallet"
                sublabel="Voir mes paiements"
                subhref="/dashboard/payments"
            />
            <MetricCard
                label="Échéances affichées"
                :value="`${upcomingPayments.length}`"
                :icon="RefreshCcw"
                variant="info"
                :sublabel="
                    upcomingPayments.length > 0
                        ? `Prochain : ${upcomingPayments[0]?.dueDate}`
                        : 'Aucun pour le moment'
                "
            />
            <div class="rounded-xl border border-gray-100 bg-white p-5">
                <TrustScoreGauge
                    v-if="trustScore !== undefined"
                    :score="trustScore"
                />
                <template v-else>
                    <p class="text-sm font-medium text-eq-muted">
                        Votre profil
                    </p>
                    <p class="mt-2 text-lg font-semibold text-eq-ink">
                        Identité et compte
                    </p>
                    <Link
                        href="/dashboard/profile"
                        class="eq-link mt-2 !text-xs"
                        >Voir mes informations →</Link
                    >
                </template>
            </div>
        </div>

        <div v-if="subscriptions.length > 0" class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold text-eq-ink">Vos abonnements</h2>
                <Link
                    href="/dashboard/subscriptions"
                    class="text-sm text-equitab-emerald hover:underline"
                >
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

        <div
            v-else-if="activeSubscriptionsCount === 0"
            class="eq-panel dashboard-discovery mt-8"
        >
            <div>
                <p class="eq-eyebrow text-eq-muted">Votre premier partage</p>
                <h2 class="mt-3 text-xl font-semibold text-eq-ink">
                    Faites de la place à ce que vous aimez.
                </h2>
                <p class="mt-2 text-sm leading-6 text-eq-muted">
                    Trouvez un abonnement ou proposez les places de votre propre
                    groupe.
                </p>
            </div>
            <Link href="/services" class="eq-button shrink-0"
                >Explorer les services</Link
            >
            <div class="dashboard-discovery-art" aria-hidden="true">
                <ServiceArtwork
                    scene="world"
                    category="VOTRE PROCHAIN UNIVERS"
                    tagline="De belles découvertes vous attendent."
                    :motion="false"
                />
            </div>
        </div>
        <div v-else class="eq-panel dashboard-discovery mt-8">
            <div>
                <h2 class="text-lg font-semibold text-eq-ink">
                    Vos abonnements, au même endroit.
                </h2>
                <p class="mt-2 text-sm text-eq-muted">
                    Retrouvez les détails de vos
                    {{ activeSubscriptionsCount }} abonnement(s) et de vos
                    groupes.
                </p>
            </div>
            <Link
                href="/dashboard/subscriptions"
                class="eq-button eq-button-secondary"
                >Gérer mes abonnements</Link
            >
            <div class="dashboard-discovery-art" aria-hidden="true">
                <ServiceArtwork
                    scene="music"
                    category="VOTRE COLLECTION"
                    tagline="Le plaisir de se retrouver."
                    :motion="false"
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
            <div
                class="flex items-center justify-between border-b border-gray-100 px-6 py-4"
            >
                <h2 class="font-semibold text-eq-ink">Prochaines échéances</h2>
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
                <p class="mt-3 text-sm text-gray-400">
                    Aucun paiement en attente.
                </p>
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
                        :class="
                            payment.status === 'completed'
                                ? 'bg-equitab-emerald/10'
                                : payment.status === 'pending'
                                  ? 'bg-amber-50'
                                  : 'bg-red-50'
                        "
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
                            {{
                                payment.status === "completed" && payment.paidAt
                                    ? `Payé le ${payment.paidAt}`
                                    : `Échéance : ${payment.dueDate}`
                            }}
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

        <div
            class="mt-6 rounded-xl border border-dashed border-equitab-emerald/30 bg-equitab-emerald/5 p-6"
        >
            <div class="flex flex-wrap items-center justify-between gap-5">
                <div>
                    <p class="font-semibold text-equitab-navy">
                        Partagez vos abonnements et économisez
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        Créez un groupe et invitez vos proches à partager les
                        frais.
                    </p>
                </div>
                <Link
                    href="/dashboard/groups/create"
                    class="eq-button shrink-0"
                >
                    Commencer
                </Link>
            </div>
        </div>
    </DashboardLayout>
</template>
<style scoped>
.dashboard-discovery {
    display: grid;
    grid-template-columns: 1fr 240px;
    padding: 28px;
    gap: 20px 28px;
    align-items: center;
    overflow: hidden;
}
.dashboard-discovery > div:first-child {
    grid-column: 1;
}
.dashboard-discovery > a {
    grid-column: 1;
    justify-self: start;
}
.dashboard-discovery-art {
    grid-column: 2;
    grid-row: 1 / span 2;
    transform: rotate(5deg);
}
@media (max-width: 700px) {
    .dashboard-discovery {
        grid-template-columns: 1fr;
        padding: 24px;
    }
    .dashboard-discovery-art {
        grid-column: 1;
        grid-row: 1;
        width: min(100%, 300px);
        justify-self: center;
        transform: none;
    }
}
</style>
