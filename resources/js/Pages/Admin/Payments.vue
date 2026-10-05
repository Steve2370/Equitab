<script setup lang="ts">
import AdminPageHeader from "@/Components/Admin/AdminPageHeader.vue";
import AdminPagination from "@/Components/Admin/AdminPagination.vue";
import { type AdminPage } from "@/Components/Admin/adminPresentation";

import { Head } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";

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
    payments: AdminPage<Payment>;
    totalEarnings: number;
}>();

function formatAmount(cents: number): string {
    return new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(cents / 100);
}
</script>

<template>
    <Head title="Paiements — Admin" />
    <AdminLayout>
        <AdminPageHeader
            title="Les paiements, au clair."
            description="Les transactions terminées et les commissions enregistrées, toutes périodes."
            section="LE SUIVI / PAIEMENTS"
            ><div class="eq-panel px-5 py-4">
                <p class="text-xs text-eq-muted">Commissions EquitAb · CAD</p>
                <p class="mt-2 text-2xl font-semibold text-eq-green">
                    {{ formatAmount(totalEarnings) }}
                </p>
            </div></AdminPageHeader
        >
        <p class="mb-5 text-xs text-eq-muted">
            {{ payments.total }} paiements terminés · montants en dollars
            canadiens
        </p>
        <div
            class="admin-table-region"
            role="region"
            aria-label="Liste des paiements"
            tabindex="0"
        >
            <table class="admin-table">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th
                            class="text-left px-4 py-3 font-medium text-gray-500"
                        >
                            Membre
                        </th>
                        <th
                            class="text-left px-4 py-3 font-medium text-gray-500"
                        >
                            Groupe
                        </th>
                        <th
                            class="text-left px-4 py-3 font-medium text-gray-500"
                        >
                            Montant
                        </th>
                        <th
                            class="text-left px-4 py-3 font-medium text-gray-500"
                        >
                            Commission Equitab
                        </th>
                        <th
                            class="text-left px-4 py-3 font-medium text-gray-500"
                        >
                            Date
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr
                        v-for="payment in payments.data"
                        :key="payment.id"
                        class="hover:bg-gray-50"
                    >
                        <td data-label="Membre" class="px-4 py-3">
                            <p class="font-medium text-equitab-navy">
                                {{ payment.userName }}
                            </p>
                            <p class="text-xs text-gray-400">
                                {{ payment.userEmail }}
                            </p>
                        </td>
                        <td data-label="Groupe" class="px-4 py-3">
                            <p class="text-equitab-navy">
                                {{ payment.groupName }}
                            </p>
                            <p class="text-xs text-gray-400">
                                {{ payment.subscriptionName }}
                            </p>
                        </td>
                        <td
                            data-label="Montant"
                            class="px-4 py-3 font-semibold text-equitab-navy"
                        >
                            {{ formatAmount(payment.amount) }}
                        </td>
                        <td
                            data-label="Commission Equitab"
                            class="px-4 py-3 font-semibold text-equitab-emerald"
                        >
                            {{ formatAmount(payment.equitabFee) }}
                        </td>
                        <td
                            data-label="Date"
                            class="px-4 py-3 text-xs text-gray-400"
                        >
                            {{ payment.paidAt }}
                        </td>
                    </tr>
                    <tr v-if="!payments.data.length">
                        <td colspan="5" class="admin-full-cell">
                            <p class="admin-empty">
                                Aucun paiement terminé pour l’instant.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <AdminPagination :page="payments" />
    </AdminLayout>
</template>
