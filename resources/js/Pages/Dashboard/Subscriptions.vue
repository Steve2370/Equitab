<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import CredentialsModal from '@/Components/CredentialsModal.vue';
import { useToast } from '@/composables/useToast';
import { Users, Calendar, Plus, ChevronRight, Clock, Key, Link2 } from 'lucide-vue-next';

const toast = useToast();

interface JoinedSubscription {
    id: number;
    subscriptionName: string;
    subscriptionSlug: string;
    ownerName: string;
    pricePerMember: number;
    joinedAt: string;
    status: string;
    spotsLeft: number;
}

interface OwnedSubscription {
    id: number;
    subscriptionName: string;
    membersCount: number;
    maxMembers: number;
    pricePerMember: number;
    status: string;
    renewalDate: string;
    inviteLink: string | null;
}

interface Props {
    joinedSubscriptions: JoinedSubscription[];
    ownedSubscriptions: OwnedSubscription[];
}

defineProps<Props>();

const activeTab = ref<'joined' | 'owned'>('joined');
const showCredentials = ref(false);
const selectedGroup = ref<{ id: number; name: string } | null>(null);

function openCredentials(groupId: number, subscriptionName: string): void {
    selectedGroup.value = { id: groupId, name: subscriptionName };
    showCredentials.value = true;
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('fr-CA', {
        style: 'currency',
        currency: 'CAD',
    }).format(cents / 100);
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        active: 'Actif',
        pending_payment: 'En attente',
        suspended: 'Suspendu',
        open: 'Ouvert',
        full: 'Complet',
        closed: 'Fermé',
    };
    return labels[status] ?? status;
}

function statusClass(status: string): string {
    const classes: Record<string, string> = {
        active: 'bg-equitab-emerald/10 text-equitab-emerald',
        pending_payment: 'bg-amber-50 text-amber-700',
        suspended: 'bg-red-50 text-red-600',
        open: 'bg-blue-50 text-blue-600',
        full: 'bg-gray-100 text-gray-600',
        closed: 'bg-gray-100 text-gray-400',
    };
    return classes[status] ?? 'bg-gray-100 text-gray-500';
}

const copiedLink = ref<number | null>(null);

async function copyInviteLink(groupId: number, link: string): Promise<void> {
    await navigator.clipboard.writeText(link);
    copiedLink.value = groupId;
    setTimeout(() => copiedLink.value = null, 2000);
}

const closeModal = ref<{ show: boolean; groupId: number | null }>({
    show: false,
    groupId: null,
});

function confirmCloseGroup(): void {
    if (!closeModal.value.groupId) return;
    router.patch(`/groups/${closeModal.value.groupId}/close`, {}, {
        onSuccess: () => {
            toast.success('Groupe fermé avec succès.');
            closeModal.value = { show: false, groupId: null };
        },
        onError: () => {
            toast.error('Une erreur est survenue.');
        }
    });
}
</script>

