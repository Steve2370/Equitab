<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import DashboardLayout from "@/Layouts/DashboardLayout.vue";
import OwnerCredentials from "@/Components/Owner/OwnerCredentials.vue";
import { ownerCountryCsrfToken } from "@/utils/ownerCountry";
import type { DraftErrors, ServiceCredentials } from "@/types/group-draft";
import "@/Components/Owner/owner.css";

interface MemberAccess {
    id: number;
    name: string;
    email: string | null;
    status: string;
    provided_at: string | null;
    revoked_at: string | null;
    revoke_required: boolean;
}
interface GroupAccess {
    id: number;
    name: string;
    mode: "credentials" | "invitation";
    invitation_channel: "link" | "provider_email" | null;
    closed: boolean;
    credentials_ready: boolean;
    members: MemberAccess[];
}
const props = defineProps<{ group: GroupAccess }>();
const providerEmail = computed(() => props.group.invitation_channel === "provider_email");
const supportedInvitation = computed(() => ["link", "provider_email"].includes(props.group.invitation_channel ?? ""));
const emptyCredentials = (): ServiceCredentials => ({ credential_email: "", credential_password: "", credential_notes: "" });
// Secrets are transient only: never Inertia forms, remembered state, props or browser storage.
const credentials = ref<ServiceCredentials>(emptyCredentials());
const invitationUrl = ref("");
const invitationSent = ref(false);
const removedAtProvider = ref(false);
const editor = ref<{ member: number; action: "deliver" | "revoke" } | null>(null);
const busy = ref(false);
const errors = ref<DraftErrors>({});
const error = ref("");
const success = ref("");
const errorSummary = ref<HTMLElement>();
let controller: AbortController | null = null;
let disposed = false;
let generation = 0;

function clearSecrets(): void {
    credentials.value = emptyCredentials();
    invitationUrl.value = "";
    invitationSent.value = false;
    removedAtProvider.value = false;
}
function resetEditor(): void {
    clearSecrets();
    editor.value = null;
    errors.value = {};
    error.value = "";
}
function canDeliver(member: MemberAccess): boolean {
    return !props.group.closed && !member.revoke_required && !member.revoked_at && supportedInvitation.value
        && (member.status === "active" || (!providerEmail.value && member.status === "pending_payment"));
}
async function editMember(member: MemberAccess, action: "deliver" | "revoke"): Promise<void> {
    if (busy.value || (action === "deliver" ? !canDeliver(member) : !member.revoke_required)) return;
    resetEditor();
    success.value = "";
    editor.value = { member: member.id, action };
    await nextTick();
    document.getElementById(action === "revoke" ? `removed-${member.id}` : `invitation-${member.id}`)?.focus();
}
function memberStatus(status: string): string {
    const labels: Record<string, string> = { active: "Actif", pending_payment: "Paiement en attente", left: "Parti", kicked: "Retiré du groupe", suspended: "Suspendu" };
    return labels[status] ?? "Adhésion indisponible";
}
function dateLabel(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? "date indisponible" : new Intl.DateTimeFormat("fr-CA", { dateStyle: "medium", timeStyle: "short" }).format(date);
}
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === "object" && value !== null && !Array.isArray(value);
}
async function announceError(message: string): Promise<void> {
    error.value = message;
    await nextTick();
    errorSummary.value?.focus();
}

