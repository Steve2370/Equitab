<script setup lang="ts">
import { ref, computed } from "vue";
import { ArrowUpRight, Users, Calendar, ShieldCheck } from "lucide-vue-next";
import TierBadge from "@/Components/TierBadge.vue";
import StripeCardForm from "@/Components/StripeCardForm.vue";
import CollectionCard from "@/Components/Experience/CollectionCard.vue";
import ExperienceDialog from "@/Components/Experience/ExperienceDialog.vue";
interface Props {
    groupId: number;
    ownerName: string;
    ownerIdentityStatus: string;
    ownerActiveGroupsCount: number;
    ownerTrustScore: number | null;
    tier: "standard" | "premium" | "famille";
    pricePerMember: number;
    spotsAvailable: number;
    maxMembers: number;
    createdAt: string;
    subscriptionName: string;
    subscriptionSlug?: string;
    description?: string | null;
    motion?: boolean;
    index?: number;
}
const props = defineProps<Props>();
const detailOpen = ref(false);
const showForm = ref(false);
const subscribed = ref(false);
const loading = ref(false);
const error = ref("");
const isVerified = computed(() => props.ownerIdentityStatus === "verified");
const slug = computed(
    () =>
        props.subscriptionSlug ||
        props.subscriptionName
            .toLowerCase()
            .replace(/\+/g, "-plus")
            .replace(/\s+/g, "-"),
);
const prorationData = ref<{
    amount_today: number;
    amount_recurring: number;
    next_billing_date: string;
} | null>(null);
async function openSubscribeForm(): Promise<void> {
    if (loading.value || props.spotsAvailable <= 0) return;
    loading.value = true;
    error.value = "";
    try {
        const response = await fetch(
            "/api/groups/" + props.groupId + "/proration",
            { headers: { Accept: "application/json" } },
        );
        const data = await response.json();
        if (!response.ok) {
            error.value =
                data.message ||
                "Impossible de charger les montants. Veuillez réessayer.";
            return;
        }
        if (
            !Number.isFinite(data.amount_today) ||
            !Number.isFinite(data.amount_recurring)
        )
            throw new Error("Invalid amounts");
        prorationData.value = data;
        showForm.value = true;
    } catch {
        error.value =
            "Les montants ne sont pas disponibles. Réessayez dans un instant.";
    } finally {
        loading.value = false;
    }
}
function close() {
    detailOpen.value = false;
    showForm.value = false;
    error.value = "";
}
function onSuccess() {
    showForm.value = false;
    subscribed.value = true;
}
</script>
<template>
    <CollectionCard
        :id="'group-' + groupId"
        :name="subscriptionName"
        :slug="slug"
        category="À PARTAGER ENSEMBLE"
        eyebrow="UN GROUPE À DÉCOUVRIR"
        :price="pricePerMember"
        price-label="Part estimée après votre arrivée"
        :members="maxMembers - spotsAvailable"
        :capacity="maxMembers"
        :owner="ownerName"
        :motion="motion"
        :index="index"
    >
        <template #action
            ><button
                type="button"
                :aria-label="'Découvrir le groupe de ' + ownerName"
                @click="detailOpen = true"
            >
                Découvrir
                <span class="eq-round-arrow"
                    ><ArrowUpRight :size="18" aria-hidden="true"
                /></span></button
        ></template>
    </CollectionCard>
    <ExperienceDialog
        :open="detailOpen"
        :title="subscriptionName + ' · avec ' + ownerName"
        @close="close"
    >
        <div class="group-details">
            <div class="group-badges">
                <span :class="{ verified: isVerified }"
                    ><ShieldCheck :size="15" aria-hidden="true" />{{
                        isVerified
                            ? "Identité vérifiée"
                            : "Identité non vérifiée"
                    }}</span
                ><TierBadge :tier="tier" />
            </div>
            <p v-if="description" class="group-description">
                {{ description }}
            </p>
            <dl>
                <div>
                    <dt><Users :size="15" /> Places disponibles</dt>
                    <dd>
                        {{ Math.max(0, spotsAvailable) }} / {{ maxMembers }}
                    </dd>
                </div>
                <div>
                    <dt>Partages actifs du propriétaire</dt>
                    <dd>{{ ownerActiveGroupsCount }}</dd>
                </div>
                <div v-if="createdAt">
                    <dt><Calendar :size="15" /> Groupe créé le</dt>
                    <dd>{{ createdAt }}</dd>
                </div>
                <div
                    v-if="
                        ownerTrustScore !== null &&
                        Number.isFinite(ownerTrustScore)
                    "
                >
                    <dt>Score du propriétaire</dt>
                    <dd>{{ ownerTrustScore }} %</dd>
                </div>
            </dl>
            <p class="group-note">
                Consultez les conditions de partage de {{ subscriptionName }}.
                Une identité vérifiée ne garantit pas la prestation. Votre part
                peut évoluer selon le nombre de membres.
            </p>
            <p v-if="error" role="alert" class="group-error">{{ error }}</p>
            <p v-if="subscribed" role="status">Abonnement actif</p>
            <button
                v-else-if="!showForm"
                type="button"
                class="eq-button w-full"
                :disabled="loading || spotsAvailable <= 0"
                @click="openSubscribeForm"
            >
                {{
                    loading
                        ? "Chargement des montants…"
                        : spotsAvailable <= 0
                          ? "Ce groupe est complet"
                          : "Voir le récapitulatif de paiement"
                }}
            </button>
            <StripeCardForm
                v-if="showForm && prorationData"
                :group-id="groupId"
                :price-per-member="prorationData.amount_recurring"
                :amount-today="prorationData.amount_today"
                :next-billing-date="prorationData.next_billing_date"
                :subscription-name="subscriptionName"
                @success="onSuccess"
                @cancel="showForm = false"
            />
        </div>
    </ExperienceDialog>
</template>
<style scoped>
.group-badges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
.group-badges > span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f2f2ed;
    color: #686b70;
    font-size: 11px;
    padding: 8px 12px;
    border-radius: 22px;
}
.group-badges .verified {
    background: #edf3e5;
    color: #187a57;
}
.group-description {
    margin: 20px 0;
    font-size: 13px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
dl {
    margin-block: 22px;
}
dl > div {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 18px;
    padding: 12px 0;
    border-bottom: 1px solid #e7e7e3;
    font-size: 11px;
}
dt {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #686b70;
}
dd {
    text-align: right;
    font-weight: 550;
    flex: none;
}
.group-note {
    font-size: 11px;
    line-height: 1.8;
    color: #686b70;
    margin-block: 20px;
}
.group-error {
    font-size: 12px;
    padding: 12px;
    border-radius: 10px;
    color: #a1392f;
    background: #fff0ed;
    margin-bottom: 16px;
}
</style>
