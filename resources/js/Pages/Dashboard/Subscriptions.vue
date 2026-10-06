<script setup lang="ts">
import { ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import DashboardLayout from "@/Layouts/DashboardLayout.vue";
import CredentialsModal from "@/Components/CredentialsModal.vue";
import { useToast } from "@/composables/useToast";
import { Plus, ArrowUpRight, Key, Link2 } from "lucide-vue-next";

import CollectionCard from "@/Components/Experience/CollectionCard.vue";
import ExperienceDialog from "@/Components/Experience/ExperienceDialog.vue";
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
    initialTab?: "joined" | "owned";
    joinedSubscriptions: JoinedSubscription[];
    ownedSubscriptions: OwnedSubscription[];
    drafts?: { id: string; name: string; status: string; updatedAt: string; url: string }[];
}

const props = defineProps<Props>();

const activeTab = ref<"joined" | "owned">(props.initialTab ?? "joined");
const showCredentials = ref(false);
const selectedGroup = ref<{ id: number; name: string } | null>(null);

function openCredentials(groupId: number, subscriptionName: string): void {
    selectedGroup.value = { id: groupId, name: subscriptionName };
    showCredentials.value = true;
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        active: "Actif",
        pending_payment: "En attente",
        suspended: "Suspendu",
        open: "Ouvert",
        full: "Complet",
        closed: "Fermé",
    };
    return labels[status] ?? status;
}

const copiedLink = ref<number | null>(null);

async function copyInviteLink(groupId: number, link: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(link);
        copiedLink.value = groupId;
        setTimeout(() => (copiedLink.value = null), 2000);
    } catch {
        toast.error("Le lien n’a pas pu être copié. Réessayez.");
    }
}

const closeModal = ref<{ show: boolean; groupId: number | null }>({
    show: false,
    groupId: null,
});

function confirmCloseGroup(): void {
    if (!closeModal.value.groupId) return;
    router.patch(
        `/groups/${closeModal.value.groupId}/close`,
        {},
        {
            onSuccess: () => {
                toast.success("Groupe fermé avec succès.");
                closeModal.value = { show: false, groupId: null };
            },
            onError: () => {
                toast.error("Une erreur est survenue.");
            },
        },
    );
}
</script>

