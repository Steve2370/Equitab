<script setup lang="ts">
import { computed, ref, useId, watch } from "vue";
import type { OwnerCountryState } from "@/types/group-draft";
import { saveOwnerCountry } from "@/utils/ownerCountry";

const props = withDefaults(defineProps<{ state: OwnerCountryState; disabled?: boolean }>(), { disabled: false });
const emit = defineEmits<{ saved: [state: OwnerCountryState]; busy: [value: boolean]; pending: [value: boolean] }>();
const id = `owner-country-${useId()}`;
const selected = ref(props.state.country ?? "");
const saving = ref(false);
const error = ref("");
const success = ref("");
const dirty = computed(() => selected.value !== (props.state.country ?? ""));
const countryName = computed(() => props.state.countries.find((item) => item.code === props.state.country)?.name ?? props.state.country);
const selectedOption = computed(() => props.state.countries.find((item) => item.code === selected.value));

watch(() => props.state, (state) => {
    selected.value = state.country ?? "";
    error.value = "";
});
watch(dirty, (pending) => emit("pending", pending));
watch(selected, () => { error.value = ""; success.value = ""; });

async function save(): Promise<void> {
    if (saving.value || props.disabled || props.state.locked || !dirty.value) return;
    if (!selectedOption.value) {
        error.value = "Choisissez votre pays de résidence.";
        return;
    }
    saving.value = true;
    emit("busy", true);
    error.value = "";
    success.value = "";
    try {
        const state = await saveOwnerCountry(selected.value);
        emit("saved", state);
        success.value = "Pays enregistré. La devise de vos groupes reste inchangée.";
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : "Le pays n’a pas pu être enregistré. Veuillez réessayer.";
    } finally {
        saving.value = false;
        emit("busy", false);
    }
}
</script>

<template>
    <section class="owner-country" :aria-labelledby="`${id}-title`" :aria-busy="saving">
        <h3 :id="`${id}-title`" class="owner-country-title">Pays de résidence</h3>
        <template v-if="state.locked">
            <p v-if="countryName" class="owner-country-value">{{ countryName }}</p>
            <p :id="`${id}-hint`" class="owner-country-hint">
                {{ state.country ? 'Le pays est lié à votre configuration Stripe et ne peut plus être modifié ici. Contactez le soutien si votre situation a changé.' : 'Un compte Stripe ou une activation existe déjà. Aucun nouveau choix de pays n’est nécessaire pour continuer.' }}
            </p>
        </template>
        <template v-else>
            <p :id="`${id}-hint`" class="owner-country-hint">Choisissez votre pays de résidence réel. Il sera verrouillé dès le début de l’activation Stripe. Ce choix ne modifie pas la devise de vos groupes.</p>
            <div class="owner-country-controls">
                <div class="owner-country-field">
                    <label :for="id" class="owner-country-label">Votre pays</label>
                    <select :id="id" v-model="selected" autocomplete="country" :disabled="disabled || saving" :aria-invalid="!!error" :aria-describedby="`${id}-hint ${id}-availability${error ? ` ${id}-error` : ''}`">
                        <option value="" disabled>Choisir mon pays</option>
                        <option v-for="country in state.countries" :key="country.code" :value="country.code">{{ country.name }}{{ country.enabled ? '' : ' · préparation seulement' }}</option>
                    </select>
                </div>
                <button type="button" :disabled="disabled || saving || !dirty || !selected" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer le pays' }}</button>
            </div>
            <p v-if="dirty" class="owner-country-hint">Le changement de pays efface l’adresse enregistrée pour éviter de réutiliser une adresse d’un autre pays.</p>
        </template>
        <p :id="`${id}-availability`" class="owner-country-hint" role="status">
            <template v-if="!state.locked && selectedOption && !selectedOption.enabled">Vous pouvez préparer votre profil et vos brouillons. L’activation des paiements pour ce pays n’est pas encore ouverte.</template>
            <template v-else-if="!state.canStart && !state.country">Enregistrez votre pays avant d’activer les paiements.</template>
        </p>
        <p v-if="error" :id="`${id}-error`" class="owner-country-error" role="alert">{{ error }}</p>
        <p class="owner-country-success" role="status">{{ success }}</p>
    </section>
</template>

<style scoped>
.owner-country { min-width: 0; padding: 18px; margin-bottom: 18px; border: 1px solid #dbe3de; border-radius: 12px; background: #fff; color: #192a24; overflow-wrap: anywhere; }
.owner-country-title { font-size: .95rem; font-weight: 600; }
.owner-country-value { margin-top: 8px; font-weight: 600; }
.owner-country-hint { margin-top: 8px; color: #59685f; font-size: .8rem; line-height: 1.6; }
.owner-country-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-top: 14px; }
.owner-country-field { flex: 1 1 190px; min-width: 0; }
.owner-country-label { display: block; margin-bottom: 6px; font-size: .83rem; font-weight: 600; }
.owner-country select { width: 100%; max-width: 100%; min-height: 46px; border: 1px solid #bac8bf; border-radius: 10px; padding: 10px 32px 10px 12px; background-color: #fff; color: inherit; font-size: 1rem; }
.owner-country button { min-height: 46px; padding: 10px 14px; border: 1px solid #167447; border-radius: 10px; color: #167447; background: #fff; font: inherit; font-size: .85rem; font-weight: 600; cursor: pointer; }
.owner-country button:hover:not(:disabled) { background: #f6faf7; }
.owner-country :is(button, select):focus-visible { outline: 3px solid #167447; outline-offset: 3px; }
.owner-country :disabled { cursor: not-allowed; opacity: .6; }
.owner-country select[aria-invalid="true"] { border-color: #b42318; }
.owner-country-error { margin-top: 10px; font-size: .85rem; line-height: 1.6; color: #b42318; }
.owner-country-success { margin-top: 8px; font-size: .8rem; color: #167447; }
.owner-country-success:empty { margin: 0; }
@media (max-width: 540px) { .owner-country-controls { display: grid; grid-template-columns: minmax(0, 1fr); } .owner-country button { width: 100%; } }
</style>
