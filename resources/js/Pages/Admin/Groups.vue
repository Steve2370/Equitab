<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

interface Group {
    id: number;
    name: string;
    subscriptionName: string;
    ownerName: string;
    ownerEmail: string;
    status: string;
    visibility: string;
    membersCount: number;
    maxMembers: number;
    totalPrice: number;
    createdAt: string;
}

interface Props {
    groups: {
        data: Group[];
        total: number;
    };
}

defineProps<Props>();

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}

function statusClass(status: string): string {
    return status === 'open' ? 'bg-equitab-emerald/10 text-equitab-emerald' : 'bg-gray-100 text-gray-500';
}
</script>

<template>
    <Head title="Groupes — Admin Equitab" />

    <div class="min-h-screen bg-gray-50">
        <div class="bg-equitab-navy px-6 py-4">
            <div class="mx-auto max-w-7xl flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link href="/admin" class="text-white/60 hover:text-white text-sm">← Admin</Link>
                    <span class="text-white/30">/</span>
                    <h1 class="text-white font-semibold">Groupes</h1>
                </div>
                <p class="text-white/60 text-sm">{{ groups.total }} groupes</p>
            </div>
        </div>

        <div class="mx-auto max-w-7xl px-6 py-8">
            <div class="rounded-xl border border-gray-100 bg-white overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Groupe</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Propriétaire</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Statut</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Visibilité</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Membres</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Prix total</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Créé le</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="group in groups.data" :key="group.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <p class="font-medium text-equitab-navy">{{ group.name }}</p>
                                <p class="text-xs text-gray-400">{{ group.subscriptionName }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-equitab-navy">{{ group.ownerName }}</p>
                                <p class="text-xs text-gray-400">{{ group.ownerEmail }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(group.status)">
                                    {{ group.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-500 capitalize">{{ group.visibility }}</td>
                            <td class="px-6 py-4 text-gray-500">{{ group.membersCount }} / {{ group.maxMembers }}</td>
                            <td class="px-6 py-4 font-medium text-equitab-navy">{{ formatPrice(group.totalPrice) }}</td>
                            <td class="px-6 py-4 text-gray-400 text-xs">{{ group.createdAt }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
