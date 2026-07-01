<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

interface Payment {
    id: number;
    userName: string;
    userEmail: string;
    groupName: string;
    subscriptionName: string;
    amount: number;
    equitabFee: number;
    currency: string;
    paidAt: string;
}

const props = defineProps<{
    payments: { data: Payment[]; total: number };
    totalEarnings: number;
}>();

function formatAmount(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}
</script>

<template>
    <Head title="Paiements — Admin" />
    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-equitab-navy">Paiements</h1>
            <div class="text-right">
                <p class="text-xs text-gray-400">Gains Equitab (5%)</p>
                <p class="text-xl font-bold text-equitab-emerald">{{ formatAmount(totalEarnings) }}</p>
            </div>
        </div>

        <div class="rounded-xl border border-gray-100 bg-white overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Membre</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Groupe</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Montant</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Commission Equitab</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-500">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr v-for="payment in payments.data" :key="payment.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-equitab-navy">{{ payment.userName }}</p>
                            <p class="text-xs text-gray-400">{{ payment.userEmail }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-equitab-navy">{{ payment.groupName }}</p>
                            <p class="text-xs text-gray-400">{{ payment.subscriptionName }}</p>
                        </td>
                        <td class="px-4 py-3 font-semibold text-equitab-navy">
                            {{ formatAmount(payment.amount) }}
                        </td>
                        <td class="px-4 py-3 font-semibold text-equitab-emerald">
                            {{ formatAmount(payment.equitabFee) }}
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ payment.paidAt }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
