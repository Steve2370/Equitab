<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NavbarWithSearch from '@/Components/NavbarWithSearch.vue';
import OwnerGroupCard from '@/Components/OwnerGroupCard.vue';

interface OwnerGroup {
    id: number;
    subscriptionName: string;
    ownerName: string;
    ownerIdentityStatus: string;
    ownerActiveGroupsCount: number;
    ownerTrustScore: number;
    tier: 'standard' | 'premium' | 'famille';
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
    <Head :title="`Partager ${subscription.name} — Equitab`" />

    <div class="min-h-screen bg-white">
        <NavbarWithSearch
            :can-login="canLogin"
            :can-register="canRegister"
            :is-authenticated="isAuthenticated"
        />

        <main class="mx-auto max-w-5xl px-6 py-12 lg:px-12">
            <h1 class="text-3xl font-semibold text-equitab-navy">
                Partager {{ subscription.name }}
            </h1>
            <p class="mt-2 text-gray-500">
                {{ groups.length }} propriétaire{{ groups.length > 1 ? 's' : '' }} disponible{{ groups.length > 1 ? 's' : '' }}
            </p>

            <div v-if="groups.length === 0" class="mt-12 text-center text-gray-500">
                Aucun groupe ouvert pour ce service en ce moment.
            </div>

            <div v-else class="mt-8 grid gap-4 sm:grid-cols-2">
                <OwnerGroupCard
                    v-for="group in groups"
                    :key="group.id"
                    :group-id="group.id"
                    :subscription-name="group.subscriptionName"
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
        </main>
    </div>
</template>
