<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import DashboardLayout from "@/Layouts/DashboardLayout.vue";
import OwnerServicePicker from "@/Components/Owner/OwnerServicePicker.vue";
import OwnerPreparation from "@/Components/Owner/OwnerPreparation.vue";
import OwnerGroupPreview from "@/Components/Owner/OwnerGroupPreview.vue";
import OwnerActivation from "@/Components/Owner/OwnerActivation.vue";
import OwnerCredentials from "@/Components/Owner/OwnerCredentials.vue";
import { useGroupDraft } from "@/composables/useGroupDraft";
import { preparationErrors, safeDraftRecord, serviceDefaults } from "@/utils/groupDraft";
import type { GroupDraft, OwnerActivation as ActivationKind, OwnerReadiness, OwnerSubscription, ServiceCredentials } from "@/types/group-draft";
import "@/Components/Owner/owner.css";

const props = defineProps<{ subscriptions: OwnerSubscription[]; draft: GroupDraft | null; ownerReadiness: OwnerReadiness; supportedCurrencies: string[]; enabledCurrencies: string[] }>();
type Step = 1 | 2 | 3;
const step = ref<Step>(props.draft ? (props.draft.status !== "draft" ? 3 : props.draft.data.subscription_id ? 2 : 1) : 1);
const steps = ["Service", "Préparation", "Publication"] as const;
const errorSummary = ref<HTMLElement>();
const priceInvalid = ref(false);
const certify = ref(false);
const publicationNeedsRefresh = ref(false);
const emptyCredentials = (): ServiceCredentials => ({ credential_email: "", credential_password: "", credential_notes: "" });
// Deliberately outside the draft, Inertia forms/remember, page props and browser storage.
const credentials = ref<ServiceCredentials>(emptyCredentials());
const clearCredentials = () => { credentials.value = emptyCredentials(); certify.value = false; };
const hasCredentials = computed(() => Object.values(credentials.value).some(Boolean));

function rememberConfirmedDraft(draft: GroupDraft): Promise<void> {
    // Replace history with only the confirmed allowlisted snapshot. Live fields stay in memory.
    const snapshot = safeDraftRecord(draft);
    return new Promise((resolve) => router.replace({
        url: `/dashboard/groups/drafts/${encodeURIComponent(snapshot.id)}`,
        props: (current) => ({ ...current, draft: snapshot }),
        preserveState: true, preserveScroll: true,
        onFinish: () => resolve(),
    }));
}
const { state, saved, busy, locked, save, activate, publish, reopen, changeCurrency } = useGroupDraft(() => props.draft, rememberConfirmedDraft);
const selected = computed(() => props.subscriptions.find((service) => service.id === state.data.subscription_id));
const usesInvitation = computed(() => selected.value?.access_mode === "invitation");
const isReady = computed(() => props.ownerReadiness.ready && props.ownerReadiness.identityVerified && props.ownerReadiness.connectActive);
const canAttemptPublication = computed(() => !state.conflict && !state.leaving && state.saved?.status !== "published");
const edited = computed(() => state.saved
    ? !saved.value
    : state.data.subscription_id !== null || !!state.data.name || !!state.data.description);
const saveStatus = computed(() => {
    if (state.operation === "saving") return "Enregistrement en cours…";
    if (state.conflict) return "Votre saisie est conservée ici. La version du serveur doit être rechargée.";
    if (state.saved?.status === "published") return "Groupe publié.";
    if (state.saved?.status === "publishing") return "Publication en cours ou interrompue. Actualisez son état ou rouvrez le brouillon pour le modifier.";
    if (saved.value && !priceInvalid.value) return "Brouillon enregistré. Vous pouvez le retrouver dans Mes abonnements.";
    return state.saved ? "Modifications non enregistrées." : "Brouillon non enregistré. Il reste privé.";
});
const errors = computed(() => Object.entries(state.errors));

