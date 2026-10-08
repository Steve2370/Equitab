// Real Vue scripts/templates; HTTP and Inertia are isolated at their boundaries.
// No Laravel bootstrap, .env, application database, browser storage or Stripe calls.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createRenderer, defineComponent, h, nextTick, reactive, ref } from 'vue';
import ts from 'typescript';
import * as ownerCountry from '../resources/js/utils/ownerCountry.ts';
import * as draftUtils from '../resources/js/utils/groupDraft.ts';

class OfflineDocument { activeElement = null; }
globalThis.Document = OfflineDocument;
globalThis.ShadowRoot = class {};
const offlineDocument = new OfflineDocument();
const root = fileURLToPath(new URL('../resources/js/', import.meta.url));
const require = createRequire(import.meta.url);
const none = defineComponent({ setup: () => () => null });
const frame = defineComponent({ setup: (_, { slots }) => () => h('main', slots.default?.()) });
const link = defineComponent({ props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) });
const collection = defineComponent({ setup: (_, { slots }) => () => h('article', [slots.meta?.(), slots.action?.(), slots.default?.()]) });
const modal = defineComponent({ props: ['groupId'], setup: (props) => () => h('aside', { 'data-member-access': props.groupId }, 'Member access modal') });
const unavailable = () => { throw new Error('Unexpected external action'); };
const response = (data = { message: 'Synthetic confirmation' }, status = 200) => new Response(JSON.stringify(data), { status });
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };
const member = (overrides = {}) => ({ id: 42, name: 'Synthetic member', email: 'member@example.test', status: 'active', provided_at: null, revoked_at: null, revoke_required: false, ...overrides });
const group = (overrides = {}) => ({ id: 7, name: 'Synthetic group', mode: 'invitation', invitation_channel: 'link', closed: false, credentials_ready: false, members: [member()], ...overrides });
const emptyCredentials = () => ({ credential_email: '', credential_password: '', credential_notes: '' });

function element(type, text = '') {
    const node = { type, tagName: type.toUpperCase(), text, children: [], parent: null, props: {}, value: '', checked: false, listeners: {}, style: {}, selected: false };
    node.addEventListener = (event, callback) => { node.listeners[event] = callback; };
    node.removeEventListener = (event) => { delete node.listeners[event]; };
    node.getRootNode = () => offlineDocument;
    node.getAttribute = (name) => node.props[name];
    node.focus = () => { offlineDocument.activeElement = node; };
    return node;
}
function walk(node) { return [node, ...node.children.flatMap(walk)]; }
function textOf(node) { return node.text + node.children.map(textOf).join(''); }
const renderer = createRenderer({
    createElement: (type) => element(type), createText: (text) => element('#text', text), createComment: () => element('#comment'),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: (node) => node.parent,
    nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor = null) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        node.parent = parent;
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        if (index < 0) parent.children.push(node); else parent.children.splice(index, 0, node);
    },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, _old, value) {
        node.props[key] = value;
        if (key === 'value') { node.value = value; node._value = value; }
        if (key === 'checked') node.checked = value;
    },
});