<template>
    <Head title="Ma collection — Equitab" />
    <DashboardLayout>
        <div class="eq-page-heading">
            <div>
                <p class="eq-eyebrow">LE PLAISIR AU QUOTIDIEN</p>
                <h1>Votre collection.</h1>
                <p>
                    Vos abonnements, vos accès et les personnes avec qui les
                    partager.
                </p>
            </div>
            <Link
                :href="
                    activeTab === 'joined'
                        ? '/services'
                        : '/dashboard/groups/create'
                "
                class="eq-button"
                ><Plus :size="16" />{{
                    activeTab === "joined"
                        ? "Rejoindre un groupe"
                        : "Partager un abonnement"
                }}</Link
            >
        </div>
        <section v-if="drafts?.length" class="eq-panel mb-8 p-5" aria-labelledby="owner-drafts-title">
            <h2 id="owner-drafts-title" class="text-lg font-semibold">Vos partages en préparation</h2>
            <p class="mt-1 text-sm text-gray-600">Privés et sans paiement. Reprenez quand vous le souhaitez.</p>
            <ul class="mt-4 divide-y divide-gray-100">
                <li v-for="draft in drafts" :key="draft.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="font-medium break-words">{{ draft.name || 'Mon prochain partage' }}</p>
                        <p class="text-sm text-gray-500">{{ draft.status === 'publishing' ? 'Publication à reprendre' : 'Brouillon enregistré' }} · {{ draft.updatedAt }}</p>
                    </div>
                    <Link :href="draft.url" class="eq-button" :aria-label="`Reprendre ${draft.name || 'mon partage'}`">Reprendre <ArrowUpRight :size="16" /></Link>
                </li>
            </ul>
        </section>
        <div
            class="eq-pills mb-8"
            role="group"
            aria-label="Afficher mes abonnements"
        >
            <button
                :aria-pressed="activeTab === 'joined'"
                @click="activeTab = 'joined'"
            >
                J’ai rejoint · {{ joinedSubscriptions.length }}</button
            ><button
                :aria-pressed="activeTab === 'owned'"
                @click="activeTab = 'owned'"
            >
                Je partage · {{ ownedSubscriptions.length }}
            </button>
        </div>
        <div v-if="activeTab === 'joined'">
            <div
                v-if="joinedSubscriptions.length === 0"
                class="eq-panel collection-empty"
            >
                <h2>Votre première découverte vous attend.</h2>
                <p>Retrouvez ici les abonnements que vous rejoindrez.</p>
                <Link href="/services" class="eq-button"
                    >Explorer les services <ArrowUpRight :size="16"
                /></Link>
            </div>
            <div v-else class="member-collection-grid">
                <CollectionCard
                    v-for="sub in joinedSubscriptions"
                    presentation="workspace"
                    :key="sub.id"
                    :name="sub.subscriptionName"
                    :slug="sub.subscriptionSlug"
                    category="DANS VOTRE QUOTIDIEN"
                    eyebrow="ABONNEMENT REJOINT"
                    :status="statusLabel(sub.status)"
                    :price="sub.pricePerMember"
                    price-label="Votre part"
                    :owner="sub.ownerName"
                >
                    <template #meta
                        ><p class="subscription-date">
                            {{
                                sub.joinedAt
                                    ? "Rejoint le " + sub.joinedAt
                                    : "Date d’arrivée à confirmer"
                            }}
                        </p></template
                    >
                    <template #action
                        ><button
                            @click="
                                openCredentials(sub.id, sub.subscriptionName)
                            "
                            :aria-label="
                                'Identifiants de ' + sub.subscriptionName
                            "
                        >
                            <Key :size="15" /> Mes accès
                        </button></template
                    >
                    <Link
                        :href="
                            '/groups/service/' +
                            encodeURIComponent(sub.subscriptionSlug)
                        "
                        class="subscription-secondary"
                        >Voir les groupes <ArrowUpRight :size="14"
                    /></Link>
                </CollectionCard>
            </div>
        </div>
        <div v-else>
            <div
                v-if="ownedSubscriptions.length === 0"
                class="eq-panel collection-empty"
            >
                <h2>Faites de la place à votre groupe.</h2>
                <p>
                    Proposez votre abonnement et invitez des membres à partager
                    les frais.
                </p>
                <Link href="/dashboard/groups/create" class="eq-button"
                    >Créer un groupe <Plus :size="16"
                /></Link>
            </div>
            <div v-else class="member-collection-grid">
                <CollectionCard
                    v-for="sub in ownedSubscriptions"
                    presentation="workspace"
                    :key="sub.id"
                    :name="sub.subscriptionName"
                    :slug="
                        sub.subscriptionName
                            .toLowerCase()
                            .replace(/\+/g, '-plus')
                            .replace(/\s+/g, '-')
                    "
                    category="VOTRE GROUPE"
                    eyebrow="ABONNEMENT PARTAGÉ"
                    :status="statusLabel(sub.status)"
                    :price="sub.pricePerMember"
                    price-label="Part actuelle par membre"
                    :members="sub.membersCount"
                    :capacity="sub.maxMembers"
                    owner="vous"
                >
                    <template #meta
                        ><p class="subscription-date">
                            {{
                                sub.renewalDate
                                    ? "Renouvellement le " + sub.renewalDate
                                    : "Échéance à confirmer"
                            }}
                        </p></template
                    >
                    <template #action
                        ><button
                            @click="
                                openCredentials(sub.id, sub.subscriptionName)
                            "
                            :aria-label="
                                'Gérer les identifiants de ' +
                                sub.subscriptionName
                            "
                        >
                            <Key :size="15" /> Gérer les accès
                        </button></template
                    >
                    <div class="subscription-actions">
                        <button
                            v-if="sub.inviteLink"
                            @click="copyInviteLink(sub.id, sub.inviteLink)"
                            class="subscription-secondary"
                        >
                            <Link2 :size="14" />{{
                                copiedLink === sub.id
                                    ? "Lien copié !"
                                    : "Copier l’invitation"
                            }}</button
                        ><button
                            v-if="sub.status === 'open'"
                            @click="
                                closeModal = { show: true, groupId: sub.id }
                            "
                            class="subscription-close"
                        >
                            Fermer le groupe
                        </button>
                    </div>
                </CollectionCard>
            </div>
        </div>
        <ExperienceDialog
            :open="closeModal.show"
            title="Fermer ce groupe ?"
            @close="closeModal.show = false"
            ><p class="mb-6 text-sm leading-7 text-eq-muted">
                Tous les membres actifs seront désabonnés immédiatement. Cette
                action est irréversible.
            </p>
            <div class="flex flex-wrap gap-3">
                <button
                    @click="closeModal.show = false"
                    class="eq-button eq-button-secondary"
                >
                    Annuler</button
                ><button
                    @click="confirmCloseGroup"
                    class="eq-button !bg-red-700"
                >
                    Fermer le groupe
                </button>
            </div></ExperienceDialog
        >
        <CredentialsModal
            v-if="showCredentials && selectedGroup"
            :group-id="selectedGroup.id"
            :subscription-name="selectedGroup.name"
            @close="
                showCredentials = false;
                selectedGroup = null;
            "
        />
    </DashboardLayout>
</template>
<style scoped>
.member-collection-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
}
.subscription-date {
    font-size: 10px;
    color: #686b70;
    line-height: 1.7;
    margin-bottom: 18px;
}
.subscription-secondary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    color: #686b70;
    min-height: 44px;
}
.subscription-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 5px;
}
.subscription-close {
    color: #a33e37;
    font-size: 10px;
    min-height: 44px;
}
.collection-empty {
    padding: 60px 30px;
    text-align: center;
}
.collection-empty h2 {
    font-size: 25px;
    letter-spacing: -0.04em;
}
.collection-empty p {
    color: #686b70;
    font-size: 13px;
    line-height: 1.8;
    margin: 14px 0 24px;
}
@media (min-width: 1500px) {
    .member-collection-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 650px) {
    .member-collection-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