async function goToStep(next: Step): Promise<void> {
    if (busy.value || (next > 1 && !selected.value)) return;
    if (next === 3 && !locked.value) {
        state.errors = preparationErrors(state.data, selected.value, props.enabledCurrencies);
        if (priceInvalid.value) state.errors.total_price = "Corrigez le format du prix avant de continuer.";
        if (Object.keys(state.errors).length) {
            step.value = 2;
            state.message = "Complétez les renseignements nécessaires à la publication. Vous pouvez aussi enregistrer un brouillon incomplet.";
            await focusSummary();
            return;
        }
    }
    step.value = next;
    await nextTick();
    document.getElementById(["owner-service-title", "owner-preparation-title", "owner-publication-title"][next - 1])?.focus();
}
function selectService(subscription: OwnerSubscription): void {
    if (busy.value || locked.value) return;
    if (state.data.subscription_id !== subscription.id) {
        Object.assign(state.data, serviceDefaults(subscription, state.data.currency));
        clearCredentials();
        state.errors = {};
    }
    void goToStep(2);
}
async function focusSummary(): Promise<void> {
    await nextTick();
    errorSummary.value?.focus();
}
async function saveDraft(): Promise<void> {
    if (priceInvalid.value) return;
    await save();
    if (state.message || errors.value.length) await focusSummary();
}
async function startActivation(kind: ActivationKind): Promise<void> {
    if (priceInvalid.value) return;
    const url = await activate(kind);
    if (url) { clearCredentials(); window.location.assign(url); }
    else if (state.message) await focusSummary();
}
async function refreshReadiness(): Promise<void> {
    if (busy.value || locked.value || priceInvalid.value) return;
    const draft = await save();
    if (!draft || !saved.value) { await focusSummary(); return; }
    clearCredentials();
    state.leaving = true;
    // This authenticated return route refreshes Connect/Identity before reopening the draft.
    window.location.assign(`/stripe/onboarding/return?draft_id=${encodeURIComponent(draft.id)}`);
}
async function publishGroup(): Promise<void> {
    if (busy.value || !canAttemptPublication.value) return;
    state.errors = preparationErrors(state.data, selected.value, props.enabledCurrencies);
    if (Object.keys(state.errors).length || priceInvalid.value) { await goToStep(3); return; }
    const result = await publish(props.ownerReadiness, certify.value, usesInvitation.value ? emptyCredentials() : credentials.value);
    if (result) {
        clearCredentials();
        router.visit(result.redirect);
    } else if (state.message) {
        publicationNeedsRefresh.value = true;
        await focusSummary();
    }
}
async function reopenDraft(): Promise<void> {
    const result = await reopen();
    if (result?.status === "draft") {
        publicationNeedsRefresh.value = false;
        clearCredentials();
        await goToStep(selected.value ? 2 : 1);
    } else if (state.message) await focusSummary();
}
function reloadDraft(): void {
    if (busy.value) return;
    if ((edited.value || hasCredentials.value || priceInvalid.value)
        && !window.confirm("Recharger remplacera votre saisie non enregistrée par la version du serveur et effacera les accès saisis. Voulez-vous continuer ?")) return;
    clearCredentials();
    window.location.assign(state.id ? `/dashboard/groups/drafts/${encodeURIComponent(state.id)}` : "/dashboard/groups/create");
}
function fieldTarget(key: string): string | null {
    if (key === "subscription_id") return "owner-service-search";
    if (key === "visibility") return "owner-preparation-title";
    if (["name", "tier", "currency", "max_members", "total_price", "renewal_date", "description", "auto_renew", "credential_email", "credential_password", "credential_notes", "certify"].includes(key)) return `owner-field-${key}`;
    return null;
}
async function focusField(key: string): Promise<void> {
    step.value = key === "subscription_id" ? 1 : key.startsWith("credential_") || key === "certify" ? 3 : 2;
    await nextTick();
    const target = fieldTarget(key);
    if (target) document.getElementById(target)?.focus();
}
watch(() => state.data, () => { certify.value = false; }, { deep: true });
watch(usesInvitation, () => clearCredentials());
watch(() => props.draft?.id, (id, previous) => {
    if (previous && id !== previous) {
        clearCredentials();
        step.value = props.draft?.data.subscription_id ? 2 : 1;
    }
});
function warnBeforeUnload(event: BeforeUnloadEvent): void {
    if (!state.leaving && (edited.value || hasCredentials.value || busy.value || priceInvalid.value)) {
        event.preventDefault(); event.returnValue = "";
    }
}
const removeBeforeListener = router.on("before", (event) => {
    if (state.leaving) return;
    if (busy.value) { event.preventDefault(); return; }
    if ((edited.value || hasCredentials.value || priceInvalid.value)
        && !window.confirm("Quitter cette page effacera votre saisie non enregistrée et les accès au service. Voulez-vous continuer ?")) event.preventDefault();
});
onMounted(() => {
    window.addEventListener("beforeunload", warnBeforeUnload);
    window.addEventListener("pagehide", clearCredentials);
});
onBeforeUnmount(() => {
    removeBeforeListener();
    window.removeEventListener("beforeunload", warnBeforeUnload);
    window.removeEventListener("pagehide", clearCredentials);
    clearCredentials();
});
</script>