function mount(relative, initialProps, { fetch = unavailable, cookie = 'XSRF-TOKEN=synthetic%3Dtoken', draft = null } = {}) {
    const cache = new Map();
    const props = reactive(initialProps);
    const container = element('root');
    const events = new Map();
    const reloads = [];
    const document = { cookie, getElementById: (id) => walk(container).find((node) => node.props.id === id) };
    function load(path) {
        if (cache.has(path)) return cache.get(path);
        const { descriptor, errors } = parse(readFileSync(resolve(root, path), 'utf8'));
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path, inlineTemplate: true });
        const compiled = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
        const module = { exports: {} };
        vm.runInNewContext(compiled, {
            exports: module.exports, fetch, AbortController, URL, document, setTimeout, clearTimeout,
            window: { addEventListener: (event, fn) => events.set(event, fn), removeEventListener: (event) => events.delete(event), location: { assign: unavailable }, confirm: () => false },
            require(name) {
                if (name === 'vue') return require(name);
                if (name === 'lucide-vue-next') return new Proxy({}, { get: () => none });
                if (name === '@inertiajs/vue3') return { Head: none, Link: link, router: { reload: (options) => reloads.push(options), on: () => () => {}, visit: unavailable, patch: unavailable } };
                if (name === '@/Layouts/DashboardLayout.vue') return { __esModule: true, default: frame };
                if (name === '@/Components/Experience/CollectionCard.vue') return { __esModule: true, default: collection };
                if (name === '@/Components/CredentialsModal.vue') return { __esModule: true, default: modal };
                if (name === '@/Components/Experience/ExperienceDialog.vue') return { __esModule: true, default: none };
                if (name === '@/Components/Owner/OwnerCredentials.vue') return { __esModule: true, default: load(name.slice(2)) };
                if (name.startsWith('@/Components/Owner/') && name.endsWith('.vue')) return { __esModule: true, default: none };
                if (name === '@/composables/useGroupDraft' && draft) return { useGroupDraft: () => draft };
                if (name === '@/composables/useToast') return { useToast: () => ({ error: unavailable, success: unavailable }) };
                if (name === '@/utils/ownerCountry') return ownerCountry;
                if (name === '@/utils/groupDraft') return draftUtils;
                if (name.endsWith('.css')) return {};
                throw new Error(`Unexpected dependency ${name}`);
            },
        });
        cache.set(path, module.exports.default);
        return module.exports.default;
    }
    const component = load(relative);
    const app = renderer.createApp(defineComponent({ setup: () => () => h(component, props) }));
    app.config.warnHandler = (message) => { throw new Error(message); };
    app.mount(container);
    const nodes = () => walk(container);
    const find = (condition) => { const result = nodes().find(condition); assert.ok(result, 'Expected element'); return result; };
    return {
        app, props, nodes, events, reloads, document, find, text: () => textOf(container),
        id: (id) => find((node) => node.props.id === id),
        button: (label) => find((node) => node.type === 'button' && textOf(node).includes(label)),
        form: () => find((node) => node.type === 'form'),
    };
}
const access = (data = group(), options) => mount('Pages/Dashboard/Groups/Access.vue', { group: data }, options);
async function settle() { for (let i = 0; i < 12; i++) { await Promise.resolve(); await nextTick(); } }
async function change(node, value) { node.props['onUpdate:modelValue'](value); await settle(); }
async function click(button) { assert.ok(!button.props.disabled); button.props.onClick(); await settle(); }
async function submit(form) { form.props.onSubmit({ preventDefault() {} }); await settle(); }
async function fillCredentials(view) {
    await change(view.id('owner-field-credential_email'), 'shared@example.test');
    await change(view.id('owner-field-credential_password'), 'synthetic-only-password');
    await change(view.id('owner-field-credential_notes'), 'Synthetic instructions');
}

test('credentials metadata never prefills secrets; both fields and accessible labels are required', () => {
    const view = access(group({ mode: 'credentials', invitation_channel: null, credentials_ready: true }));
    assert.match(view.text(), /déjà enregistrés/);
    for (const suffix of ['credential_email', 'credential_password']) {
        const field = view.id(`owner-field-${suffix}`);
        assert.equal(field.value, '');
        assert.equal(field.props.required, true);
        assert.ok(view.find((node) => node.type === 'label' && node.props.for === field.props.id));
    }
    assert.equal(view.id('owner-field-credential_password').props.type, 'password');
    view.app.unmount();
});

test('credentials save uses same-origin JSON with CSRF and clears all secrets only on server success', async () => {
    let calls = 0;
    const view = access(group({ mode: 'credentials', invitation_channel: null }), { fetch: async (url, options) => {
        calls++;
        assert.equal(url, '/api/groups/7/service-access');
        assert.equal(options.method, 'PUT');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.redirect, 'error');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.headers['X-XSRF-TOKEN'], 'synthetic=token');
        assert.equal(options.headers.Accept, 'application/json');
        assert.deepEqual(JSON.parse(options.body), { credential_email: 'shared@example.test', credential_password: 'synthetic-only-password', credential_notes: 'Synthetic instructions' });
        return response();
    } });
    await fillCredentials(view);
    await submit(view.form());
    assert.equal(calls, 1);
    for (const suffix of ['credential_email', 'credential_password', 'credential_notes']) assert.equal(view.id(`owner-field-${suffix}`).value, '');
    assert.equal(textOf(view.find((node) => node.props.role === 'status')).startsWith('Identifiants enregistrés'), true);
    assert.deepEqual(JSON.parse(JSON.stringify(view.reloads)), [{ only: ['group'] }]);
    view.app.unmount();
});