<template>
    <Head title="Abonnements - Equitab" />

    <DashboardLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-equitab-navy">Mes abonnements</h1>
            <Link
                :href="activeTab === 'joined' ? '/' : '/dashboard/groups/create'"
                class="flex items-center gap-2 rounded-md bg-equitab-emerald px-4 py-2 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
            >
                <Plus class="h-4 w-4" />
                {{ activeTab === 'joined' ? 'Rejoindre un abonnement' : 'Partager un abonnement' }}
            </Link>
        </div>

        <div class="mb-6 flex gap-1 rounded-xl border border-gray-100 bg-white p-1">
            <button
                @click="activeTab = 'joined'"
                class="flex-1 rounded-lg py-2.5 text-sm font-medium transition-colors"
                :class="activeTab === 'joined' ? 'bg-equitab-navy text-white' : 'text-gray-500 hover:text-gray-900'"
            >
                Abonnements rejoints
                <span class="ml-1.5 rounded-full bg-equitab-emerald/20 px-2 py-0.5 text-xs text-equitab-emerald">
                    {{ joinedSubscriptions.length }}
                </span>
            </button>
            <button
                @click="activeTab = 'owned'"
                class="flex-1 rounded-lg py-2.5 text-sm font-medium transition-colors"
                :class="activeTab === 'owned' ? 'bg-equitab-navy text-white' : 'text-gray-500 hover:text-gray-900'"
            >
                Abonnements partagés
                <span class="ml-1.5 rounded-full bg-equitab-emerald/20 px-2 py-0.5 text-xs text-equitab-emerald">
                    {{ ownedSubscriptions.length }}
                </span>
            </button>
        </div>

        <div v-if="activeTab === 'joined'">
            <div
                v-if="joinedSubscriptions.length === 0"
                class="rounded-xl border border-dashed border-gray-200 py-16 text-center"
            >
                <p class="text-gray-400">Vous n'avez pas encore rejoint d'abonnement.</p>
                <Link
                    href="/"
                    class="mt-4 inline-flex items-center gap-2 text-sm font-medium text-equitab-emerald hover:underline"
                >
                    Parcourir les abonnements disponibles →
                </Link>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="sub in joinedSubscriptions"
                    :key="sub.id"
                    class="flex items-center gap-4 rounded-xl border border-gray-100 bg-white p-5 transition-shadow hover:shadow-sm"
                >
                    <button
                        @click="openCredentials(sub.id, sub.subscriptionName)"
                        class="shrink-0 flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-600 hover:border-equitab-emerald hover:text-equitab-emerald"
                    >
                        <Key class="h-3.5 w-3.5" />
                        Identifiants
                    </button>

                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg font-bold text-white"
                        :style="{ background: 'linear-gradient(135deg, #0B1929, #10B981)' }"
                    >
                        {{ sub.subscriptionName.charAt(0) }}
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold text-equitab-navy">{{ sub.subscriptionName }}</p>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(sub.status)">
                                {{ statusLabel(sub.status) }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center gap-3 text-xs text-gray-400">
                            <span class="flex items-center gap-1">
                                <Users class="h-3.5 w-3.5" />
                                Partagé par {{ sub.ownerName }}
                            </span>
                            <span class="flex items-center gap-1">
                                <Calendar class="h-3.5 w-3.5" />
                                Rejoint le {{ sub.joinedAt }}
                            </span>
                        </div>
                    </div>

                    <div class="text-right shrink-0">
                        <p class="text-lg font-semibold text-equitab-navy">
                            {{ formatPrice(sub.pricePerMember) }}
                            <span class="text-xs font-normal text-gray-400">/ mois</span>
                        </p>
                    </div>

                    <Link
                        :href="`/groups/service/${sub.subscriptionSlug}`"
                        class="shrink-0 rounded-lg border border-gray-200 p-2 text-gray-400 hover:border-equitab-emerald hover:text-equitab-emerald"
                    >
                        <ChevronRight class="h-4 w-4" />
                    </Link>
                </div>
            </div>
        </div>

        <div v-if="activeTab === 'owned'">
            <div
                v-if="ownedSubscriptions.length === 0"
                class="rounded-xl border border-dashed border-gray-200 py-16 text-center"
            >
                <p class="text-gray-400">Vous ne partagez aucun abonnement pour le moment.</p>
                <p class="mt-2 text-sm text-gray-400">Créez un groupe pour partager vos frais d'abonnement.</p>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="sub in ownedSubscriptions"
                    :key="sub.id"
                    class="flex items-center gap-4 rounded-xl border border-gray-100 bg-white p-5 transition-shadow hover:shadow-sm"
                >
                    <button
                        @click="openCredentials(sub.id, sub.subscriptionName)"
                        class="shrink-0 flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-600 hover:border-equitab-emerald hover:text-equitab-emerald"
                    >
                        <Key class="h-3.5 w-3.5" />
                        Identifiants
                    </button>

                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg font-bold text-white"
                        :style="{ background: 'linear-gradient(135deg, #10B981, #0B1929)' }"
                    >
                        {{ sub.subscriptionName.charAt(0) }}
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold text-equitab-navy">{{ sub.subscriptionName }}</p>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(sub.status)">
                                {{ statusLabel(sub.status) }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center gap-3 text-xs text-gray-400">
                            <span class="flex items-center gap-1">
                                <Users class="h-3.5 w-3.5" />
                                {{ sub.membersCount }} / {{ sub.maxMembers }} membres
                            </span>
                            <span class="flex items-center gap-1">
                                <Clock class="h-3.5 w-3.5" />
                                Renouvellement le {{ sub.renewalDate }}
                            </span>
                        </div>
                    </div>

                    <div class="text-right shrink-0">
                        <p class="text-xs text-gray-400">par membre</p>
                        <p class="text-lg font-semibold text-equitab-navy">
                            {{ formatPrice(sub.pricePerMember) }}
                            <span class="text-xs font-normal text-gray-400">/ mois</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <div class="flex gap-1">
                            <div
                                v-for="i in sub.maxMembers"
                                :key="i"
                                class="h-2 w-2 rounded-full"
                                :class="i <= sub.membersCount ? 'bg-equitab-emerald' : 'bg-gray-200'"
                            />
                        </div>
                        <p class="text-xs text-gray-400">
                            {{ sub.maxMembers - sub.membersCount }} place{{ sub.maxMembers - sub.membersCount > 1 ? 's' : '' }} libre{{ sub.maxMembers - sub.membersCount > 1 ? 's' : '' }}
                        </p>
                    </div>

                    <button
                        v-if="sub.inviteLink"
                        @click="copyInviteLink(sub.id, sub.inviteLink)"
                        class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:border-equitab-emerald hover:text-equitab-emerald"
                    >
                        <Link2 class="h-3.5 w-3.5" />
                        {{ copiedLink === sub.id ? 'Copié !' : 'Copier le lien' }}
                    </button>

                    <button
                        v-if="sub.status === 'open'"
                        @click="closeModal = { show: true, groupId: sub.id }"
                        class="flex items-center gap-1.5 rounded-lg border border-red-100 px-3 py-1.5 text-xs font-medium text-red-500 hover:bg-red-50"
                    >
                        Fermer le groupe
                    </button>
                </div>
            </div>
        </div>

        <div v-if="closeModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="closeModal.show = false" />
            <div class="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="font-semibold text-equitab-navy text-lg mb-2">Fermer ce groupe ?</h3>
                <p class="text-sm text-gray-500 mb-6">
                    Tous les membres actifs seront désabonnés immédiatement. Cette action est irréversible.
                </p>
                <div class="flex gap-3">
                    <button
                        @click="closeModal.show = false"
                        class="flex-1 rounded-xl border border-gray-200 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    >
                        Annuler
                    </button>
                    <button
                        @click="confirmCloseGroup"
                        class="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-medium text-white hover:bg-red-600"
                    >
                        Fermer le groupe
                    </button>
                </div>
            </div>
        </div>

        <CredentialsModal
            v-if="showCredentials && selectedGroup"
            :group-id="selectedGroup.id"
            :subscription-name="selectedGroup.name"
            @close="showCredentials = false; selectedGroup = null"
        />
    </DashboardLayout>
</template>
