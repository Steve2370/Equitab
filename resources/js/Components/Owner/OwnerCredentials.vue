<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from "vue";
import type { DraftErrors, ServiceCredentials } from "@/types/group-draft";
const credentials = defineModel<ServiceCredentials>({ required: true });
const props = withDefaults(defineProps<{ errors: DraftErrors; disabled: boolean; required?: boolean; accessMode?: "credentials" | "invitation" }>(), { required: false, accessMode: "credentials" });
const showPassword = ref(false);
function clear(): void {
    credentials.value = { credential_email: "", credential_password: "", credential_notes: "" };
    showPassword.value = false;
}
watch(() => props.accessMode, (mode) => { if (mode === "invitation") clear(); }, { immediate: true });
watch(() => credentials.value.credential_password, (value) => { if (!value) showPassword.value = false; });
onBeforeUnmount(clear);
</script>

<template>
    <p v-if="accessMode === 'invitation'" class="owner-hint">Ce service fonctionne par invitation individuelle. Après la publication, préparez les invitations dans « Gérer les accès » selon les étapes du fournisseur. Les instructions seront visibles après validation du paiement. Certains services demandent au membre d’accepter personnellement l’invitation, puis au propriétaire de le confirmer chez le fournisseur. Ne partagez jamais votre mot de passe principal, votre coffre personnel ni vos codes de récupération.</p>
    <details v-else class="owner-credentials" :open="required || !!(errors.credential_email || errors.credential_password || errors.credential_notes)">
        <summary>Ajouter les accès au service <span v-if="!required" class="owner-optional">· facultatif</span></summary>
        <p id="owner-credentials-hint" class="owner-hint">{{ required ? 'Renseignez les deux identifiants pour enregistrer un accès complet. Les valeurs existantes ne sont jamais préchargées ici. Les accès ne sont visibles que par les membres autorisés après validation du paiement.' : 'Ces accès seront transmis uniquement lors de la publication. Ils ne sont pas enregistrés dans le brouillon et devront être saisis de nouveau si vous quittez cette page.' }}</p>
        <fieldset :disabled="disabled" class="owner-fields" aria-describedby="owner-credentials-hint">
            <legend class="sr-only">{{ required ? 'Identifiants du service' : 'Accès facultatifs au service' }}</legend>
            <div>
                <label class="owner-label" for="owner-field-credential_email">Courriel du compte partagé</label>
                <input id="owner-field-credential_email" v-model="credentials.credential_email" type="email" :required="required" maxlength="255" autocomplete="off" class="owner-input" :aria-invalid="!!errors.credential_email" :aria-describedby="errors.credential_email ? 'owner-error-credential_email' : undefined" />
                <p v-if="errors.credential_email" id="owner-error-credential_email" class="owner-error">{{ errors.credential_email }}</p>
            </div>
            <div>
                <label class="owner-label" for="owner-field-credential_password">Mot de passe du compte partagé</label>
                <div class="owner-password-row">
                    <input id="owner-field-credential_password" v-model="credentials.credential_password" :type="showPassword ? 'text' : 'password'" :required="required" autocomplete="new-password" maxlength="255" class="owner-input" :aria-invalid="!!errors.credential_password" :aria-describedby="errors.credential_password ? 'owner-error-credential_password' : undefined" />
                    <button type="button" class="owner-button owner-button-secondary" :aria-pressed="showPassword" aria-controls="owner-field-credential_password" @click="showPassword = !showPassword">{{ showPassword ? 'Masquer' : 'Afficher' }}</button>
                </div>
                <p v-if="errors.credential_password" id="owner-error-credential_password" class="owner-error">{{ errors.credential_password }}</p>
            </div>
            <div>
                <label class="owner-label" for="owner-field-credential_notes">Instructions d’accès</label>
                <textarea id="owner-field-credential_notes" v-model="credentials.credential_notes" autocomplete="off" rows="3" maxlength="1000" class="owner-input" :aria-invalid="!!errors.credential_notes" :aria-describedby="errors.credential_notes ? 'owner-error-credential_notes' : undefined" />
                <p v-if="errors.credential_notes" id="owner-error-credential_notes" class="owner-error">{{ errors.credential_notes }}</p>
            </div>
        </fieldset>
    </details>
</template>