test('incomplete credentials never send a mutation and focus the error summary', async () => {
    const view = access(group({ mode: 'credentials' }));
    await change(view.id('owner-field-credential_email'), 'shared@example.test');
    await submit(view.form());
    const field = view.id('owner-field-credential_password');
    assert.equal(field.props['aria-invalid'], true);
    assert.ok(view.id(field.props['aria-describedby']));
    assert.equal(offlineDocument.activeElement.props.role, 'alert');
    assert.equal(view.reloads.length, 0);
    view.app.unmount();
});

test('link invitations can be prepared before payment but send only the selected member URL', async () => {
    const requests = [];
    const view = access(group({ members: [member({ status: 'pending_payment' })] }), { fetch: async (...args) => { requests.push(args); return response(); } });
    assert.equal(view.nodes().filter((node) => node.props.type === 'password').length, 0);
    await click(view.button('Préparer l’invitation'));
    assert.equal(offlineDocument.activeElement.props.id, 'invitation-42');
    await change(view.id('invitation-42'), 'https://www.dropbox.com/synthetic-invite?token=fixture');
    await submit(view.form());
    assert.equal(requests.length, 1);
    assert.equal(requests[0][0], '/api/groups/7/members/42/service-access');
    assert.equal(requests[0][1].method, 'PUT');
    assert.deepEqual(JSON.parse(requests[0][1].body), { invitation_url: 'https://www.dropbox.com/synthetic-invite?token=fixture' });
    assert.equal(view.nodes().filter((node) => node.type === 'input').length, 0);
    await click(view.button('Préparer l’invitation'));
    assert.equal(view.id('invitation-42').value, '');
    view.app.unmount();
});

test('Bitwarden requires active payment, an explicit sent checkbox, no URL and no master password', async () => {
    const requests = [];
    const view = access(group({ invitation_channel: 'provider_email', members: [member({ status: 'pending_payment' })] }), { fetch: async (...args) => { requests.push(args); return response(); } });
    assert.equal(view.nodes().filter((node) => node.type === 'button').length, 0);
    assert.match(view.text(), /Attendez la validation du paiement/);
    view.props.group = group({ invitation_channel: 'provider_email' });
    await settle();
    await click(view.button('Déclarer un envoi Bitwarden'));
    assert.equal(view.id('invitation-42').props.type, 'checkbox');
    assert.ok(view.button('Déclarer l’envoi').props.disabled);
    await submit(view.form());
    assert.equal(requests.length, 0);
    await change(view.id('invitation-42'), true);
    await submit(view.form());
    assert.equal(requests.length, 1);
    assert.deepEqual(JSON.parse(requests[0][1].body), { invitation_sent: true });
    assert.equal(requests[0][0], '/api/groups/7/members/42/service-access');
    assert.equal(view.nodes().some((node) => ['password', 'url'].includes(node.props.type)), false);
    assert.match(view.text(), /Après acceptation par le membre, confirmez-le également dans Bitwarden/);
    for (const anchor of view.nodes().filter((node) => node.props.target === '_blank')) {
        assert.equal(anchor.props.rel, 'noopener noreferrer');
        assert.ok(['https://vault.bitwarden.com/', 'https://vault.bitwarden.eu/'].includes(anchor.props.href));
    }
    view.app.unmount();
});

test('closed groups keep revocation tasks but no delivery; checkbox attests a manual supplier removal', async () => {
    const requests = [];
    const view = access(group({ closed: true, members: [member({ status: 'left', revoke_required: true, provided_at: '2026-10-07T12:00:00Z' }), member({ id: 43 })] }), { fetch: async (...args) => { requests.push(args); return response(); } });
    assert.equal(view.nodes().filter((node) => node.type === 'button').length, 1);
    await click(view.button('Déclarer le retrait'));
    assert.ok(view.button('Confirmer le retrait effectué').props.disabled);
    await submit(view.form());
    assert.equal(requests.length, 0);
    await change(view.id('removed-42'), true);
    await submit(view.form());
    assert.equal(requests.length, 1);
    assert.equal(requests[0][0], '/api/groups/7/members/42/service-access/revoke');
    assert.equal(requests[0][1].method, 'POST');
    assert.deepEqual(JSON.parse(requests[0][1].body), { removed_at_provider: true });
    assert.match(view.text(), /aucune action automatique n’a été effectuée chez le fournisseur/);
    view.app.unmount();
});

