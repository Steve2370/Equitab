<script setup lang="ts">
import AdminPageHeader from "@/Components/Admin/AdminPageHeader.vue";
import AdminPagination from "@/Components/Admin/AdminPagination.vue";
import {
    adminLabel,
    type AdminPage,
} from "@/Components/Admin/adminPresentation";
import ExperienceDialog from "@/Components/Experience/ExperienceDialog.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import { formatMoney } from "@/utils/money";

import { Head, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { Flag, CheckCircle, XCircle } from "lucide-vue-next";

interface Dispute {
    id: number;
    userName: string;
    userEmail: string;
    groupName: string;
    subscriptionName: string;
    reason: string;
    description: string;
    status: string;
    amount: number | null;
    currency: string | null;
    adminNotes: string | null;
    createdAt: string;
}

interface Props {
    disputes: AdminPage<Dispute>;
}

defineProps<Props>();

const selectedDispute = ref<Dispute | null>(null);
const resolution = ref<"resolved_refund" | "resolved_rejected">(
    "resolved_refund",
);
const adminNotes = ref("");
const isResolving = ref(false);

const reasonLabels: Record<string, string> = {
    no_access: "Identifiants non reçus",
    invalid_credentials: "Identifiants invalides",
    service_down: "Service suspendu",
    other: "Autre raison",
};

function openResolve(dispute: Dispute): void {
    selectedDispute.value = dispute;
    adminNotes.value = dispute.adminNotes ?? "";
    resolution.value = "resolved_refund";
}

function resolve(): void {
    if (!selectedDispute.value) return;
    isResolving.value = true;
    router.patch(
        `/admin/disputes/${selectedDispute.value.id}/resolve`,
        {
            status: resolution.value,
            admin_notes: adminNotes.value,
        },
        {
            onSuccess: () => {
                selectedDispute.value = null;
            },
            onFinish: () => {
                isResolving.value = false;
            },
        },
    );
}

function statusClass(status: string): string {
    const classes: Record<string, string> = {
        open: "bg-amber-50 text-amber-700",
        under_review: "bg-blue-50 text-blue-700",
        resolved_refund: "bg-equitab-emerald/10 text-equitab-emerald",
        resolved_rejected: "bg-gray-100 text-gray-500",
    };
    return classes[status] ?? "bg-gray-100 text-gray-500";
}
</script>

<template>
    <Head title="Disputes — Admin Equitab" />

    <AdminLayout>
        <AdminPageHeader
            title="Chaque litige mérite une réponse."
            description="Consultez le signalement et le montant avant de confirmer une décision."
            section="LA CONFIANCE / LITIGES"
            ><span class="admin-count"
                >{{ disputes.total }} litiges</span
            ></AdminPageHeader
        >
        <div>
            <div
                class="admin-table-region"
                role="region"
                aria-label="Liste des litiges"
                tabindex="0"
            >
                <table class="admin-table">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Membre
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Groupe
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Raison
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Montant
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Statut
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Date
                            </th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="dispute in disputes.data"
                            :key="dispute.id"
                            class="hover:bg-gray-50"
                        >
                            <td data-label="Membre" class="px-6 py-4">
                                <p class="font-medium text-equitab-navy">
                                    {{ dispute.userName }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ dispute.userEmail }}
                                </p>
                            </td>
                            <td data-label="Groupe" class="px-6 py-4">
                                <p class="text-equitab-navy">
                                    {{ dispute.groupName }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    {{ dispute.subscriptionName }}
                                </p>
                            </td>
                            <td data-label="Raison" class="px-6 py-4">
                                <p class="text-gray-700">
                                    {{
                                        reasonLabels[dispute.reason] ??
                                        dispute.reason
                                    }}
                                </p>
                                <p
                                    class="text-xs text-gray-400 mt-0.5 max-w-xs truncate"
                                >
                                    {{ dispute.description }}
                                </p>
                            </td>
                            <td
                                data-label="Montant"
                                class="px-6 py-4 font-semibold text-equitab-navy max-[480px]:col-span-full"
                            >
                                {{ dispute.amount === null || dispute.currency === null ? 'Montant indisponible' : formatMoney(dispute.amount, dispute.currency) }}
                            </td>
                            <td data-label="Statut" class="px-6 py-4">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="statusClass(dispute.status)"
                                >
                                    {{ adminLabel(dispute.status) }}
                                </span>
                            </td>
                            <td
                                data-label="Date"
                                class="px-6 py-4 text-gray-400 text-xs"
                            >
                                {{ dispute.createdAt }}
                            </td>
                            <td data-label="Actions" class="px-6 py-4">
                                <button
                                    v-if="dispute.status === 'open'"
                                    @click="openResolve(dispute)"
                                    class="flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:border-equitab-navy hover:text-equitab-navy"
                                >
                                    <Flag class="h-3.5 w-3.5" />
                                    Résoudre
                                </button>
                            </td>
                        </tr>
                        <tr v-if="disputes.data.length === 0">
                            <td
                                colspan="7"
                                class="admin-full-cell px-6 py-8 text-center text-gray-400 text-sm"
                            >
                                Aucun litige pour l’instant.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ExperienceDialog
            :open="!!selectedDispute"
            title="Examiner le litige"
            @close="!isResolving && (selectedDispute = null)"
        >
            <template v-if="selectedDispute">
                <p class="text-sm text-gray-500 mb-4">
                    {{ selectedDispute.userName }} —
                    {{ selectedDispute.groupName }} —
                    {{ selectedDispute.amount === null || selectedDispute.currency === null ? 'Montant indisponible' : formatMoney(selectedDispute.amount, selectedDispute.currency) }}
                </p>

                <div
                    class="mb-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600"
                >
                    <p class="font-medium text-gray-700 mb-1">
                        Description du membre :
                    </p>
                    <p class="italic">{{ selectedDispute.description }}</p>
                </div>

                <div class="space-y-3 mb-4">
                    <label class="text-sm font-medium text-gray-700"
                        >Décision</label
                    >
                    <div class="grid grid-cols-2 gap-3">
                        <button
                            @click="resolution = 'resolved_refund'"
                            :aria-pressed="resolution === 'resolved_refund'"
                            class="flex items-center gap-2 rounded-lg border p-3 text-sm transition-colors"
                            :class="
                                resolution === 'resolved_refund'
                                    ? 'border-equitab-emerald bg-equitab-emerald/5 text-equitab-emerald'
                                    : 'border-gray-200 text-gray-500'
                            "
                        >
                            <CheckCircle class="h-4 w-4" />
                            Rembourser
                        </button>
                        <button
                            @click="resolution = 'resolved_rejected'"
                            :aria-pressed="resolution === 'resolved_rejected'"
                            class="flex items-center gap-2 rounded-lg border p-3 text-sm transition-colors"
                            :class="
                                resolution === 'resolved_rejected'
                                    ? 'border-red-400 bg-red-50 text-red-600'
                                    : 'border-gray-200 text-gray-500'
                            "
                        >
                            <XCircle class="h-4 w-4" />
                            Rejeter
                        </button>
                    </div>
                </div>

                <div class="mb-5">
                    <label
                        for="admin-notes"
                        class="text-sm font-medium text-gray-700"
                        >Notes admin (optionnel)</label
                    >
                    <textarea
                        id="admin-notes"
                        v-model="adminNotes"
                        rows="3"
                        placeholder="Raison de la décision..."
                        class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                    />
                </div>

                <div class="flex gap-3">
                    <button
                        @click="selectedDispute = null"
                        :disabled="isResolving"
                        class="flex-1 rounded-lg border border-gray-200 py-2.5 text-sm text-gray-600 hover:bg-gray-50"
                    >
                        Annuler
                    </button>
                    <button
                        @click="resolve"
                        :disabled="isResolving"
                        class="flex-1 rounded-lg py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        :class="
                            resolution === 'resolved_refund'
                                ? 'bg-equitab-emerald'
                                : 'bg-red-500'
                        "
                    >
                        {{
                            isResolving
                                ? "En cours..."
                                : resolution === "resolved_refund"
                                  ? "Confirmer le remboursement"
                                  : "Rejeter le litige"
                        }}
                    </button>
                </div>
            </template>
        </ExperienceDialog>
        <AdminPagination :page="disputes" />
    </AdminLayout>
</template>