// One transport boundary for all three forms. Eligibility and URL validation belong to PHP.
async function submit(path: string, method: "PUT" | "POST", payload: Record<string, string | boolean>, confirmation: string): Promise<void> {
    if (busy.value || disposed) return;
    errors.value = {};
    error.value = "";
    success.value = "";
    const token = ownerCountryCsrfToken(document.cookie);
    if (!token) { await announceError("Votre session doit être actualisée. Rechargez la page avant de réessayer."); return; }
    busy.value = true;
    const requestGeneration = generation;
    controller = new AbortController();
    try {
        const response = await fetch(path, {
            method, credentials: "same-origin", redirect: "error", cache: "no-store", signal: controller.signal,
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-XSRF-TOKEN": token },
            body: JSON.stringify(payload),
        });
        const data: unknown = await response.json().catch(() => null);
        if (disposed || requestGeneration !== generation) return;
        if (!response.ok) {
            if (response.status === 422 && isRecord(data) && isRecord(data.errors)) {
                for (const key of ["credential_email", "credential_password", "credential_notes", "invitation_url", "invitation_sent", "removed_at_provider"]) {
                    const field = data.errors[key];
                    const message = Array.isArray(field) ? field[0] : field;
                    if (typeof message === "string") errors.value[key] = message;
                }
            }
            const messages: Record<number, string> = {
                401: "Votre session a expiré. Rechargez la page pour vous reconnecter.",
                419: "Votre session a expiré. Rechargez la page pour vous reconnecter.",
                403: "Cette action n’est pas autorisée. Vérifiez votre compte ou contactez le soutien.",
                404: "Ce groupe ou ce membre n’est plus disponible. Rechargez la page.",
                409: "L’état du groupe ou du membre a changé. Rechargez la page avant de réessayer.",
                422: "Vérifiez les renseignements indiqués ci-dessous.",
                429: "Trop de tentatives. Patientez un instant avant de réessayer.",
            };
            await announceError(messages[response.status] ?? "L’enregistrement n’a pas été confirmé. Réessayez ou rechargez la page pour vérifier son état.");
            return;
        }
        if (!isRecord(data) || typeof data.message !== "string" || !data.message.trim()) {
            await announceError("La confirmation du serveur est indisponible. Rechargez la page pour vérifier l’enregistrement.");
            return;
        }
        clearSecrets();
        editor.value = null;
        success.value = confirmation;
        // Refresh metadata only; no secrets may be included in this Inertia prop.
        router.reload({ only: ["group"] });
    } catch {
        if (!disposed && requestGeneration === generation) await announceError("La connexion a été interrompue. Vérifiez l’état de l’accès avant de réessayer ; aucune confirmation n’a été reçue.");
    } finally {
        if (requestGeneration === generation) {
            if (!disposed) busy.value = false;
            controller = null;
        }
    }
}
function saveCredentials(): void {
    if (busy.value || props.group.closed || props.group.mode !== "credentials") return;
    if (!credentials.value.credential_email.trim() || !credentials.value.credential_password) {
        errors.value = {};
        if (!credentials.value.credential_email.trim()) errors.value.credential_email = "Renseignez le courriel du compte partagé.";
        if (!credentials.value.credential_password) errors.value.credential_password = "Renseignez le mot de passe du compte partagé.";
        void announceError("Les deux identifiants sont nécessaires pour enregistrer l’accès.");
        return;
    }
    void submit(`/api/groups/${props.group.id}/service-access`, "PUT", { ...credentials.value }, "Identifiants enregistrés. Ils sont disponibles pour les membres autorisés dont le paiement est validé.");
}
function saveInvitation(member: MemberAccess): void {
    if (!canDeliver(member) || editor.value?.member !== member.id || editor.value.action !== "deliver") return;
    if (providerEmail.value && !invitationSent.value) return;
    const payload: Record<string, string | boolean> = providerEmail.value ? { invitation_sent: true } : { invitation_url: invitationUrl.value.trim() };
    void submit(`/api/groups/${props.group.id}/members/${member.id}/service-access`, "PUT", payload,
        providerEmail.value ? "Envoi déclaré. Après acceptation par le membre, confirmez-le également dans Bitwarden. EquitAb ne vérifie pas l’accès chez le fournisseur."
            : "Invitation enregistrée. Elle sera visible par ce membre uniquement après validation du paiement. Sa validité chez le fournisseur n’est pas vérifiée par EquitAb.");
}
function confirmRemoval(member: MemberAccess): void {
    if (!member.revoke_required || !removedAtProvider.value || editor.value?.member !== member.id || editor.value.action !== "revoke") return;
    void submit(`/api/groups/${props.group.id}/members/${member.id}/service-access/revoke`, "POST", { removed_at_provider: true },
        "Retrait déclaré. EquitAb a enregistré votre confirmation ; aucune action automatique n’a été effectuée chez le fournisseur.");
}
function invalidatePage(): void {
    generation++;
    controller?.abort();
    controller = null;
    busy.value = false;
    success.value = "";
    resetEditor();
}
watch([() => props.group.id, () => props.group.mode, () => props.group.invitation_channel, () => props.group.closed], invalidatePage);
watch(() => props.group.members, () => {
    if (!editor.value) return;
    const member = props.group.members.find((item) => item.id === editor.value?.member);
    if (!member || (editor.value.action === "deliver" ? !canDeliver(member) : !member.revoke_required)) invalidatePage();
});
onMounted(() => window.addEventListener("pagehide", invalidatePage));
onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener("pagehide", invalidatePage);
    invalidatePage();
});
</script>

