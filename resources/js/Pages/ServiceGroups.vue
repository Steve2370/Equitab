<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ArrowLeft, Users, Info } from "lucide-vue-next";
import Footer from "@/Components/Footer.vue";
import NavbarWithSearch from "@/Components/NavbarWithSearch.vue";
import OwnerGroupCard from "@/Components/OwnerGroupCard.vue";
import { useExperienceMotion } from "@/composables/useExperienceMotion";
const { motion } = useExperienceMotion();

interface OwnerGroup {
    id: number;
    subscriptionName: string;
    description?: string | null;
    ownerName: string;
    ownerIdentityStatus: string;
    ownerActiveGroupsCount: number;
    ownerTrustScore: number | null;
    tier: "standard" | "premium" | "famille";
    pricePerMember: number;
    spotsAvailable: number;
    maxMembers: number;
    createdAt: string;
}

interface Props {
    subscription: { name: string; slug: string };
    groups: OwnerGroup[];
    canLogin: boolean;
    canRegister: boolean;
    isAuthenticated: boolean;
}

defineProps<Props>();
</script>

<template>
    <Head :title="`Partager ${subscription.name} - Equitab`" />

    <div class="eq-experience min-h-screen">
        <NavbarWithSearch
            :can-login="canLogin"
            :can-register="canRegister"
            :is-authenticated="isAuthenticated"
        />

        <main class="eq-container py-12 md:py-16">
            <Link href="/services" class="eq-link mb-8"
                ><ArrowLeft :size="16" aria-hidden="true" /> Tous les
                services</Link
            >
            <p class="eq-eyebrow text-eq-muted">Trouvez votre groupe</p>
            <h1 class="eq-title mt-4 text-4xl sm:text-5xl">
                {{ subscription.name }}, ensemble.
            </h1>
            <p class="mt-5 text-sm leading-6 text-eq-muted">
                {{ groups.length }} groupe{{
                    groups.length > 1 ? "s" : ""
                }}
                proposé{{ groups.length > 1 ? "s" : "" }} au partage. Comparez
                les places et le profil des propriétaires.
            </p>

            <div
                v-if="groups.length === 0"
                class="eq-panel mt-10 flex flex-col items-center p-10 text-center"
            >
                <Users :size="36" class="text-eq-muted" aria-hidden="true" />
                <h2 class="mt-5 text-xl font-semibold">
                    Le prochain groupe pourrait être le vôtre.
                </h2>
                <p class="mt-3 max-w-md text-sm leading-6 text-eq-muted">
                    Aucun groupe ouvert pour ce service en ce moment. Vous avez
                    déjà cet abonnement ? Proposez vos places.
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <Link href="/dashboard/groups/create" class="eq-button"
                        >Créer un groupe</Link
                    ><Link
                        href="/services"
                        class="eq-button eq-button-secondary"
                        >Explorer d’autres services</Link
                    >
                </div>
            </div>

            <div v-else class="eq-collection-grid mt-10 items-start">
                <OwnerGroupCard
                    v-for="(group, index) in groups"
                    :key="group.id"
                    :group-id="group.id"
                    :subscription-name="group.subscriptionName"
                    :subscription-slug="subscription.slug"
                    :motion="motion"
                    :index="index + 1"
                    :description="group.description"
                    :owner-name="group.ownerName"
                    :owner-identity-status="group.ownerIdentityStatus"
                    :owner-active-groups-count="group.ownerActiveGroupsCount"
                    :owner-trust-score="group.ownerTrustScore"
                    :tier="group.tier"
                    :price-per-member="group.pricePerMember"
                    :spots-available="group.spotsAvailable"
                    :max-members="group.maxMembers"
                    :created-at="group.createdAt"
                />
            </div>
            <div
                class="mt-8 flex items-start gap-3 rounded-xl bg-[#edf1e7] p-5 text-xs leading-6 text-eq-muted"
            >
                <Info :size="18" class="mt-1 shrink-0" aria-hidden="true" />
                <p>
                    Vérifiez les conditions de partage du service et les
                    montants du récapitulatif avant de payer. Votre part peut
                    évoluer selon le nombre de membres du groupe.
                </p>
            </div>
        </main>
        <Footer />
    </div>
</template>
