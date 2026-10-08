<script setup lang="ts">
import AdminPageHeader from "@/Components/Admin/AdminPageHeader.vue";
import AdminPagination from "@/Components/Admin/AdminPagination.vue";
import { type AdminPage } from "@/Components/Admin/adminPresentation";

import { Head } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import { formatMoney } from "@/utils/money";

interface Payment {
    id: number;
    userName: string;
    userEmail: string;
    groupName: string;
    subscriptionName: string;
    amount: number;
    equitabFee: number | null;
    currency: string;
    paidAt: string | null;
}

defineProps<{
    payments: AdminPage<Payment>;
    paymentTotalsByCurrency: { currency: string; equitabEarnings: number }[];
}>();
</script>

<template>
    <Head title="Paiements — Admin" />
    <AdminLayout>
        <AdminPageHeader
            title="Les paiements, au clair."
            description="Les transactions terminées et les commissions enregistrées, toutes périodes."
            section="LE SUIVI / PAIEMENTS"
            ><div class="eq-panel px-5 py-4">
                <p class="text-xs text-eq-muted">Commissions EquitAb · par devise</p>
                <p v-for="total in paymentTotalsByCurrency" :key="total.currency" class="mt-2 break-words text-2xl font-semibold text-eq-green">
                    {{ formatMoney(total.equitabEarnings, total.currency) }}
                </p>
                <p v-if="!paymentTotalsByCurrency.length" class="mt-2 text-sm text-eq-muted">Aucune commission</p>
            </div></AdminPageHeader
        >
        <p class="mb-5 text-xs text-eq-muted">
            {{ payments.total }} paiements terminés · montants dans leur devise d’origine
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
                            class="px-4 py-3 font-semibold text-equitab-navy max-[480px]:col-span-full"
                        >
                            {{ formatMoney(payment.amount, payment.currency) }}
                        </td>
                        <td
                            data-label="Commission Equitab"
                            class="px-4 py-3 font-semibold text-equitab-emerald max-[480px]:col-span-full"
                        >
                            {{ payment.equitabFee === null ? 'Montant indisponible' : formatMoney(payment.equitabFee, payment.currency) }}
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
