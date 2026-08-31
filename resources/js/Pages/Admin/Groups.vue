<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';

interface Member {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    role: string;
    status: string;
    joinedAt: string | null;
}

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
    members: Member[];
}

interface Props {
    groups: {
        data: Group[];
        total: number;
    };
}

defineProps<Props>();

const expandedGroupId = ref<number | null>(null);

function toggleExpand(groupId: number): void {
    expandedGroupId.value = expandedGroupId.value === groupId ? null : groupId;
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}

function statusClass(status: string): string {
    return status === 'open' ? 'bg-equitab-emerald/10 text-equitab-emerald' : 'bg-gray-100 text-gray-500';
}

function memberStatusClass(status: string): string {
    const map: Record<string, string> = {
        active: 'bg-equitab-emerald/10 text-equitab-emerald',
        pending_payment: 'bg-amber-50 text-amber-600',
        suspended: 'bg-red-50 text-red-500',
        left: 'bg-gray-100 text-gray-400',
    };
    return map[status] ?? 'bg-gray-100 text-gray-500';
}

function initials(name: string): string {
    return name.charAt(0).toUpperCase();
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
                            <th class="w-8"></th>
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
                        <template v-for="group in groups.data" :key="group.id">
                            <tr
                                @click="toggleExpand(group.id)"
                                class="cursor-pointer hover:bg-gray-50"
                            >
                                <td class="pl-4 text-gray-300">
                                    <ChevronDown v-if="expandedGroupId === group.id" class="h-4 w-4" />
                                    <ChevronRight v-else class="h-4 w-4" />
                                </td>
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

                            <tr v-if="expandedGroupId === group.id">
                                <td colspan="8" class="bg-gray-50/60 px-6 py-4">
                                    <p class="mb-3 text-xs font-medium uppercase text-gray-400">
                                        Composition du groupe ({{ group.members.length }})
                                    </p>
                                    <div class="space-y-2">
                                        <div
                                            v-for="member in group.members"
                                            :key="member.id"
                                            class="flex items-center gap-3 rounded-lg bg-white px-4 py-2.5"
                                        >
                                            <img
                                                v-if="member.avatar"
                                                :src="member.avatar"
                                                :alt="member.name"
                                                class="h-8 w-8 shrink-0 rounded-full object-cover"
                                            />
                                            <div
                                                v-else
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-equitab-navy/10 text-xs font-semibold text-equitab-navy"
                                            >
                                                {{ initials(member.name) }}
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-equitab-navy">{{ member.name }}</p>
                                                <p class="truncate text-xs text-gray-400">{{ member.email }}</p>
                                            </div>

                                            <span
                                                v-if="member.role === 'owner'"
                                                class="shrink-0 rounded-full bg-equitab-navy/10 px-2 py-0.5 text-xs font-medium text-equitab-navy"
                                            >
                                                Propriétaire
                                            </span>

                                            <span
                                                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="memberStatusClass(member.status)"
                                            >
                                                {{ member.status }}
                                            </span>

                                            <span class="shrink-0 text-xs text-gray-400">
                                                {{ member.joinedAt }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
