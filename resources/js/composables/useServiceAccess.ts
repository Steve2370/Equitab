import { onBeforeUnmount, onMounted, readonly, ref, shallowRef, watch } from 'vue';
import type { ServiceAccess, ServiceAccessSnapshot, ServiceAccessStatus, ServiceCredentials } from '../types/service-access';

const POLLING_WINDOW_MS = 120_000;
const REQUEST_TIMEOUT_MS = 10_000;

/** Defence in depth: the server remains responsible for validating the provider URL. */
export function safeInvitationUrl(value: unknown): string | null {
    if (typeof value !== 'string' || !/^https:\/\//i.test(value)
        || /[\s\\\u0000-\u001f\u007f]/.test(value)) return null;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && url.hostname && !url.username && !url.password ? url.href : null;
    } catch {
        return null;
    }
}

function record(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readAccess(value: unknown): ServiceAccess | null {
    if (!record(value) || (value.mode !== 'credentials' && value.mode !== 'invitation')) return null;
    const { status, mode } = value;
    if (status === 'payment_pending' || status === 'awaiting_owner' || status === 'unavailable') {
        // Never retain unexpected secrets attached to a non-authorized response.
        return { status, mode, credentials: null, invitation: null };
    }
    if (status !== 'ready') return null;
    if (mode === 'credentials' && record(value.credentials)) {
        const { email, password, notes } = value.credentials;
        if (![email, password, notes].every(field => field === null || typeof field === 'string')) return null;
        const credentials = { email, password, notes } as ServiceCredentials;
        if (!credentials.email?.trim() || !credentials.password?.trim()) return null;
        return { status, mode, credentials, invitation: null };
    }
    if (mode === 'invitation' && record(value.invitation)) {
        const { channel, recipient_email: recipientEmail, provided_at: providedAt } = value.invitation;
        if (typeof providedAt !== 'string' || !providedAt.trim()) return null;
        if (channel === 'provider_email' && value.invitation.url === null
            && typeof recipientEmail === 'string' && recipientEmail.trim()) {
            return { status, mode, credentials: null,
                invitation: { channel, url: null, provided_at: providedAt, recipient_email: recipientEmail } };
        }
        const url = safeInvitationUrl(value.invitation.url);
        if (channel === 'link' && url && (recipientEmail === null || typeof recipientEmail === 'string')) {
            return { status, mode, credentials: null,
                invitation: { channel, url, provided_at: providedAt, recipient_email: recipientEmail } };
        }
    }
    return null;
}

function initialStatus(value: ServiceAccessSnapshot | null | undefined): ServiceAccessStatus | null {
    // Inertia props/history (including legacy credentials) never supply secrets.
    // Even initial `ready` requires a fresh, authorized no-store API response.
    return value?.status === 'payment_pending' || value?.status === 'awaiting_owner'
        || value?.status === 'unavailable' ? value.status : null;
}

export function useServiceAccess(groupId: () => number, initial: () => ServiceAccessSnapshot | null | undefined = () => null) {
    const access = shallowRef<ServiceAccess | null>(null);
    const status = ref<ServiceAccessStatus | null>(initialStatus(initial()));
    const loading = ref(false);
    const error = ref<string | null>(null);
    const timedOut = ref(false);
    let generation = 0;
    let disposed = false;
    let deadlineAt = 0;
    let controller: AbortController | null = null;
    let pollTimer: ReturnType<typeof setTimeout> | undefined;
    let deadlineTimer: ReturnType<typeof setTimeout> | undefined;
    let requestTimer: ReturnType<typeof setTimeout> | undefined;
    let stopWatching: (() => void) | undefined;

    function stop() {
        generation++;
        clearTimeout(pollTimer);
        clearTimeout(deadlineTimer);
        clearTimeout(requestTimer);
        controller?.abort();
        controller = null;
        loading.value = false;
    }

    function fail(message: string) {
        stop();
        access.value = null;
        status.value = 'unavailable';
        error.value = message;
    }

    function endWindow() {
        stop();
        access.value = null;
        timedOut.value = true;
    }

    function withinWindow(run: number): boolean {
        if (disposed || run !== generation) return false;
        // Background tabs may defer timers: late responses must still expire.
        if (performance.now() >= deadlineAt) {
            endWindow();
            return false;
        }
        return true;
    }

    async function request(run: number, id: number): Promise<void> {
        if (!withinWindow(run) || loading.value) return;
        loading.value = true;
        const current = new AbortController();
        controller = current;
        requestTimer = setTimeout(() => {
            if (run !== generation) return;
            fail('La vérification prend trop de temps. Vous pouvez réessayer sans effectuer un nouveau paiement.');
            timedOut.value = true;
        }, REQUEST_TIMEOUT_MS);

        try {
            const response = await fetch(`/api/groups/${id}/service-access`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: current.signal,
            });
            if (!withinWindow(run)) return;
            if (!response.ok || response.redirected) {
                fail([401, 403, 404].includes(response.status)
                    ? 'Accès refusé ou indisponible. Vérifiez votre connexion et votre abonnement depuis votre espace.'
                    : 'Impossible de vérifier les accès pour le moment. Veuillez réessayer.');
                return;
            }
            if (!response.headers.get('content-type')?.toLowerCase().includes('application/json')) {
                fail('La réponse reçue ne permet pas de vérifier les accès. Veuillez réessayer.');
                return;
            }
            const result = readAccess(await response.json());
            if (!withinWindow(run)) return;
            if (!result) {
                fail('La réponse reçue ne permet pas de vérifier les accès. Veuillez réessayer.');
                return;
            }
            clearTimeout(requestTimer);
            controller = null;
            loading.value = false;
            access.value = result;
            status.value = result.status;
            if (result.status === 'payment_pending' || result.status === 'awaiting_owner') {
                pollTimer = setTimeout(() => void request(run, id), result.status === 'payment_pending' ? 3_000 : 15_000);
            } else {
                stop();
            }
        } catch {
            if (!disposed && run === generation) {
                // Never render/log server bodies, URLs, credentials or exception details.
                fail('Connexion interrompue. Réessayez pour vérifier vos accès, sans refaire le paiement.');
            }
        }
    }

    function retry() {
        if (disposed || loading.value) return;
        stop();
        access.value = null;
        if (status.value === 'ready') status.value = null;
        error.value = null;
        timedOut.value = false;
        const id = groupId();
        if (!Number.isSafeInteger(id) || id <= 0) {
            fail('Ce groupe est indisponible. Retrouvez vos abonnements dans votre espace.');
            return;
        }
        const run = generation;
        deadlineAt = performance.now() + POLLING_WINDOW_MS;
        deadlineTimer = setTimeout(() => {
            if (run !== generation) return;
            endWindow();
        }, POLLING_WINDOW_MS);
        void request(run, id);
    }

    onMounted(() => {
        stopWatching = watch([groupId, initial], () => {
            stop();
            access.value = null;
            status.value = initialStatus(initial());
            retry();
        }, { immediate: true, flush: 'sync' });
    });
    onBeforeUnmount(() => {
        disposed = true;
        stopWatching?.();
        stop();
        access.value = null;
        status.value = null;
    });

    return { access: readonly(access), status: readonly(status), loading: readonly(loading),
        error: readonly(error), timedOut: readonly(timedOut), retry };
}
