<script setup lang="ts">
import { computed } from "vue";
import type { DraftInput, DraftPreview, OwnerSubscription } from "@/types/group-draft";
import { formatGroupMoney, fullGroupShare } from "@/utils/groupDraft";

const props = defineProps<{ data: DraftInput; subscription?: OwnerSubscription; preview?: DraftPreview | null }>();
const currency = computed(() => props.data.currency ?? "");
const confirmedPreview = computed(() => props.preview
    && props.preview.total_price === props.data.total_price
    && props.preview.max_members === props.data.max_members
    && props.preview.currency === currency.value ? props.preview : null);
const share = computed(() => confirmedPreview.value?.full_group_share ?? fullGroupShare(props.data));
const visibility = computed(() => props.data.visibility === "public" ? "Public" : props.data.visibility ? "Privé — sur invitation" : "À préciser");
</script>

<template>
    <aside class="owner-preview" aria-label="Prévisualisation du groupe">
        <p class="owner-eyebrow">Aperçu du groupe</p>
        <h3>{{ data.name || 'Votre futur groupe' }}</h3>
        <p class="owner-hint">{{ subscription?.name ?? 'Service à choisir' }} · {{ data.tier || 'Offre à préciser' }}</p>
        <div class="owner-preview-amount">
            <strong>{{ share === null ? 'À compléter' : formatGroupMoney(share, currency) }}</strong>
            <span v-if="share !== null">par personne / mois · {{ currency }}</span>
        </div>
        <p class="owner-estimate">Estimation pour un groupe complet<template v-if="data.max_members"> de {{ data.max_members }} personnes, vous compris</template>.</p>
        <p class="owner-hint">Répartition égale du prix déclaré. Les frais éventuels ne sont pas inclus.</p>
        <dl class="owner-preview-details">
            <div><dt>Prix total déclaré</dt><dd>{{ typeof data.total_price === 'number' ? formatGroupMoney(data.total_price, currency) + ' / mois' : 'À préciser' }}</dd></div>
            <div><dt>Visibilité</dt><dd>{{ visibility }}</dd></div>
            <div><dt>Renouvellement</dt><dd>{{ data.renewal_date ? data.renewal_date.split('-').reverse().join('/') : 'Date à préciser' }}</dd></div>
        </dl>
        <p class="owner-hint">Les places ne sont pas encore occupées. Cette estimation ne garantit aucun revenu.</p>
        <p class="owner-private-note">Votre brouillon reste privé jusqu’à votre publication.</p>
    </aside>
</template>
