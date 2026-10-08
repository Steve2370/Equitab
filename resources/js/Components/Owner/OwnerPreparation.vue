<script setup lang="ts">
import { computed, ref, watch } from "vue";
import type { DraftErrors, DraftInput, OwnerSubscription } from "@/types/group-draft";
import { centsFromInput, formatGroupMoney, memberLimit } from "@/utils/groupDraft";
import { isSupportedCurrency } from "@/utils/money";

const data = defineModel<DraftInput>({ required: true });
const props = defineProps<{ subscription?: OwnerSubscription; errors: DraftErrors; disabled: boolean; supportedCurrencies: string[]; enabledCurrencies: string[]; priceNotice?: string }>();
const emit = defineEmits<{ invalidPrice: [invalid: boolean]; currencyChange: [currency: string] }>();
const currencies = computed(() => props.supportedCurrencies.filter(isSupportedCurrency));
const priceText = ref("");
const priceInvalid = computed(() => priceText.value.trim() !== "" && centsFromInput(priceText.value) === null);
watch(() => data.value.total_price, (cents) => {
    if (centsFromInput(priceText.value) !== cents || (priceText.value === "" && typeof cents === "number")) {
        priceText.value = typeof cents === "number" ? (cents / 100).toFixed(2).replace(".", ",") : "";
    }
}, { immediate: true });
watch(priceInvalid, (invalid) => emit("invalidPrice", invalid));
watch(() => data.value.currency, () => {
    // Also clear raw invalid text when both the old and new minor-unit values are null.
    priceText.value = typeof data.value.total_price === "number" ? (data.value.total_price / 100).toFixed(2).replace(".", ",") : "";
});

function setPrice(event: Event): void {
    priceText.value = (event.target as HTMLInputElement).value;
    data.value.total_price = centsFromInput(priceText.value);
}
function setMembers(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    data.value.max_members = value === "" ? null : Number(value);
}
const descriptionFor = (key: string, hint?: string) => [hint, props.errors[key] ? `owner-error-${key}` : ""].filter(Boolean).join(" ") || undefined;
const visibilities = [
    { value: "public", label: "Public", description: "Visible dans le catalogue des groupes." },
    { value: "private", label: "Privé — sur invitation", description: "Absent du catalogue. Toute personne disposant de votre lien pourra demander une place et payer." },
] as const;
</script>

