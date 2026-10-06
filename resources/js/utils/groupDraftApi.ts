import axios from "axios";
import type { DraftErrors, GroupDraft, GroupDraftTransport, PublishResult } from "../types/group-draft.ts";
import { activationUrl, DraftRequestError, safeDraftData, safeDraftRecord } from "./groupDraft.ts";

function errorFields(value: unknown): DraftErrors {
    if (!value || typeof value !== "object") return {};
    const fields: DraftErrors = {};
    for (const [key, messages] of Object.entries(value)) {
        const message: unknown = Array.isArray(messages) ? messages[0] : messages;
        if (typeof message === "string") fields[key.replace(/^data\./, "")] = message;
    }
    return fields;
}

async function request<T>(run: () => Promise<{ data: T }>): Promise<T> {
    try { return (await run()).data; }
    catch (error: unknown) {
        if (!axios.isAxiosError<{ errors?: unknown }>(error)) throw error;
        const status = error.response?.status ?? 0;
        const messages: Record<number, string> = {
            401: "Votre session a expiré. Reconnectez-vous avant de réessayer; votre saisie reste ici.",
            403: "Cette action n’est pas autorisée. Vérifiez votre profil et vos accès avant de réessayer.",
            404: "Ce brouillon n’est plus accessible. Votre saisie est conservée sur cette page.",
            409: "Ce brouillon a changé dans un autre onglet ou sa publication a commencé. Votre saisie est conservée ici. Rechargez pour reprendre la version du serveur.",
            419: "Votre session a expiré. Rechargez après avoir conservé vos modifications non enregistrées.",
            422: "Certains renseignements doivent être corrigés. Consultez les champs indiqués.",
            429: "Trop de demandes ont été envoyées. Patientez un moment avant de réessayer.",
            503: "Le service est temporairement indisponible. Votre saisie est conservée; vous pouvez réessayer.",
        };
        throw new DraftRequestError(status,
            messages[status] ?? "La demande n’a pas pu être confirmée. Votre saisie est conservée ici; vous pouvez réessayer.",
            errorFields(error.response?.data?.errors));
    }
}

const json = { headers: { Accept: "application/json" } };

export const groupDraftApi: GroupDraftTransport = {
    async create(id, data) {
        const response = await request(() => axios.post<{ draft: GroupDraft }>("/group-drafts", { id, data: safeDraftData(data) }, json));
        return safeDraftRecord(response.draft);
    },
    async update(id, version, data) {
        const response = await request(() => axios.put<{ draft: GroupDraft }>(`/group-drafts/${encodeURIComponent(id)}`, { version, data: safeDraftData(data) }, json));
        return safeDraftRecord(response.draft);
    },
    async publish(id, version, credentials) {
        return request(() => axios.post<PublishResult>(`/group-drafts/${encodeURIComponent(id)}/publish`, {
            version, certify: true,
            ...(credentials.credential_email ? { credential_email: credentials.credential_email } : {}),
            ...(credentials.credential_password ? { credential_password: credentials.credential_password } : {}),
            ...(credentials.credential_notes ? { credential_notes: credentials.credential_notes } : {}),
        }, json));
    },
    async activate(kind, draftId) {
        const response = await request(() => axios.post<{ url: string }>(
            kind === "identity" ? "/api/stripe/identity" : "/api/stripe/onboarding",
            { draft_id: draftId }, json,
        ));
        return activationUrl(response.url, window.location.origin);
    },
    async reopen(id, version) {
        const response = await request(() => axios.post<{ draft: GroupDraft }>(`/group-drafts/${encodeURIComponent(id)}/reopen`, { version }, json));
        return safeDraftRecord(response.draft);
    },
};
