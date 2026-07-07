<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Users, FolderOpen, CreditCard, AlertTriangle, TrendingUp, ShieldCheck } from 'lucide-vue-next';

interface Props {
    stats: {
        totalUsers: number;
        totalGroups: number;
        activeGroups: number;
        totalPayments: number;
        totalRevenue: number;
        equitabEarnings: number;
        openDisputes: number;
        verifiedUsers: number;
    };
}

defineProps<Props>();

function formatAmount(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}
</script>

<template>
    <Head title="Admin - Equitab" />
    <AdminLayout>
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-equitab-navy">Vue d'ensemble</h1>
            <p class="mt-1 text-sm text-gray-500">Tableau de bord administrateur Equitab</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-100 bg-white p-5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-gray-500">Utilisateurs</p>
                    <Users class="h-5 w-5 text-equitab-navy/30" />
                </div>
                <p class="text-3xl font-bold text-equitab-navy">{{ stats.totalUsers }}</p>
                <p class="text-xs text-equitab-emerald mt-1">{{ stats.verifiedUsers }} vérifiés</p>
            </div>

            <div class="rounded-xl border border-gray-100 bg-white p-5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-gray-500">Groupes actifs</p>
                    <FolderOpen class="h-5 w-5 text-equitab-navy/30" />
                </div>
                <p class="text-3xl font-bold text-equitab-navy">{{ stats.activeGroups }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ stats.totalGroups }} au total</p>
            </div>

            <div class="rounded-xl border border-gray-100 bg-white p-5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-gray-500">Gains Equitab (5%)</p>
                    <TrendingUp class="h-5 w-5 text-equitab-emerald/50" />
                </div>
                <p class="text-3xl font-bold text-equitab-emerald">{{ formatAmount(stats.equitabEarnings) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ stats.totalPayments }} paiements</p>
            </div>

            <div class="rounded-xl border border-gray-100 bg-white p-5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-gray-500">Disputes ouvertes</p>
                    <AlertTriangle class="h-5 w-5 text-amber-400/50" />
                </div>
                <p class="text-3xl font-bold text-equitab-navy">{{ stats.openDisputes }}</p>
                <p class="text-xs text-gray-400 mt-1">À traiter</p>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-gray-100 bg-white p-5">
            <p class="text-sm font-medium text-gray-500 mb-1">Volume total des transactions</p>
            <p class="text-4xl font-bold text-equitab-navy">{{ formatAmount(stats.totalRevenue) }}</p>
            <p class="text-xs text-gray-400 mt-1">Equitab a perçu {{ formatAmount(stats.equitabEarnings) }} de commission</p>
        </div>
    </AdminLayout>
</template>
