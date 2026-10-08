// node --test scripts/test-service-access-ui.mjs
// Behavioral tests of the real compiled page, panel, modal and composable. HTTP,
// clock, clipboard and native dialog host are isolated; this is not a browser/Stripe recipe.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deferred, flush, mount, response, textOf } from './helpers/service-access-test-harness.mjs';

const group = { id: 15, name: 'Groupe de test', subscriptionName: 'Service de test', subscriptionSlug: 'spotify',
    ownerName: 'Propriétaire de test', pricePerMember: 789, currency: 'CAD', renewalDate: '01 nov 2026', memberStatus: 'active' };
const secret = { email: 'synthetic@example.test', password: 'synthetic-password-not-real', notes: 'Instructions synthétiques' };
const legacy = { email: 'legacy@example.test', password: 'never-display-legacy', notes: 'never-display-legacy-note' };
const pending = (status = 'payment_pending', mode = 'credentials') => ({ status, mode, credentials: null, invitation: null });
const ready = () => ({ status: 'ready', mode: 'credentials', credentials: { ...secret }, invitation: null });
const invite = (url = 'https://provider.example.test/invite/synthetic?token=not-real') => ({
    status: 'ready', mode: 'invitation', credentials: null,
    invitation: { url, provided_at: '2026-10-07T17:00:00Z', channel: 'link', recipient_email: null },
});
const emailInvite = () => ({ status: 'ready', mode: 'invitation', credentials: null,
    invitation: { url: null, provided_at: '2026-10-07T17:00:00Z', channel: 'provider_email', recipient_email: 'recipient@example.test' } });
const click = async node => { node.props.onClick(); await flush(); };
const button = (ui, label) => ui.find(node => node.type === 'button' && (node.props['aria-label'] === label || textOf(node).includes(label)));
const retry = ui => button(ui, 'Vérifier à nouveau');
function assertNoSecrets(ui) {
    const visible = JSON.stringify(ui.nodes().map(node => [node.type, node.text, node.props]));
    for (const value of [...Object.values(secret), ...Object.values(legacy), invite().invitation.url, emailInvite().invitation.recipient_email]) {
        assert.ok(!visible.includes(value), 'No secret in rendered text/attributes before authorized availability');
    }
}
function page(t, options = {}, props = {}) {
    const ui = mount('Pages/PaymentSuccess.vue', { group: { ...group }, credentials: legacy, ...props }, options);
    t.after(() => {
        ui.unmount();
        assert.equal(ui.time.size, 0, 'Timers disposed');
        assert.deepEqual(ui.logs, [], 'No response or secret logged');
        assert.deepEqual(ui.forbidden, [], 'No storage, telemetry, SDK or automatic external navigation');
    });
    return ui;
}

test('URL success, memberStatus active and legacy/ready props cannot disclose secrets or bypass the API', async t => {
    const wait = deferred();
    const ui = page(t, { fetch: () => wait.promise }, { serviceAccess: ready() });
    await flush();
    assert.equal(ui.title(), 'Vérification de votre abonnement — Equitab');
    assertNoSecrets(ui);
    assert.equal(ui.calls.length, 1);
    const { url, init } = ui.calls[0];
    assert.equal(url, '/api/groups/15/service-access');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.redirect, 'error');
    assert.equal(init.headers.Accept, 'application/json');
    assert.ok(init.signal instanceof AbortSignal);
    assert.equal(init.body, undefined);
    wait.resolve(response(pending()));
    await flush();
    assert.equal(ui.title(), 'Confirmation du paiement en cours — Equitab');
    assertNoSecrets(ui);
});