<template>
    <Head title="Gérer les accès — EquitAb" />
    <DashboardLayout>
        <div class="owner-flow service-access">
            <header>
                <Link href="/dashboard/subscriptions?tab=owned" class="owner-text-button">Retour à ma collection</Link>
                <p class="owner-eyebrow">Votre groupe, vos accès</p>
                <h1>Gérer les accès.</h1>
                <p class="owner-intro">{{ group.name }}</p>
            </header>
            <div v-if="error" ref="errorSummary" role="alert" tabindex="-1" class="owner-alert">{{ error }}</div>
            <p v-if="success" role="status" aria-live="polite" class="owner-ready">{{ success }}</p>
            <p v-if="group.closed" class="access-notice">Les nouveaux accès sont désactivés pour ce groupe. Les retraits à effectuer chez le fournisseur restent accessibles ci-dessous.</p>

            <form v-if="group.mode === 'credentials' && !group.closed" class="access-panel" :aria-busy="busy" @submit.prevent="saveCredentials">
                <h2 class="owner-step-title">Les identifiants du service</h2>
                <p class="owner-hint">{{ group.credentials_ready ? 'Des identifiants sont déjà enregistrés. Pour les remplacer, renseignez à nouveau les deux champs.' : 'Aucun accès complet n’est enregistré pour le moment.' }}</p>
                <OwnerCredentials v-model="credentials" :errors="errors" :disabled="busy" required />
                <button type="submit" class="owner-button" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer les identifiants' }}</button>
            </form>

            <template v-if="group.mode === 'invitation'">
                <section class="access-panel" aria-labelledby="invitation-heading">
                    <h2 id="invitation-heading" class="owner-step-title">Une invitation, un membre.</h2>
                    <template v-if="providerEmail">
                        <p class="owner-hint">Dans Bitwarden, invitez le membre à son adresse courriel ci-dessous. Déclarez ensuite l’envoi ici. Cette action est disponible après validation de son paiement.</p>
                        <p class="owner-hint">Le membre doit accepter l’invitation, puis vous devez le confirmer dans Bitwarden. La déclaration d’envoi ne prouve pas que son accès fonctionne.</p>
                    </template>
                    <p v-else class="owner-hint">Créez l’invitation chez le fournisseur pour le membre concerné, puis enregistrez son lien HTTPS ici. Vous pouvez préparer le lien pendant que son paiement est en attente : il ne sera affiché au membre qu’après validation du paiement.</p>
                    <p class="owner-hint">Ne partagez jamais votre mot de passe principal, votre coffre personnel ni vos codes de récupération. EquitAb ne crée ni ne retire les invitations chez le fournisseur.</p>
                    <nav class="provider-links" aria-label="Interfaces officielles des fournisseurs">
                        <template v-if="providerEmail">
                            <a href="https://vault.bitwarden.com/" target="_blank" rel="noopener noreferrer" class="owner-text-button">Bitwarden</a>
                            <a href="https://vault.bitwarden.eu/" target="_blank" rel="noopener noreferrer" class="owner-text-button">Bitwarden · compte hébergé en Europe</a>
                        </template>
                        <template v-else>
                            <a href="https://www.dropbox.com/" target="_blank" rel="noopener noreferrer" class="owner-text-button">Dropbox</a>
                            <a href="https://my.nordaccount.com/" target="_blank" rel="noopener noreferrer" class="owner-text-button">NordPass · Nord Account</a>
                        </template>
                    </nav>
                    <p v-if="!supportedInvitation" class="owner-error">Le mode d’invitation est indisponible. Contactez le soutien avant de fournir un accès.</p>
                </section>
                <section aria-labelledby="members-heading" :aria-busy="busy">
                    <h2 id="members-heading" class="owner-step-title">Les accès de vos membres</h2>
                    <p v-if="!group.members.length" class="owner-empty">Aucun membre pour le moment. Vous pourrez préparer ses accès lorsqu’il rejoindra le groupe.</p>
                    <ul v-else class="access-members">
                        <li v-for="member in group.members" :key="member.id" class="access-panel">
                            <div class="access-member-heading">
                                <div class="min-w-0">
                                    <h3 class="access-member-name">{{ member.name }}</h3>
                                    <p class="owner-hint">{{ member.email || 'Courriel indisponible' }}</p>
                                </div>
                                <span class="access-status">{{ memberStatus(member.status) }}</span>
                            </div>
                            <p v-if="member.revoked_at" class="owner-hint">Retrait déclaré le {{ dateLabel(member.revoked_at) }}. Déclaration du propriétaire, sans vérification chez le fournisseur.</p>
                            <p v-else-if="member.revoke_required" class="access-notice">Retrait à effectuer chez le fournisseur. Retirez ou annulez l’invitation de ce membre dans son interface, puis confirmez ci-dessous.</p>
                            <p v-else-if="member.provided_at" class="owner-hint">{{ providerEmail ? 'Envoi déclaré' : 'Lien enregistré' }} le {{ dateLabel(member.provided_at) }}. {{ providerEmail ? 'Après acceptation, pensez à confirmer le membre dans Bitwarden.' : 'Le lien n’est accessible qu’après validation du paiement.' }} Aucun accès fonctionnel n’est certifié par EquitAb.</p>
                            <p v-else class="owner-hint">{{ providerEmail && member.status === 'pending_payment' ? 'Attendez la validation du paiement avant d’envoyer l’invitation Bitwarden.' : 'Aucune invitation enregistrée.' }}</p>

                            <form v-if="editor?.member === member.id && editor.action === 'deliver' && canDeliver(member)" class="access-editor" @submit.prevent="saveInvitation(member)">
                                <fieldset :disabled="busy" class="owner-fields">
                                    <legend class="sr-only">Invitation pour {{ member.name }}</legend>
                                    <div v-if="providerEmail">
                                        <label class="owner-check" :for="`invitation-${member.id}`">
                                            <input :id="`invitation-${member.id}`" v-model="invitationSent" type="checkbox" required :aria-invalid="!!errors.invitation_sent" :aria-describedby="`invitation-hint-${member.id}${errors.invitation_sent ? ` invitation-error-${member.id}` : ''}`" />
                                            <span>J’ai envoyé l’invitation depuis Bitwarden à ce membre.</span>
                                        </label>
                                        <p :id="`invitation-hint-${member.id}`" class="owner-hint">Après son acceptation, confirmez le membre dans Bitwarden pour terminer l’activation de son accès.</p>
                                        <p v-if="errors.invitation_sent" :id="`invitation-error-${member.id}`" class="owner-error">{{ errors.invitation_sent }}</p>
                                    </div>
                                    <div v-else>
                                        <label class="owner-label" :for="`invitation-${member.id}`">Lien d’invitation officiel pour {{ member.name }}</label>
                                        <input :id="`invitation-${member.id}`" v-model="invitationUrl" class="owner-input" type="url" required maxlength="2048" autocomplete="off" autocapitalize="none" :spellcheck="false" :aria-invalid="!!errors.invitation_url" :aria-describedby="`invitation-hint-${member.id}${errors.invitation_url ? ` invitation-error-${member.id}` : ''}`" />
                                        <p :id="`invitation-hint-${member.id}`" class="owner-hint">Lien HTTPS du fournisseur uniquement. Le lien existant n’est pas préchargé ; un nouvel enregistrement le remplacera pour ce membre.</p>
                                        <p v-if="errors.invitation_url" :id="`invitation-error-${member.id}`" class="owner-error">{{ errors.invitation_url }}</p>
                                    </div>
                                    <div class="access-actions">
                                        <button type="submit" class="owner-button" :disabled="busy || (providerEmail && !invitationSent)">{{ busy ? 'Enregistrement…' : providerEmail ? 'Déclarer l’envoi' : 'Enregistrer l’invitation' }}</button>
                                        <button type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="resetEditor">Annuler et effacer la saisie</button>
                                    </div>
                                </fieldset>
                            </form>
                            <form v-else-if="editor?.member === member.id && editor.action === 'revoke' && member.revoke_required" class="access-editor" @submit.prevent="confirmRemoval(member)">
                                <label class="owner-check" :for="`removed-${member.id}`">
                                    <input :id="`removed-${member.id}`" v-model="removedAtProvider" type="checkbox" required :disabled="busy" :aria-invalid="!!errors.removed_at_provider" :aria-describedby="`removed-hint-${member.id}${errors.removed_at_provider ? ` removed-error-${member.id}` : ''}`" />
                                    <span>J’ai retiré ce membre ou annulé son invitation dans l’interface du fournisseur.</span>
                                </label>
                                <p :id="`removed-hint-${member.id}`" class="owner-hint">Ce bouton enregistre votre déclaration. Il ne retire pas automatiquement l’accès chez le fournisseur.</p>
                                <p v-if="errors.removed_at_provider" :id="`removed-error-${member.id}`" class="owner-error">{{ errors.removed_at_provider }}</p>
                                <div class="access-actions">
                                    <button type="submit" class="owner-button" :disabled="busy || !removedAtProvider">{{ busy ? 'Enregistrement…' : 'Confirmer le retrait effectué' }}</button>
                                    <button type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="resetEditor">Annuler</button>
                                </div>
                            </form>
                            <div v-else class="access-actions">
                                <button v-if="member.revoke_required" type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="editMember(member, 'revoke')" :aria-label="`Déclarer le retrait de ${member.name}`">Déclarer le retrait</button>
                                <button v-else-if="canDeliver(member)" type="button" class="owner-button owner-button-secondary" :disabled="busy" @click="editMember(member, 'deliver')" :aria-label="`Gérer l’invitation de ${member.name}`">{{ providerEmail ? 'Déclarer un envoi Bitwarden' : member.provided_at ? 'Remplacer l’invitation' : 'Préparer l’invitation' }}</button>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </DashboardLayout>
</template>

<style scoped>
.service-access { max-width: 900px; }
.service-access header > a { display: inline-flex; align-items: center; margin-bottom: 20px; }
.access-panel { margin: 20px 0; padding: clamp(18px, 3vw, 28px); border: 1px solid #dbe3de; border-radius: 18px; background: #fff; }
.access-notice { margin: 16px 0; padding: 16px; border: 1px solid #cbd6ce; border-radius: 12px; font-size: .88rem; line-height: 1.7; }
.access-members { list-style: none; padding: 0; }
.access-member-heading { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; }
.access-member-name { font-size: 1.1rem; font-weight: 650; }
.access-status { font-size: .75rem; border: 1px solid #dbe3de; border-radius: 20px; padding: 6px 12px; }
.access-actions, .provider-links { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
.provider-links a { display: inline-flex; align-items: center; }
.access-editor { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5ebe6; }
@media (max-width: 540px) { .access-actions { flex-direction: column; align-items: stretch; } }
</style>