<template>
    <Head title="Créer un groupe — EquitAb" />
    <DashboardLayout hide-verification-notice>
        <div class="owner-flow">
            <header>
                <p class="owner-eyebrow">Le partage commence ici</p>
                <h1>Votre abonnement.<br />Votre futur groupe.</h1>
                <p class="owner-intro">Préparez votre groupe à votre rythme. Enregistrez-le maintenant, publiez-le quand vous serez prêt.</p>
                <Link href="/dashboard/subscriptions" class="owner-text-button">Mes abonnements et brouillons</Link>
            </header>
            <nav aria-label="Étapes de création">
                <ol class="owner-steps">
                    <li v-for="(label, index) in steps" :key="label">
                        <button type="button" :aria-current="step === index + 1 ? 'step' : undefined" :disabled="busy || (index > 0 && !selected)" @click="goToStep((index + 1) as Step)">
                            <span class="owner-step-number" aria-hidden="true">{{ index + 1 }}</span><span>{{ label }}</span>
                        </button>
                    </li>
                </ol>
            </nav>
            <div v-if="state.message || errors.length" ref="errorSummary" class="owner-alert" role="alert" tabindex="-1" aria-labelledby="owner-error-title">
                <p id="owner-error-title">{{ state.message || 'Vérifiez les renseignements indiqués.' }}</p>
                <ul v-if="errors.length">
                    <li v-for="[key, message] in errors" :key="key"><a v-if="fieldTarget(key)" :href="`#${fieldTarget(key)}`" @click.prevent="focusField(key)">{{ message }}</a><span v-else>{{ message }}</span></li>
                </ul>
                <button v-if="state.conflict || publicationNeedsRefresh" type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="reloadDraft">{{ state.conflict ? 'Recharger la version du serveur' : 'Actualiser l’état de la publication' }}</button>
            </div>
            <div v-if="state.saved?.status === 'publishing'" class="owner-alert" role="status">
                <p>La publication est en cours ou a été interrompue. Vous pouvez actualiser son état, ou rouvrir le brouillon pour corriger ses renseignements. La réouverture ne publie rien.</p>
                <button type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="reloadDraft">Actualiser l’état</button>
                <button type="button" class="owner-button" :disabled="busy || state.conflict" @click="reopenDraft">{{ state.operation === 'reopening' ? 'Réouverture…' : 'Modifier le brouillon' }}</button>
            </div>
            <div v-if="state.saved?.status === 'published'" class="owner-ready" role="status">Ce groupe est publié. <Link href="/dashboard/subscriptions" class="owner-text-button">Voir mes abonnements</Link></div>
            <div class="owner-content" :class="{ 'with-preview': step > 1 }" :aria-busy="busy">
                <div class="min-w-0">
                    <OwnerServicePicker v-show="step === 1" :subscriptions="subscriptions" :selected="state.data.subscription_id ?? null" :disabled="busy || locked" :error="state.errors.subscription_id" @select="selectService" />
                    <OwnerPreparation v-show="step === 2" v-model="state.data" :subscription="selected" :errors="state.errors" :disabled="busy || locked" :supported-currencies="supportedCurrencies" :enabled-currencies="enabledCurrencies" :price-notice="state.priceNotice" @currency-change="changeCurrency($event, supportedCurrencies)" @invalid-price="priceInvalid = $event" />
                    <section v-show="step === 3" aria-labelledby="owner-publication-title">
                        <p class="owner-eyebrow">03 · À vous de publier</p>
                        <h2 id="owner-publication-title" tabindex="-1" class="owner-step-title">Tout est prêt pour partager ?</h2>
                        <OwnerActivation :readiness="ownerReadiness" :disabled="busy || locked || priceInvalid" :operation="state.operation" @activate="startActivation" @refresh="refreshReadiness" />
                        <div v-if="isReady && canAttemptPublication" class="owner-publication">
                            <OwnerCredentials v-model="credentials" :errors="state.errors" :disabled="busy" :access-mode="selected?.access_mode ?? 'credentials'" />
                            <label class="owner-check" for="owner-field-certify">
                                <input id="owner-field-certify" v-model="certify" type="checkbox" :disabled="busy" :aria-invalid="!!state.errors.certify" :aria-describedby="state.errors.certify ? 'owner-error-certify' : undefined" />
                                <span>Je certifie que cet abonnement m’appartient et que son partage respecte les conditions du service.</span>
                            </label>
                            <p v-if="state.errors.certify" id="owner-error-certify" class="owner-error">{{ state.errors.certify }}</p>
                            <p class="owner-hint">Le bouton « Publier mon groupe » rendra le groupe disponible selon la visibilité choisie, après les contrôles du serveur.</p>
                        </div>
                    </section>
                </div>
                <OwnerGroupPreview v-if="step > 1" :data="state.data" :subscription="selected" :preview="saved ? state.saved?.preview : null" />
            </div>
            <footer>
                <div class="owner-actions">
                    <button v-if="step > 1" type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="goToStep((step - 1) as Step)">Retour</button>
                    <button type="button" class="owner-button owner-button-secondary" :disabled="busy || locked || priceInvalid" @click="saveDraft">{{ state.operation === 'saving' ? 'Enregistrement…' : 'Enregistrer et reprendre plus tard' }}</button>
                    <button v-if="step < 3" type="button" class="owner-button" :disabled="busy || !selected || (step === 2 && priceInvalid)" @click="goToStep((step + 1) as Step)">{{ step === 1 ? 'Préparer mon groupe' : 'Continuer vers la publication' }}</button>
                    <button v-else-if="state.saved?.status !== 'published'" type="button" class="owner-button" :disabled="busy || !canAttemptPublication || !isReady || !certify || priceInvalid" @click="publishGroup">{{ state.operation === 'publishing' ? 'Publication en cours…' : state.saved?.status === 'publishing' ? 'Réessayer la publication' : 'Publier mon groupe' }}</button>
                </div>
                <p class="owner-save-status" role="status" aria-live="polite">{{ saveStatus }}</p>
            </footer>
        </div>
    </DashboardLayout>
</template>