for (const currency of ['CAD', 'EUR']) {
    test(`${currency}: pending becomes ready and displays access automatically, without acknowledgement`, async t => {
        const ui = page(t, { fetch: (_url, _init, count) => response(count === 1 ? pending() : ready()) }, { group: { ...group, currency } });
        await flush();
        assert.match(ui.text().replaceAll('\u00a0', ' '), new RegExp(`7,89\\s${currency}`));
        assertNoSecrets(ui);
        await ui.time.advance(2999);
        assert.equal(ui.calls.length, 1);
        await ui.time.advance(1);
        assert.equal(ui.calls.length, 2);
        assert.equal(ui.title(), 'Paiement confirmé — Equitab');
        assert.ok(ui.text().includes(secret.email));
        assert.ok(!ui.text().includes(secret.password));
        await click(button(ui, 'Afficher le mot de passe'));
        assert.ok(ui.text().includes(secret.password));
        assert.equal(button(ui, 'Masquer le mot de passe').props['aria-pressed'], true);
        await click(button(ui, 'Copier le mot de passe'));
        assert.deepEqual(ui.copied, [secret.password]);
        assert.ok(ui.nodes().some(node => node.props.role === 'status' && textOf(node) === 'Mot de passe copié.'));
        await ui.time.advance(240_000);
        assert.equal(ui.calls.length, 2, 'Ready ends polling');
        assert.equal(ui.time.size, 0);
        assert.ok(ui.calls.every(call => call.init.method === undefined && call.init.body === undefined), 'No member acknowledgement request');
    });
}

test('awaiting owner polls more slowly, then renders a manual HTTPS invitation with no-referrer isolation', async t => {
    const ui = page(t, { fetch: (_url, _init, count) => response(count === 1 ? pending('awaiting_owner', 'invitation') : invite()) });
    await flush();
    assert.ok(ui.text().includes('Accès en préparation'));
    assertNoSecrets(ui);
    await ui.time.advance(14999);
    assert.equal(ui.calls.length, 1);
    await ui.time.advance(1);
    const link = ui.find(node => node.type === 'a' && node.props.href === invite().invitation.url);
    assert.equal(link.props.target, '_blank');
    assert.equal(link.props.rel, 'noopener noreferrer');
    assert.equal(link.props.referrerpolicy, 'no-referrer');
    assert.ok(!ui.text().includes(invite().invitation.url), 'Token URL is not copied into display text');
    assert.ok(!ui.nodes().some(node => node.type === 'input'));
    assert.ok(!ui.nodes().some(node => node.type === 'button' && /confirmer|accepter/i.test(textOf(node))));
});

test('Bitwarden provider email displays recipient/instructions, no URL, credential or misleading acceptance claim', async t => {
    const ui = page(t, { fetch: () => response(emailInvite()) });
    await flush();
    assert.ok(ui.text().includes('Invitation envoyée par le fournisseur'));
    assert.ok(ui.text().includes(emailInvite().invitation.recipient_email));
    assert.ok(ui.text().includes('le propriétaire doit ensuite confirmer votre adhésion dans Bitwarden'));
    assert.ok(ui.text().includes('ne garantit pas encore que votre accès fonctionne'));
    assert.ok(!ui.nodes().some(node => node.type === 'a' && /^https?:/i.test(node.props.href ?? '')));
    assert.ok(!ui.nodes().some(node => node.type === 'button' && /confirmer|accepter/i.test(textOf(node))));
    assert.equal(ui.time.size, 0);
});

for (const state of ['payment_pending', 'awaiting_owner', 'unavailable']) {
    test(`${state} scrubs unexpected secrets and never renders links/credential controls`, async t => {
        const ui = page(t, { fetch: () => response({ ...pending(state), credentials: secret, invitation: invite().invitation }) });
        await flush();
        assertNoSecrets(ui);
        assert.ok(!ui.nodes().some(node => node.props['aria-label'] === 'Afficher le mot de passe'));
        if (state !== 'awaiting_owner') assert.ok(!ui.title().includes('Paiement confirmé'));
        if (state === 'unavailable') assert.equal(ui.time.size, 0);
    });
}

for (const state of ['payment_pending', 'awaiting_owner']) {
    test(`${state} stops at exactly two minutes; explicit retry starts one bounded new attempt`, async t => {
        const ui = page(t, { fetch: () => response(pending(state)) });
        await flush();
        await ui.time.advance(120_000);
        const count = ui.calls.length;
        assert.equal(count, state === 'payment_pending' ? 40 : 8);
        assert.ok(ui.calls.every(call => call.at < 120_000));
        assert.equal(ui.time.size, 0);
        assert.ok(ui.text().includes('vérification automatique est en pause'));
        await ui.time.advance(300_000);
        assert.equal(ui.calls.length, count);
        await click(retry(ui));
        assert.equal(ui.calls.length, count + 1);
        assert.ok(!ui.text().includes('vérification automatique est en pause'));
        await ui.time.advance(120_000);
        assert.equal(ui.time.size, 0);
        assert.equal(ui.calls.length, count * 2);
    });
}

