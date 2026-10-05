<script setup lang="ts">
import AdminPageHeader from "@/Components/Admin/AdminPageHeader.vue";
import AdminPagination from "@/Components/Admin/AdminPagination.vue";
import {
    adminLabel,
    type AdminPage,
} from "@/Components/Admin/adminPresentation";
import AdminLayout from "@/Layouts/AdminLayout.vue";

import { ref } from "vue";
import { Head } from "@inertiajs/vue3";
import { ChevronDown, ChevronRight } from "lucide-vue-next";

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
    groups: AdminPage<Group>;
}

defineProps<Props>();

const expandedGroupId = ref<number | null>(null);

function toggleExpand(groupId: number): void {
    expandedGroupId.value = expandedGroupId.value === groupId ? null : groupId;
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(cents / 100);
}

function statusClass(status: string): string {
    return status === "open"
        ? "bg-equitab-emerald/10 text-equitab-emerald"
        : "bg-gray-100 text-gray-500";
}

function memberStatusClass(status: string): string {
    const map: Record<string, string> = {
        active: "bg-equitab-emerald/10 text-equitab-emerald",
        pending_payment: "bg-amber-50 text-amber-600",
        suspended: "bg-red-50 text-red-500",
        left: "bg-gray-100 text-gray-400",
    };
    return map[status] ?? "bg-gray-100 text-gray-500";
}

function initials(name: string): string {
    return name.charAt(0).toUpperCase();
}
</script>

<template>
    <Head title="Groupes — Admin Equitab" />

    <AdminLayout>
        <AdminPageHeader
            title="Les groupes, en détail."
            description="Propriétaires, membres et accès : une vue claire de chaque abonnement partagé."
            section="LE PARTAGE / GROUPES"
            ><span class="admin-count"
                >{{ groups.total }} groupes</span
            ></AdminPageHeader
        >
        <div>
            <div
                class="admin-table-region"
                role="region"
                aria-label="Liste des groupes"
                tabindex="0"
            >
                <table class="admin-table">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="w-8"></th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Groupe
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Propriétaire
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Statut
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Visibilité
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Membres
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Prix total
                            </th>
                            <th
                                class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase"
                            >
                                Créé le
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <template v-for="group in groups.data" :key="group.id">
                            <tr
                                @click="toggleExpand(group.id)"
                                class="cursor-pointer hover:bg-gray-50"
                            >
                                <td
                                    data-label="Membres"
                                    class="pl-4 text-gray-300"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center min-w-11"
                                        :aria-label="
                                            'Membres du groupe ' + group.name
                                        "
                                        :aria-expanded="
                                            expandedGroupId === group.id
                                        "
                                        :aria-controls="
                                            'group-members-' + group.id
                                        "
                                        @click.stop="toggleExpand(group.id)"
                                    >
                                        <ChevronDown
                                            v-if="expandedGroupId === group.id"
                                            class="h-4 w-4"
                                        /><ChevronRight
                                            v-else
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </td>
                                <td data-label="Groupe" class="px-6 py-4">
                                    <p class="font-medium text-equitab-navy">
                                        {{ group.name }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ group.subscriptionName }}
                                    </p>
                                </td>
                                <td data-label="Propriétaire" class="px-6 py-4">
                                    <p class="text-equitab-navy">
                                        {{ group.ownerName }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        {{ group.ownerEmail }}
                                    </p>
                                </td>
                                <td data-label="Statut" class="px-6 py-4">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statusClass(group.status)"
                                    >
                                        {{ adminLabel(group.status) }}
                                    </span>
                                </td>
                                <td
                                    data-label="Visibilité"
                                    class="px-6 py-4 text-gray-500 capitalize"
                                >
                                    {{ adminLabel(group.visibility) }}
                                </td>
                                <td
                                    data-label="Membres"
                                    class="px-6 py-4 text-gray-500"
                                >
                                    {{ group.membersCount }} /
                                    {{ group.maxMembers }}
                                </td>
                                <td
                                    data-label="Prix total"
                                    class="px-6 py-4 font-medium text-equitab-navy"
                                >
                                    {{ formatPrice(group.totalPrice) }}
                                </td>
                                <td
                                    data-label="Créé le"
                                    class="px-6 py-4 text-gray-400 text-xs"
                                >
                                    {{ group.createdAt }}
                                </td>
                            </tr>

                            <tr
                                v-if="expandedGroupId === group.id"
                                :id="'group-members-' + group.id"
                                class="admin-detail-row"
                            >
                                <td
                                    colspan="8"
                                    class="admin-full-cell bg-gray-50/60 px-6 py-4"
                                >
                                    <p
                                        class="mb-3 text-xs font-medium uppercase text-gray-400"
                                    >
                                        Composition du groupe ({{
                                            group.members.length
                                        }})
                                    </p>
                                    <div class="space-y-2">
                                        <div
                                            v-for="member in group.members"
                                            :key="member.id"
                                            class="flex flex-wrap items-center gap-3 rounded-lg bg-white px-4 py-2.5"
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
                                                <p
                                                    class="truncate text-sm font-medium text-equitab-navy"
                                                >
                                                    {{ member.name }}
                                                </p>
                                                <p
                                                    class="truncate text-xs text-gray-400"
                                                >
                                                    {{ member.email }}
                                                </p>
                                            </div>

                                            <span
                                                v-if="member.role === 'owner'"
                                                class="shrink-0 rounded-full bg-equitab-navy/10 px-2 py-0.5 text-xs font-medium text-equitab-navy"
                                            >
                                                Propriétaire
                                            </span>

                                            <span
                                                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="
                                                    memberStatusClass(
                                                        member.status,
                                                    )
                                                "
                                            >
                                                {{ adminLabel(member.status) }}
                                            </span>

                                            <span
                                                class="shrink-0 text-xs text-gray-400"
                                            >
                                                {{ member.joinedAt }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!groups.data.length">
                            <td colspan="8" class="admin-full-cell">
                                <p class="admin-empty">
                                    Aucun groupe pour l’instant.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <AdminPagination :page="groups" />
    </AdminLayout>
</template>