test('closed credential groups, revoked members, unsupported channels and inactive members cannot deliver', () => {
    const cases = [group({ closed: true, mode: 'credentials' }), group({ invitation_channel: null }), group({ members: [member({ revoked_at: '2026-10-07T12:00:00Z' })] }), ...['left', 'kicked', 'suspended'].map((status) => group({ members: [member({ status })] }))];
    for (const data of cases) {
        const view = access(data);
        assert.equal(view.nodes().filter((node) => ['input', 'form', 'button'].includes(node.type)).length, 0);
        view.app.unmount();
    }
});

test('422 field errors are linked and input stays in memory for a retry, never marked saved', async () => {
    const view = access(group(), { fetch: async () => response({ errors: { invitation_url: ['Domaine non autorisé.'] } }, 422) });
    await click(view.button('Préparer l’invitation'));
    await change(view.id('invitation-42'), 'https://invalid.example.test/invite');
    await submit(view.form());
    const field = view.id('invitation-42');
    assert.equal(field.value, 'https://invalid.example.test/invite');
    assert.equal(field.props['aria-invalid'], true);
    assert.ok(field.props['aria-describedby'].includes('invitation-error-42'));
    assert.equal(textOf(view.id('invitation-error-42')), 'Domaine non autorisé.');
    assert.equal(view.reloads.length, 0);
    assert.equal(offlineDocument.activeElement.props.role, 'alert');
    view.app.unmount();
});

test('session, authorization, conflict, throttle, server, invalid JSON and network errors cannot signal success', async () => {
    for (const fetch of [
        ...[401, 403, 404, 409, 419, 429, 500].map((status) => async () => response({}, status)),
        async () => new Response('<html>login</html>'), async () => response({}), async () => { throw new Error('Offline'); },
    ]) {
        const view = access(group({ mode: 'credentials' }), { fetch });
        await fillCredentials(view);
        await submit(view.form());
        assert.ok(view.find((node) => node.props.role === 'alert'));
        assert.equal(view.nodes().filter((node) => node.props.role === 'status').length, 0);
        assert.equal(view.reloads.length, 0);
        assert.equal(view.id('owner-field-credential_password').value, 'synthetic-only-password');
        assert.ok(!view.button('Enregistrer les identifiants').props.disabled);
        view.app.unmount();
    }
});

test('missing or malformed CSRF prevents sending any secret', async () => {
    for (const cookie of ['', 'XSRF-TOKEN=%']) {
        const view = access(group({ mode: 'credentials' }), { cookie });
        await fillCredentials(view);
        await submit(view.form());
        assert.match(view.text(), /session doit être actualisée/);
        assert.equal(view.reloads.length, 0);
        view.app.unmount();
    }
});

test('double submit sends only one mutation and disables fields until confirmation', async () => {
    const request = deferred();
    let calls = 0;
    const view = access(group({ mode: 'credentials' }), { fetch: () => { calls++; return request.promise; } });
    await fillCredentials(view);
    const action = view.form().props.onSubmit;
    action({ preventDefault() {} }); action({ preventDefault() {} });
    await settle();
    assert.equal(calls, 1);
    assert.equal(view.form().props['aria-busy'], true);
    assert.ok(view.find((node) => node.type === 'fieldset').props.disabled);
    request.resolve(response());
    await settle();
    assert.equal(view.form().props['aria-busy'], false);
    assert.equal(view.reloads.length, 1);
    view.app.unmount();
});

test('switching member editors clears previous invitation and cancellations erase it', async () => {
    const view = access(group({ members: [member(), member({ id: 43, name: 'Second synthetic member' })] }));
    await click(view.find((node) => node.props['aria-label'] === 'Gérer l’invitation de Synthetic member'));
    await change(view.id('invitation-42'), 'https://www.dropbox.com/fixture-secret');
    await click(view.find((node) => node.props['aria-label'] === 'Gérer l’invitation de Second synthetic member'));
    assert.equal(view.id('invitation-43').value, '');
    await click(view.button('Annuler et effacer'));
    assert.equal(view.nodes().filter((node) => node.type === 'input').length, 0);
    view.app.unmount();
});

test('pagehide and unmount abort pending writes and ignore late responses, without storing secrets', async () => {
    for (const exit of ['pagehide', 'unmount', 'group-change']) {
        const request = deferred();
        let signal;
        const view = access(group({ mode: 'credentials' }), { fetch: (_url, options) => { signal = options.signal; return request.promise; } });
        await fillCredentials(view);
        await submit(view.form());
        if (exit === 'pagehide') view.events.get('pagehide')();
        else if (exit === 'group-change') view.props.group = group({ id: 9, mode: 'credentials' });
        else view.app.unmount();
        await settle();
        assert.equal(signal.aborted, true);
        request.resolve(response());
        await settle();
        assert.equal(view.reloads.length, 0);
        if (exit !== 'unmount') {
            assert.equal(view.id('owner-field-credential_password').value, '');
            assert.equal(view.nodes().filter((node) => node.props.role === 'status').length, 0);
            view.app.unmount();
        }
    }
});