test('state transitions do not reset the shared two-minute deadline', async t => {
    const ui = page(t, { fetch: (_url, _init, count) => response(pending(count < 30 ? 'payment_pending' : 'awaiting_owner')) });
    await flush();
    await ui.time.advance(120_000);
    assert.equal(ui.time.size, 0);
    assert.equal(ui.calls.length, 32);
    assert.ok(ui.calls.every(call => call.at < 120_000));
    assert.ok(ui.text().includes('vérification automatique est en pause'));
});

test('background timer throttling cannot accept a response after the absolute deadline', async t => {
    const wait = deferred();
    const ui = page(t, { fetch: () => wait.promise });
    await flush();
    ui.time.elapseWithoutTimers(121_000);
    wait.resolve(response(ready()));
    await flush();
    assertNoSecrets(ui);
    assert.ok(!ui.title().includes('Paiement confirmé'));
    assert.ok(ui.text().includes('vérification automatique est en pause'));
    assert.equal(ui.calls[0].init.signal.aborted, true);
    assert.equal(ui.time.size, 0);
});

test('unmount during a scheduled poll prevents every subsequent request', async () => {
    const ui = mount('Pages/PaymentSuccess.vue', { group, credentials: null }, { fetch: () => response(pending()) });
    await flush();
    assert.equal(ui.calls.length, 1);
    ui.unmount();
    await ui.time.advance(240_000);
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.time.size, 0);
});

test('the sanitized ready Inertia snapshot still requires fresh endpoint authorization', async t => {
    const wait = deferred();
    const ui = page(t, { fetch: () => wait.promise }, { serviceAccess: pending('ready', 'invitation'), credentials: null });
    await flush();
    assert.equal(ui.title(), 'Vérification de votre abonnement — Equitab');
    assertNoSecrets(ui);
    wait.resolve(response(emailInvite(), 403));
    await flush();
    assert.equal(ui.title(), 'Accès indisponible — Equitab');
    assertNoSecrets(ui);
});

test('a fresh group replaces already-disclosed credentials immediately while its API request is pending', async t => {
    const wait = deferred();
    const ui = page(t, { fetch: (_url, _init, count) => count === 1 ? response(ready()) : wait.promise });
    await flush();
    await click(button(ui, 'Afficher le mot de passe'));
    assert.ok(ui.text().includes(secret.password));
    await ui.update({ group: { ...group, id: 16 } });
    assertNoSecrets(ui);
    assert.equal(ui.title(), 'Vérification de votre abonnement — Equitab');
    wait.resolve(response(pending('unavailable')));
    await flush();
    assertNoSecrets(ui);
});

test('slow in-flight response cannot overlap, survive the deadline or disclose after timeout', async t => {
    const last = deferred();
    const ui = page(t, { fetch: (_url, _init, count) => count === 40 ? last.promise : response(pending()) });
    await flush();
    await ui.time.advance(117_000);
    assert.equal(ui.calls.length, 40);
    const disabled = button(ui, 'Vérification en cours…');
    assert.equal(disabled.props.disabled, true);
    await click(disabled); // Even a programmatic duplicate is guarded.
    assert.equal(ui.calls.length, 40);
    await ui.time.advance(3000);
    assert.equal(ui.calls.at(-1).init.signal.aborted, true);
    last.resolve(response(ready()));
    await flush();
    assertNoSecrets(ui);
    assert.ok(!ui.title().includes('Paiement confirmé'));
    assert.equal(ui.time.size, 0);
});

