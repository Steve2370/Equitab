import type {
    DraftErrors, DraftInput, GroupDraft, GroupDraftTransport, OwnerActivation,
    OwnerReadiness, OwnerSubscription, PublishResult, ServiceCredentials,
} from "../types/group-draft.ts";

const draftKeys = [
    "subscription_id", "name", "description", "tier", "max_members", "total_price",
    "split_type", "visibility", "renewal_date", "auto_renew",
] as const;

export function safeDraftData(data: DraftInput): DraftInput {
    // An explicit allowlist also strips unexpected fields in server responses/history.
    return Object.fromEntries(draftKeys
        .filter((key) => data[key] !== undefined)
        .map((key) => [key, key === "visibility" && String(data[key]) === "invite_only" ? "private" : data[key]])) as DraftInput;
}

export function emptyDraftData(): DraftInput {
    return {
        subscription_id: null, name: "", description: "", tier: "standard",
        max_members: null, total_price: null, split_type: "equal",
        visibility: "public", renewal_date: "", auto_renew: true,
    };
}

export function safeDraftRecord(draft: GroupDraft): GroupDraft {
    return {
        id: draft.id, version: draft.version, status: draft.status,
        data: safeDraftData(draft.data), updated_at: draft.updated_at,
        published_group_id: draft.published_group_id,
        preview: draft.preview ? {
            full_group_share: draft.preview.full_group_share,
            total_price: draft.preview.total_price,
            currency: draft.preview.currency,
            max_members: draft.preview.max_members,
        } : null,
    };
}

export function draftFingerprint(data: DraftInput): string {
    const normalized = safeDraftData({ ...emptyDraftData(), ...data });
    // Laravel converts empty text to null; text/date inputs can emit "" again on render.
    // Only blank text is equivalent. Numbers, booleans and enum values keep their types.
    for (const key of ["name", "description", "renewal_date"] as const) {
        if (normalized[key] === null) normalized[key] = "";
    }
    return JSON.stringify(normalized);
}

export function memberLimit(subscription?: OwnerSubscription): number {
    return subscription && Number.isInteger(subscription.max_members)
        ? Math.max(0, Math.min(10, subscription.max_members)) : 0;
}

export function serviceDefaults(subscription: OwnerSubscription): DraftInput {
    return {
        subscription_id: subscription.id, name: `Groupe ${subscription.name}`,
        tier: subscription.tier, max_members: memberLimit(subscription),
        total_price: subscription.monthly_price,
    };
}

export function centsFromInput(value: string): number | null {
    const normalized = value.trim().replace(",", ".");
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) return null;
    const [whole, fraction = ""] = normalized.split(".");
    const cents = Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
    return Number.isSafeInteger(cents) ? cents : null;
}

export function formatGroupMoney(cents: number, currency = "CAD"): string {
    return new Intl.NumberFormat("fr-CA", { style: "currency", currency }).format(cents / 100);
}

export function activationUrl(value: string, origin: string): string {
    if (typeof value !== "string" || !value.trim()) throw new DraftRequestError(503, "Le lien de vérification est indisponible. Réessayez dans un moment.");
    const url = new URL(value, origin);
    // Stripe can return an external HTTPS link or a local destination if already verified.
    if (url.protocol !== "https:" && !(url.protocol === "http:" && url.origin === origin)) {
        throw new DraftRequestError(503, "Le lien de vérification est indisponible. Réessayez dans un moment.");
    }
    return url.href;
}

export function fullGroupShare(data: DraftInput): number | null {
    return typeof data.total_price === "number" && Number.isSafeInteger(data.total_price)
        && data.total_price >= 100 && typeof data.max_members === "number"
        && Number.isInteger(data.max_members) && data.max_members >= 2 && data.max_members <= 10
        ? Math.round(data.total_price / data.max_members) : null;
}

export function preparationErrors(data: DraftInput, subscription?: OwnerSubscription): DraftErrors {
    const errors: DraftErrors = {};
    if (!subscription || data.subscription_id !== subscription.id) errors.subscription_id = "Choisissez un service du catalogue.";
    if (!data.name?.trim()) errors.name = "Donnez un nom à votre groupe.";
    if (data.name && data.name.length > 255) errors.name = "Le nom doit contenir au plus 255 caractères.";
    if (data.description && data.description.length > 1000) errors.description = "La description doit contenir au plus 1 000 caractères.";
    if (typeof data.total_price !== "number" || !Number.isSafeInteger(data.total_price) || data.total_price < 100) {
        errors.total_price = "Indiquez un prix mensuel d’au moins 1,00 $, avec deux décimales au maximum.";
    }
    if (typeof data.max_members !== "number" || !Number.isInteger(data.max_members)
        || data.max_members < 2 || data.max_members > memberLimit(subscription)) {
        errors.max_members = `Choisissez de 2 à ${memberLimit(subscription)} personnes, vous compris.`;
    }
    if (!data.renewal_date || !/^\d{4}-\d{2}-\d{2}$/.test(data.renewal_date)) {
        errors.renewal_date = "Indiquez la prochaine date de renouvellement de votre abonnement.";
    }
    return errors;
}

export class DraftRequestError extends Error {
    status: number;
    fields: DraftErrors;

    constructor(status: number, message: string, fields: DraftErrors = {}) {
        super(message);
        this.name = "DraftRequestError";
        this.status = status;
        this.fields = fields;
    }
}

export interface DraftState {
    data: DraftInput;
    id: string | null;
    saved: GroupDraft | null;
    operation: "idle" | "saving" | "publishing" | "reopening" | OwnerActivation;
    errors: DraftErrors;
    message: string;
    conflict: boolean;
    leaving: boolean;
}