test('OwnerCredentials hides and clears all fields when invitation mode replaces credentials', async () => {
    const value = reactive({ credential_email: 'synthetic@example.test', credential_password: 'synthetic-only-password', credential_notes: 'Synthetic notes' });
    const view = mount('Components/Owner/OwnerCredentials.vue', { modelValue: value, errors: {}, disabled: false, accessMode: 'credentials', 'onUpdate:modelValue': (next) => Object.assign(value, next) });
    view.props.accessMode = 'invitation';
    await settle();
    assert.equal(view.nodes().filter((node) => ['input', 'textarea'].includes(node.type)).length, 0);
    assert.deepEqual({ ...value }, emptyCredentials());
    assert.match(view.text(), /préparez les invitations/);
    assert.match(view.text(), /accepter personnellement l’invitation/);
    assert.match(view.text(), /Ne partagez jamais votre mot de passe principal/);
    view.app.unmount();
});

test('collection owner action links to access management; joined member modal stays unchanged', async () => {
    const shared = { id: 7, subscriptionName: 'Synthetic', subscriptionSlug: 'synthetic', status: 'active', currency: 'CAD', pricePerMember: 100, membersCount: 2, maxMembers: 6, renewalDate: null, inviteLink: null };
    const view = mount('Pages/Dashboard/Subscriptions.vue', { initialTab: 'owned', ownedSubscriptions: [shared], joinedSubscriptions: [{ ...shared, ownerName: 'Owner', joinedAt: null, spotsLeft: 2 }], accessRevocations: [{ id: 8, name: 'Closed synthetic', url: '/dashboard/groups/8/access' }] });
    assert.equal(view.find((node) => node.props['aria-label'] === 'Gérer les accès de Synthetic').props.href, '/dashboard/groups/7/access');
    assert.equal(view.find((node) => node.props['aria-label'] === 'Gérer les retraits de Closed synthetic').props.href, '/dashboard/groups/8/access');
    assert.equal(view.nodes().some((node) => node.props['data-member-access']), false);
    await click(view.button('J’ai rejoint'));
    await click(view.button('Mes accès'));
    assert.equal(view.find((node) => node.props['data-member-access']).props['data-member-access'], 7);
    view.props.accessRevocations = [];
    await settle();
    assert.equal(view.nodes().some((node) => node.props.id === 'access-revocations-title'), false);
    view.app.unmount();
});

test('Create passes invitation mode through and publishes without credential fields from a previous selection', async () => {
    const service = { id: 3, name: 'Synthetic', slug: 'synthetic', access_mode: 'credentials', monthly_price: 1500, currency: 'CAD', tier: 'famille', category: 'Synthetic', max_members: 6 };
    const data = { ...draftUtils.emptyDraftData(), ...draftUtils.serviceDefaults(service), renewal_date: '2099-12-01' };
    const state = reactive({ data, errors: {}, message: '', saved: null, conflict: false, leaving: false, operation: 'idle', id: null });
    const publications = [];
    const draft = { state, saved: ref(false), busy: ref(false), locked: ref(false), publish: async (...args) => { publications.push(args); return null; }, save: unavailable, activate: unavailable, reopen: unavailable, changeCurrency: unavailable };
    const view = mount('Pages/Dashboard/Groups/Create.vue', { subscriptions: [service], draft: null, supportedCurrencies: ['CAD'], enabledCurrencies: ['CAD'], ownerReadiness: { ready: true, identityVerified: true, connectActive: true } }, { draft });
    await fillCredentials(view);
    view.props.subscriptions = [{ ...service, access_mode: 'invitation' }];
    await settle();
    assert.equal(view.nodes().some((node) => node.props.id === 'owner-field-credential_password'), false);
    await change(view.id('owner-field-certify'), true);
    await click(view.button('Préparer mon groupe'));
    await click(view.button('Continuer vers la publication'));
    await click(view.button('Publier mon groupe'));
    assert.equal(publications.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(publications[0][2])), emptyCredentials());
    view.app.unmount();
});