test('a hung request is aborted after ten seconds and a late body cannot overwrite retry', async t => {
    const old = deferred();
    const ui = page(t, { fetch: (_url, _init, count) => count === 1 ? old.promise : response(emailInvite()) });
    await flush();
    await ui.time.advance(10_000);
    assert.equal(ui.calls[0].init.signal.aborted, true);
    assert.ok(ui.text().includes('trop de temps'));
    assert.equal(ui.time.size, 0);
    await click(retry(ui));
    old.resolve(response(ready()));
    await flush();
    assert.ok(ui.text().includes(emailInvite().invitation.recipient_email));
    assert.ok(!ui.text().includes(secret.email));
});

for (const code of [401, 403, 404, 409, 422, 429, 500, 503]) {
    test(`HTTP ${code} clears disclosed secrets, ends polling and offers retry without a success title`, async t => {
        const ui = page(t, { fetch: (_url, _init, count) => response(ready(), count === 1 ? 200 : code) });
        await flush();
        await click(button(ui, 'Afficher le mot de passe'));
        assert.ok(ui.text().includes(secret.password));
        await click(button(ui, 'Actualiser les accès'));
        assertNoSecrets(ui);
        assert.ok(!ui.title().includes('Paiement confirmé'));
        assert.ok(ui.nodes().some(node => node.props.role === 'alert'));
        assert.equal(retry(ui).props.disabled, false);
        assert.equal(ui.time.size, 0);
    });
}

test('network error does not leak its body and an explicit retry can recover', async t => {
    const ui = page(t, { fetch: (_url, _init, count) => {
        if (count === 1) throw new Error(invite().invitation.url);
        return response(ready());
    } });
    await flush();
    assertNoSecrets(ui);
    assert.ok(ui.text().includes('Connexion interrompue'));
    assert.equal(ui.time.size, 0);
    await click(retry(ui));
    assert.ok(ui.text().includes(secret.email));
});

for (const [name, output] of [
    ['malformed JSON', response(null, 200, { json: async () => { throw new SyntaxError(secret.password); } })],
    ['HTML login page', response(ready(), 200, { headers: new Headers({ 'content-type': 'text/html' }) })],
    ['redirected success', response(ready(), 200, { redirected: true })],
    ['unknown status', response({ ...ready(), status: 'succeeded' })],
    ['null body', response(null)],
    ['partial credential', response({ ...ready(), credentials: { ...secret, password: null } })],
    ['non-string credential', response({ ...ready(), credentials: { ...secret, email: 42 } })],
    ['provider email with a link', response({ ...emailInvite(), invitation: { ...emailInvite().invitation, url: invite().invitation.url } })],
    ['provider email without recipient', response({ ...emailInvite(), invitation: { ...emailInvite().invitation, recipient_email: null } })],
]) {
    test(`${name} fails closed instead of showing success or secrets`, async t => {
        const ui = page(t, { fetch: () => output });
        await flush();
        assertNoSecrets(ui);
        assert.ok(!ui.title().includes('Paiement confirmé'));
        assert.equal(retry(ui).props.disabled, false);
        assert.equal(ui.time.size, 0);
    });
}

for (const url of ['http://provider.example.test/invite', 'javascript:alert(1)', 'data:text/html,unsafe', '//provider.example.test/invite', '/invite', 'https://user:pass@provider.example.test/invite', 'https://provider.example.test/\ninvite', 'https:\\provider.example.test/invite']) {
    test(`unsafe invitation URL is rejected (${url.split(':')[0]})`, async t => {
        const ui = page(t, { fetch: () => response(invite(url)) });
        await flush();
        assert.ok(!ui.title().includes('Paiement confirmé'));
        assert.ok(!ui.nodes().some(node => node.type === 'a' && node.props.href === url));
        assert.equal(ui.time.size, 0);
    });
}

test('switching groups aborts stale fetch/JSON parsing and never retains the previous group secrets', async t => {
    const body = deferred();
    const ui = page(t, { fetch: (_url, _init, count) => count === 1
        ? response(null, 200, { json: () => body.promise }) : response(emailInvite()) });
    await flush();
    await ui.update({ group: { ...group, id: 16 } });
    assert.equal(ui.calls[0].init.signal.aborted, true);
    assert.equal(ui.calls[1].url, '/api/groups/16/service-access');
    assert.ok(ui.text().includes(emailInvite().invitation.recipient_email));
    body.resolve(ready());
    await flush();
    assert.ok(!ui.text().includes(secret.email));
    assert.ok(ui.text().includes(emailInvite().invitation.recipient_email));
    await ui.update({ group: { ...group, id: -1 } });
    assert.equal(ui.calls.length, 2, 'Invalid identifiers never reach transport');
    assertNoSecrets(ui);
});