export function createDraftState(draft: GroupDraft | null): DraftState {
    return {
        data: { ...emptyDraftData(), ...safeDraftData(draft?.data ?? {}) },
        id: draft?.id ?? null, saved: draft ? safeDraftRecord(draft) : null,
        operation: "idle", errors: {}, message: "", conflict: false, leaving: false,
    };
}

export function isDraftSaved(state: DraftState): boolean {
    return !!state.saved && !state.conflict
        && draftFingerprint(state.data) === draftFingerprint(state.saved.data);
}

export function draftIsLocked(state: DraftState): boolean {
    return state.leaving || state.conflict || (!!state.saved && state.saved.status !== "draft");
}

/** The same controller runs against Vue's reactive state and plain objects in tests. */
export function createDraftController(
    state: DraftState,
    transport: GroupDraftTransport,
    newId: () => string,
    confirmed: (draft: GroupDraft) => void | Promise<void> = () => {},
) {
    let generation = 0;

    function restore(draft: GroupDraft | null): void {
        generation += 1;
        Object.assign(state, createDraftState(draft));
    }

    function report(error: unknown): void {
        if (error instanceof DraftRequestError) {
            state.errors = error.fields;
            state.message = error.message;
            if (error.status === 409) state.conflict = true;
        } else {
            state.message = "La connexion a été interrompue. Votre saisie est conservée ici. Réessayez pour confirmer l’enregistrement.";
        }
    }

    function begin(operation: DraftState["operation"], retryPublication = false): number | null {
        if (state.operation !== "idle" || state.leaving || state.conflict
            || (draftIsLocked(state) && !(retryPublication && state.saved?.status === "publishing"))) return null;
        state.operation = operation;
        state.errors = {};
        state.message = "";
        return generation;
    }

    async function persist(token: number): Promise<GroupDraft | null> {
        if (isDraftSaved(state)) return state.saved;
        state.id ??= newId();
        const snapshot = safeDraftData(state.data);
        const draft = safeDraftRecord(state.saved
            ? await transport.update(state.id, state.saved.version, snapshot)
            : await transport.create(state.id, snapshot));
        if (token !== generation) return null;
        state.saved = draft;
        // Never replace a user's input with a response to an earlier edit.
        // Accept normalization (e.g. trimmed text/null blanks) only for the submitted edit.
        if (draftFingerprint(state.data) === draftFingerprint(snapshot)) {
            state.data = { ...emptyDraftData(), ...safeDraftData(draft.data) };
        }
        await confirmed(draft);
        if (token !== generation) return null;
        if (draft.status !== "draft") {
            state.conflict = true;
            state.message = "Ce brouillon est en cours de publication ou déjà publié. Rechargez son état avant de continuer.";
            return null;
        }
        if (!isDraftSaved(state)) {
            state.message = "Une version est enregistrée. Votre saisie actuelle contient encore des modifications à enregistrer.";
        }
        return draft;
    }

    async function save(): Promise<GroupDraft | null> {
        const token = begin("saving");
        if (token === null) return null;
        try { return await persist(token); }
        catch (error: unknown) { if (token === generation) report(error); return null; }
        finally { if (token === generation) state.operation = "idle"; }
    }

    async function activate(kind: OwnerActivation): Promise<string | null> {
        const token = begin(kind);
        if (token === null) return null;
        try {
            const draft = await persist(token);
            if (!draft || token !== generation || !isDraftSaved(state)) return null;
            const url = await transport.activate(kind, draft.id);
            if (token !== generation) return null;
            // Editing is locked during this sequence, including between the two requests.
            state.leaving = true;
            return url;
        } catch (error: unknown) { if (token === generation) report(error); return null; }
        finally { if (token === generation) state.operation = "idle"; }
    }

    async function publish(
        readiness: OwnerReadiness, certify: boolean, credentials: ServiceCredentials,
    ): Promise<PublishResult | null> {
        if (!certify || !readiness.ready || !readiness.identityVerified || !readiness.connectActive) return null;
        const retryPublication = state.saved?.status === "publishing";
        const token = begin("publishing", retryPublication);
        if (token === null) return null;
        try {
            const draft = retryPublication ? state.saved : await persist(token);
            if (!draft || token !== generation || !isDraftSaved(state)) return null;
            const result = await transport.publish(draft.id, draft.version, {
                credential_email: credentials.credential_email,
                credential_password: credentials.credential_password,
                credential_notes: credentials.credential_notes,
            });
            if (token !== generation) return null;
            state.saved = { ...draft, status: "published", published_group_id: result.group_id };
            state.leaving = true;
            await confirmed(state.saved);
            return result;
        } catch (error: unknown) { if (token === generation) report(error); return null; }
        finally { if (token === generation) state.operation = "idle"; }
    }

    async function reopen(): Promise<GroupDraft | null> {
        if (state.operation !== "idle" || state.leaving || state.saved?.status !== "publishing" || state.conflict) return null;
        const token = generation;
        state.operation = "reopening";
        state.errors = {};
        state.message = "";
        try {
            const draft = safeDraftRecord(await transport.reopen(state.saved.id, state.saved.version));
            if (token !== generation) return null;
            state.saved = draft;
            await confirmed(draft);
            if (token !== generation) return null;
            return draft;
        } catch (error: unknown) { if (token === generation) report(error); return null; }
        finally { if (token === generation) state.operation = "idle"; }
    }

    return { save, activate, publish, reopen, restore, dispose: () => { generation += 1; } };
}