<template>
    <section aria-labelledby="owner-preparation-title">
        <p class="owner-eyebrow">02 · Votre groupe</p>
        <h2 id="owner-preparation-title" tabindex="-1" class="owner-step-title">Préparez le partage.</h2>
        <p class="owner-intro">Les renseignements du catalogue sont préremplis et modifiables. Un brouillon peut rester incomplet.</p>
        <fieldset :disabled="disabled" class="owner-fields">
            <legend class="sr-only">Renseignements du groupe</legend>
            <div>
                <label class="owner-label" for="owner-field-name">Nom du groupe</label>
                <input id="owner-field-name" v-model="data.name" class="owner-input" maxlength="255" :aria-invalid="!!errors.name" :aria-describedby="descriptionFor('name')" />
                <p v-if="errors.name" id="owner-error-name" class="owner-error">{{ errors.name }}</p>
            </div>
            <div class="owner-form-grid">
                <div>
                    <label class="owner-label" for="owner-field-currency">Devise du groupe</label>
                    <select id="owner-field-currency" :value="data.currency ?? ''" class="owner-input" :aria-invalid="!!errors.currency" :aria-describedby="descriptionFor('currency', 'owner-currency-hint')" @change="emit('currencyChange', ($event.target as HTMLSelectElement).value)">
                        <option value="" disabled>Choisir une devise</option>
                        <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}{{ enabledCurrencies.includes(currency) ? '' : ' · brouillon seulement' }}</option>
                    </select>
                    <p id="owner-currency-hint" class="owner-hint">La devise sera fixe après publication. La changer ici efface le prix à ressaisir.</p>
                    <p v-if="data.currency && !enabledCurrencies.includes(data.currency)" class="owner-hint" role="status">Vous pouvez enregistrer ce brouillon. La publication et les nouveaux paiements dans cette devise ne sont pas encore activés.</p>
                    <p v-if="errors.currency" id="owner-error-currency" class="owner-error">{{ errors.currency }}</p>
                </div>
                <div>
                    <label class="owner-label" for="owner-field-tier">Offre partagée</label>
                    <select id="owner-field-tier" v-model="data.tier" class="owner-input" :aria-invalid="!!errors.tier" :aria-describedby="descriptionFor('tier')">
                        <option value="standard">Standard</option><option value="premium">Premium</option><option value="famille">Famille</option>
                    </select>
                    <p v-if="errors.tier" id="owner-error-tier" class="owner-error">{{ errors.tier }}</p>
                </div>
                <div>
                    <label class="owner-label" for="owner-field-max_members">Personnes au total</label>
                    <input id="owner-field-max_members" :value="data.max_members ?? ''" type="number" min="2" :max="memberLimit(subscription)" step="1" inputmode="numeric" class="owner-input" :aria-invalid="!!errors.max_members" :aria-describedby="descriptionFor('max_members', 'owner-members-hint')" @input="setMembers" />
                    <p id="owner-members-hint" class="owner-hint">Vous compris · maximum {{ memberLimit(subscription) }} pour ce service.</p>
                    <p v-if="errors.max_members" id="owner-error-max_members" class="owner-error">{{ errors.max_members }}</p>
                </div>
                <div>
                    <label class="owner-label" for="owner-field-total_price">Prix total par mois ({{ isSupportedCurrency(data.currency) ? data.currency : 'devise à choisir' }})</label>
                    <input id="owner-field-total_price" :value="priceText" type="text" inputmode="decimal" class="owner-input" placeholder="0,00" :aria-invalid="priceInvalid || !!errors.total_price" :aria-describedby="descriptionFor('total_price', 'owner-price-hint') + (priceNotice ? ' owner-price-notice' : '') + (priceInvalid && !errors.total_price ? ' owner-price-format-error' : '')" @input="setPrice" />
                    <p id="owner-price-hint" class="owner-hint"><template v-if="subscription">Catalogue : {{ formatGroupMoney(subscription.monthly_price, subscription.currency) }} / mois. </template>Indiquez votre prix réel.</p>
                    <p v-if="priceNotice" id="owner-price-notice" class="owner-hint" role="status" aria-live="polite">{{ priceNotice }}</p>
                    <p v-if="priceInvalid && !errors.total_price" id="owner-price-format-error" class="owner-error">Utilisez un montant avec deux décimales au maximum, ou laissez le champ vide.</p>
                    <p v-if="errors.total_price" id="owner-error-total_price" class="owner-error">{{ errors.total_price }}</p>
                </div>
                <div>
                    <label class="owner-label" for="owner-field-renewal_date">Prochain renouvellement</label>
                    <input id="owner-field-renewal_date" v-model="data.renewal_date" type="date" class="owner-input" :aria-invalid="!!errors.renewal_date" :aria-describedby="descriptionFor('renewal_date', 'owner-date-hint')" />
                    <p id="owner-date-hint" class="owner-hint">La date de votre abonnement, obligatoire pour publier.</p>
                    <p v-if="errors.renewal_date" id="owner-error-renewal_date" class="owner-error">{{ errors.renewal_date }}</p>
                </div>
            </div>
            <fieldset class="owner-fields">
                <legend class="owner-label">Qui pourra découvrir votre groupe ?</legend>
                <label v-for="option in visibilities" :key="option.value" class="owner-choice" :class="{ 'is-selected': data.visibility === option.value }">
                    <input v-model="data.visibility" type="radio" name="owner-visibility" :value="option.value" :aria-invalid="!!errors.visibility" :aria-describedby="descriptionFor('visibility')" />
                    <span><strong>{{ option.label }}</strong><span class="owner-hint">{{ option.description }}</span></span>
                </label>
                <p v-if="errors.visibility" id="owner-error-visibility" class="owner-error">{{ errors.visibility }}</p>
            </fieldset>
            <div>
                <label class="owner-label" for="owner-field-description">Description <span class="owner-optional">· facultative</span></label>
                <textarea id="owner-field-description" v-model="data.description" rows="3" maxlength="1000" class="owner-input" :aria-invalid="!!errors.description" :aria-describedby="descriptionFor('description')" placeholder="Présentez votre groupe et vos attentes." />
                <p v-if="errors.description" id="owner-error-description" class="owner-error">{{ errors.description }}</p>
            </div>
            <label class="owner-check" for="owner-field-auto_renew">
                <input id="owner-field-auto_renew" v-model="data.auto_renew" type="checkbox" :aria-invalid="!!errors.auto_renew" :aria-describedby="descriptionFor('auto_renew')" />
                <span>Renouveler automatiquement le groupe</span>
            </label>
            <p v-if="errors.auto_renew" id="owner-error-auto_renew" class="owner-error">{{ errors.auto_renew }}</p>
        </fieldset>
    </section>
</template>
