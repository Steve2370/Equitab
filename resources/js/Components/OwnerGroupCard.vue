<script setup lang="ts">
import { ref, computed } from "vue";
import { ArrowUpRight, Users, Calendar, ShieldCheck } from "lucide-vue-next";
import TierBadge from "@/Components/TierBadge.vue";
import StripeCardForm from "@/Components/StripeCardForm.vue";
import CollectionCard from "@/Components/Experience/CollectionCard.vue";
import ExperienceDialog from "@/Components/Experience/ExperienceDialog.vue";
import { isPaymentQuote, type PaymentQuote } from "@/utils/paymentQuote";
import { formatMoney } from "@/utils/money";
interface Props {
    groupId: number;
    ownerName: string;
    ownerIdentityStatus: string;
    ownerActiveGroupsCount: number;
    ownerTrustScore: number | null;
    tier: "standard" | "premium" | "famille";
    pricePerMember: number;
    currency: string;
    spotsAvailable: number;
    maxMembers: number;
    createdAt: string;
    subscriptionName: string;
    subscriptionSlug?: string;
    description?: string | null;
    motion?: boolean;
    index?: number;
    compact?: boolean;
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
const prorationData = ref<PaymentQuote | null>(null);
async function openSubscribeForm(): Promise<void> {
    if (loading.value || props.spotsAvailable <= 0) return;
    loading.value = true;
    error.value = "";
    try {
        const response = await fetch(
            "/api/groups/" + props.groupId + "/proration",
            { headers: { Accept: "application/json" }, cache: "no-store" },
        );
        const data = await response.json();
        if (!response.ok) {
            error.value =
                data.message ||
                "Impossible de charger les montants. Veuillez réessayer.";
            return;
        }
        if (!isPaymentQuote(data))
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
    <!-- Ligne compacte : ce qui différencie les groupes passe en premier. -->
    <article
        v-if="compact"
        :id="'group-' + groupId"
        class="group-row eq-reveal"
        :style="{ '--reveal-index': ((index ?? 1) - 1) % 6 }"
    >
        <div class="group-row-owner">
            <span class="group-row-avatar" aria-hidden="true">{{ ownerName.charAt(0) }}</span>
            <div>
                <p class="group-row-title">Avec {{ ownerName }}</p>
                <p class="group-row-meta" :class="{ verified: isVerified }">
                    <ShieldCheck :size="15" aria-hidden="true" />{{ isVerified ? "Identité vérifiée" : "Identité non vérifiée" }}
                </p>
            </div>
        </div>
        <div class="group-row-places">
            <strong :class="{ full: spotsAvailable === 0 }">{{ spotsAvailable === 0 ? "Complet" : spotsAvailable + (spotsAvailable > 1 ? " places libres" : " place libre") }}</strong>
            <span>{{ maxMembers - spotsAvailable }} / {{ maxMembers }} membres</span>
        </div>
        <div class="group-row-price">
            <strong>{{ formatMoney(pricePerMember, currency) }}</strong>
            <span>votre part par mois</span>
        </div>
        <button type="button" class="eq-button group-row-action" :aria-label="'Découvrir le groupe de ' + ownerName" @click="detailOpen = true">
            Découvrir <ArrowUpRight :size="17" aria-hidden="true" />
        </button>
    </article>
    <CollectionCard
        v-else
        :id="'group-' + groupId"
        :name="subscriptionName"
        :slug="slug"
        category="À PARTAGER ENSEMBLE"
        eyebrow="UN GROUPE À DÉCOUVRIR"
        :price="pricePerMember"
        :currency="currency"
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
                :currency="prorationData.currency"
                :next-billing-date="prorationData.next_billing_date"
                :subscription-name="subscriptionName"
                @success="onSuccess"
                @cancel="showForm = false"
            />
        </div>
    </ExperienceDialog>
</template>
<style scoped>
.group-row {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr) auto;
    align-items: center;
    gap: 24px;
    padding: 20px 24px;
    background: #fff;
    border: 1px solid #dededf;
    border-radius: 20px;
    box-shadow: 0 15px 34px -30px #15172170;
}
.group-row-owner {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}
.group-row-avatar {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    flex: none;
    border-radius: 50%;
    background: #dff3e9;
    color: #187a57;
    font-weight: 700;
    font-size: 18px;
}
.group-row-title {
    font-size: 17px;
    font-weight: 650;
}
.group-row-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 3px;
    font-size: 13px;
    color: #6b6d77;
}
.group-row-meta.verified {
    color: #187a57;
}
.group-row-places,
.group-row-price {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.group-row-places strong {
    font-size: 15px;
    color: #187a57;
}
.group-row-places strong.full {
    color: #8a5a00;
}
.group-row-places span,
.group-row-price span {
    font-size: 13px;
    color: #6b6d77;
}
.group-row-price strong {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -0.04em;
}
.group-row-action {
    min-height: 46px;
}
@media (prefers-reduced-motion: no-preference) {
    .group-row {
        transition:
            translate 0.25s ease-out,
            box-shadow 0.25s ease-out,
            border-color 0.25s ease-out;
    }
    .group-row:hover {
        translate: 0 -3px;
        border-color: #b9d9c8;
        box-shadow: 0 22px 40px -28px #151721a0;
    }
}
@media (max-width: 760px) {
    .group-row {
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        padding: 18px;
    }
    .group-row-owner {
        grid-column: 1 / -1;
    }
    .group-row-action {
        grid-column: 1 / -1;
        justify-content: center;
    }
}
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