test('unmount aborts the request; late resolve creates neither UI nor timers', async () => {
    const wait = deferred();
    const ui = mount('Pages/PaymentSuccess.vue', { group, credentials: legacy }, { fetch: () => wait.promise });
    await flush();
    ui.unmount();
    assert.equal(ui.calls[0].init.signal.aborted, true);
    wait.resolve(response(ready()));
    await flush();
    await ui.time.advance(180_000);
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.time.size, 0);
    assert.equal(ui.container.children.length, 0);
});

test('credential disclosure resets on refresh; clipboard failure stays local and recoverable', async t => {
    const ui = page(t, { fetch: () => response(ready()), clipboard: () => { throw new Error(secret.password); } });
    await flush();
    await click(button(ui, 'Afficher le mot de passe'));
    await click(button(ui, 'Copier le mot de passe'));
    assert.ok(ui.text().includes('La copie n’a pas fonctionné'));
    await click(button(ui, 'Actualiser les accès'));
    assert.ok(!ui.text().includes(secret.password));
    assert.equal(button(ui, 'Afficher le mot de passe').props['aria-pressed'], false);
    assert.ok(!ui.text().includes('La copie n’a pas fonctionné'));
});

test('late clipboard completion cannot repopulate feedback after access is refused', async t => {
    const copied = deferred();
    const ui = page(t, { clipboard: () => copied.promise, fetch: (_url, _init, count) => response(ready(), count === 1 ? 200 : 403) });
    await flush();
    button(ui, 'Copier l’identifiant').props.onClick();
    await click(button(ui, 'Actualiser les accès'));
    copied.resolve();
    await flush();
    assertNoSecrets(ui);
    assert.ok(!ui.text().includes('Copié !'));
    assert.equal(ui.time.size, 0);
});

test('owner notes are rendered as plain text, never executable markup', async t => {
    const notes = '<img src=x onerror=alert(1)><script>synthetic()</script>';
    const ui = page(t, { fetch: () => response({ ...ready(), credentials: { ...secret, notes } }) });
    await flush();
    assert.ok(ui.text().includes(notes));
    assert.ok(!ui.nodes().some(node => node.type === 'img' || node.type === 'script'));
});

for (const close of ['escape', 'button']) {
    test(`modal uses the same async panel and preserves ${close} closing/focus restoration`, async () => {
        let closed = 0;
        const ui = mount('Components/CredentialsModal.vue', { groupId: 15, subscriptionName: 'Service de test' }, {
            onClose: () => closed++, fetch: (_url, _init, count) => response(count === 1 ? pending() : emailInvite()),
        });
        try {
            await flush();
            const dialog = ui.find(node => node.type === 'dialog');
            assert.equal(dialog.open, true);
            assert.equal(dialog.props['aria-label'], 'Accès — Service de test');
            assert.equal(ui.doc.body.style.overflow, 'hidden');
            assert.equal(ui.doc.activeElement.props['aria-label'], 'Fermer la fenêtre');
            assertNoSecrets(ui);
            await ui.time.advance(3000);
            assert.ok(ui.text().includes(emailInvite().invitation.recipient_email));
            if (close === 'escape') {
                let prevented = false;
                dialog.props.onCancel({ preventDefault() { prevented = true; } });
                await flush();
                assert.equal(prevented, true);
            } else await click(button(ui, 'Fermer la fenêtre'));
            assert.equal(closed, 1);
            assert.equal(dialog.open, false);
            assert.equal(ui.doc.activeElement, ui.trigger);
            assert.equal(ui.doc.body.style.overflow, 'auto');
            assert.equal(ui.time.size, 0);
            assertNoSecrets(ui);
        } finally { ui.unmount(); }
    });
}
