<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { CheckCircle, AlertCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface User {
    id: number;
    name: string;
    email: string;
    identityStatus: string;
    connectStatus: string;
    trustScore: number | null;
    groupsOwned: number;
    groupsJoined: number;
    createdAt: string;
}

const deleteModal = ref<{ show: boolean; userId: number | null; userName: string }>({
    show: false,
    userId: null,
    userName: '',
});

function openDeleteModal(id: number, name: string): void {
    deleteModal.value = { show: true, userId: id, userName: name };
}

function confirmDelete(): void {
    if (!deleteModal.value.userId) return;
    router.delete(`/admin/users/${deleteModal.value.userId}`, {
        onSuccess: () => {
            deleteModal.value = { show: false, userId: null, userName: '' };
        }
    });
}

defineProps<{ users: { data: User[]; current_page: number; last_page: number; total: number } }>();
</script>

<template>
    <Head title="Utilisateurs — Admin" />
    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-equitab-navy">Utilisateurs</h1>
            <span class="text-sm text-gray-400">{{ users.total }} au total</span>
        </div>

        <div class="rounded-xl border border-gray-100 bg-white overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Utilisateur</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Identité</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Connect</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Score</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Groupes</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Inscrit le</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-equitab-navy">{{ user.name }}</p>
                            <p class="text-xs text-gray-400">{{ user.email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="flex items-center gap-1 text-xs"
                                :class="user.identityStatus === 'verified' ? 'text-equitab-emerald' : 'text-gray-400'"
                            >
                                <CheckCircle v-if="user.identityStatus === 'verified'" class="h-3.5 w-3.5" />
                                <AlertCircle v-else class="h-3.5 w-3.5" />
                                {{ user.identityStatus }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs"
                                :class="user.connectStatus === 'active' ? 'text-equitab-emerald' : 'text-gray-400'"
                            >
                                {{ user.connectStatus }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-medium"
                                :class="(user.trustScore ?? 0) >= 70 ? 'text-equitab-emerald' : 'text-amber-500'"
                            >
                                {{ user.trustScore ?? 0 }}%
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ user.groupsOwned }} partagés · {{ user.groupsJoined }} rejoints
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ user.createdAt }}</td>

                        <td class="px-6 py-4">
                            <button
                                @click="openDeleteModal(user.id, user.name)"
                                class="rounded-lg border border-red-100 px-3 py-1.5 text-xs font-medium text-red-500 hover:bg-red-50"
                            >
                                Supprimer
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="deleteModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" @click="deleteModal.show = false" />
                <div class="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
                    <h3 class="font-semibold text-equitab-navy text-lg mb-2">Supprimer cet utilisateur ?</h3>
                    <p class="text-sm text-gray-500 mb-6">
                        <strong>{{ deleteModal.userName }}</strong> sera définitivement supprimé avec tous ses groupes et abonnements. Cette action est irréversible.
                    </p>
                    <div class="flex gap-3">
                        <button
                            @click="deleteModal.show = false"
                            class="flex-1 rounded-xl border border-gray-200 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
                        >
                            Annuler
                        </button>
                        <button
                            @click="confirmDelete"
                            class="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-medium text-white hover:bg-red-600"
                        >
                            Supprimer définitivement
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
