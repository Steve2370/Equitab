// Real Vue components and country transport, with an in-memory renderer and isolated HTTP.
// No browser storage, application database, environment file or Stripe connection.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createRenderer, defineComponent, h, nextTick } from 'vue';
import ts from 'typescript';
import * as countryTransport from '../resources/js/utils/ownerCountry.ts';

// Vue's native input directive checks the root document even with a custom renderer.
class OfflineDocument { activeElement = null; }
globalThis.Document = OfflineDocument;
globalThis.ShadowRoot = class {};
const offlineDocument = new OfflineDocument();

const root = fileURLToPath(new URL('../resources/js/', import.meta.url));
const require = createRequire(import.meta.url);
const none = defineComponent({ setup: () => () => null });
const frame = defineComponent({ setup: (_, { slots }) => () => h('main', slots.default?.()) });
const unavailableNetwork = async () => { throw new Error('Unexpected network call'); };
const countries = [
    { code: 'CA', name: 'Canada', enabled: true },
    { code: 'BE', name: 'Belgique', enabled: false },
    { code: 'FR', name: 'France', enabled: false },
];
const state = (overrides = {}) => ({ country: null, locked: false, canStart: false, countries, ...overrides });
const response = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
const ready = (ownerCountry, overrides = {}) => ({
    ready: false, identityVerified: false, connectActive: false,
    identityStatus: 'unverified', connectStatus: 'not_started', country: ownerCountry, ...overrides,
});
const profile = (ownerCountry) => ({
    ownerCountry,
    user: { name: 'Synthetic owner', email: 'owner@example.test', phone: null, address: 'Synthetic street', city: 'Synthetic city', province: 'QC', postal_code: 'H2X 1Y6', identity_status: 'unverified', stripe_connect_status: 'not_started', trust_score: null, completed_payments: 0 },
});
function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}

function element(type, text = '') {
    const node = { type, tagName: type.toUpperCase(), text, children: [], parent: null, props: {}, value: '', selected: false, selectedIndex: -1, multiple: false, listeners: {} };
    node.addEventListener = (event, callback) => { node.listeners[event] = callback; };
    node.removeEventListener = (event) => { delete node.listeners[event]; };
    node.getRootNode = () => offlineDocument;
    node.getAttribute = (key) => node.props[key];
    Object.defineProperty(node, 'options', { get: () => walk(node).filter((item) => item.type === 'option') });
    return node;
}
function walk(node) { return [node, ...node.children.flatMap(walk)]; }
function textOf(node) { return node.text + node.children.map(textOf).join(''); }
const renderer = createRenderer({
    createElement: (type) => element(type),
    createText: (text) => element('#text', text),
    createComment: (text) => element('#comment', text),
    setText: (node, text) => { node.text = text; },
    setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: (node) => node.parent,
    nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor = null) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        node.parent = parent;
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        if (index === -1) parent.children.push(node); else parent.children.splice(index, 0, node);
    },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, _old, value) {
        node.props[key] = value;
        if (key === 'value') { node.value = value; node._value = value; }
        if (key === 'multiple') node.multiple = !!value;
    },
});

function mount(relative, props, { fetch = unavailableNetwork, patch = () => { throw new Error('Unexpected profile mutation'); } } = {}) {
    const cache = new Map();
    function load(path) {
        if (cache.has(path)) return cache.get(path);
        const { descriptor, errors } = parse(readFileSync(resolve(root, path), 'utf8'));
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path, inlineTemplate: true });
        const compiled = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
        const module = { exports: {} };
        vm.runInNewContext(compiled, {
            exports: module.exports, fetch, Error, document: { cookie: 'XSRF-TOKEN=synthetic%3Dtoken' },
            window: { location: { href: '' } },
            require(name) {
                if (name === 'vue') return require(name);
                if (name === 'lucide-vue-next') return new Proxy({}, { get: () => none });
                if (name === '@inertiajs/vue3') return { Head: none, Link: frame, usePage: () => ({ props: {} }), router: { patch } };
                if (name === '@/Layouts/DashboardLayout.vue') return { __esModule: true, default: frame };
                if (name === '@/utils/ownerCountry') return { ...countryTransport, saveOwnerCountry: (code) => countryTransport.saveOwnerCountry(code, fetch, 'XSRF-TOKEN=synthetic%3Dtoken') };
                if (name.endsWith('.vue') && name.startsWith('@/Components/Owner/')) return { __esModule: true, default: load(name.slice(2)) };
                throw new Error(`Unexpected dependency ${name}`);
            },
        });
        cache.set(path, module.exports.default);
        return module.exports.default;
    }
    const container = element('root');
    const app = renderer.createApp(load(relative), props);
    app.config.warnHandler = (message) => { throw new Error(message); };
    app.mount(container);
    const nodes = () => walk(container);
    const find = (predicate) => {
        const item = nodes().find(predicate);
        assert.ok(item, 'Expected an element matching the condition');
        return item;
    };
    return {
        app, container, nodes, find,
        button: (label) => find((item) => item.type === 'button' && textOf(item).includes(label)),
        input: (label) => find((item) => item.type === 'input' && item.props['aria-label'] === label),
        countrySelect: () => find((item) => item.type === 'select' && item.props.autocomplete === 'country'),
        text: () => textOf(container),
    };
}
async function settle() { for (let i = 0; i < 8; i++) { await Promise.resolve(); await nextTick(); } }
async function change(node, value) { node.props['onUpdate:modelValue'](value); await settle(); }
async function click(button) {
    assert.ok(!button.props.disabled, 'The control must be enabled');
    button.props.onClick();
    await settle();
}

test('country transport sends an allowlisted same-origin PATCH with decoded CSRF', async () => {
    const result = state({ country: 'BE' });
    let calls = 0;
    const saved = await countryTransport.saveOwnerCountry('BE', async (url, options) => {
        calls++;
        assert.equal(url, '/api/owner/country');
        assert.equal(options.method, 'PATCH');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers['X-XSRF-TOKEN'], 'synthetic=token');
        assert.deepEqual(JSON.parse(options.body), { country: 'BE' });
        return response(result);
    }, 'other=ignored; XSRF-TOKEN=synthetic%3Dtoken');
    assert.deepEqual(saved, result);
    assert.equal(calls, 1);
    assert.equal(countryTransport.ownerCountryCsrfToken('XSRF-TOKEN=%'), '');
    assert.equal(countryTransport.ownerCountryCsrfToken(''), '');
});

test('country transport rejects errors, redirects, HTML and malformed confirmations', async () => {
    for (const status of [401, 403, 419, 429, 500]) {
        await assert.rejects(countryTransport.saveOwnerCountry('BE', async () => response({}, status), ''), (error) => error instanceof countryTransport.OwnerCountryRequestError && error.status === status);
    }
    await assert.rejects(countryTransport.saveOwnerCountry('BE', async () => response({ errors: { country: ['Pays verrouillé.'] } }, 422), ''), /Pays verrouillé/);
    for (const invalid of [{}, { ...state(), canStart: 'true' }, { ...state(), country: 'EUR' }, { ...state(), countries: [] }]) {
        await assert.rejects(countryTransport.saveOwnerCountry('BE', async () => response(invalid), ''), /confirmation du pays/);
    }
    await assert.rejects(countryTransport.saveOwnerCountry('BE', async () => new Response('<html>login</html>'), ''), /confirmation du pays/);
    await assert.rejects(countryTransport.saveOwnerCountry('BE', unavailableNetwork, ''), /Vérifiez votre connexion/);
});

test('country selection has no guessed default, linked instructions and no automatic mutation', () => {
    const view = mount('Components/Owner/OwnerCountrySelector.vue', { state: state() });
    const select = view.countrySelect();
    assert.equal(select.options[select.selectedIndex]?.value, '');
    assert.ok(view.find((item) => item.type === 'label' && item.props.for === select.props.id));
    for (const id of select.props['aria-describedby'].split(' ')) assert.ok(view.find((item) => item.props.id === id));
    assert.ok(view.button('Enregistrer').props.disabled);
    assert.ok(view.text().includes('préparation seulement'));
    assert.ok(view.text().includes('Ce choix ne modifie pas la devise'));
    view.app.unmount();
});

test('legacy null-country locked accounts show no selector or guessed country', () => {
    const view = mount('Components/Owner/OwnerCountrySelector.vue', { state: state({ locked: true, canStart: true }) });
    assert.equal(view.nodes().filter((item) => item.type === 'select').length, 0);
    assert.ok(view.text().includes('Aucun nouveau choix de pays'));
    assert.ok(!view.text().includes('Canada'));
    view.app.unmount();
});

test('country save keeps pending selection on error, announces it inline and permits explicit retry', async () => {
    let calls = 0;
    const saved = [];
    const view = mount('Components/Owner/OwnerCountrySelector.vue', { state: state(), onSaved: (value) => saved.push(value) }, {
        fetch: async () => { calls++; return response({ errors: { country: ['Pays verrouillé par Stripe.'] } }, 422); },
    });
    await change(view.countrySelect(), 'BE');
    await click(view.button('Enregistrer'));
    assert.equal(calls, 1);
    assert.equal(saved.length, 0);
    assert.equal(view.countrySelect().options[view.countrySelect().selectedIndex]?.value, 'BE');
    assert.equal(view.countrySelect().props['aria-invalid'], true);
    const alert = view.find((item) => item.props.role === 'alert');
    assert.equal(textOf(alert), 'Pays verrouillé par Stripe.');
    assert.ok(view.countrySelect().props['aria-describedby'].includes(alert.props.id));
    assert.ok(!view.button('Enregistrer').props.disabled);
    view.app.unmount();
});

test('save double clicks share one mutation and busy state remains accessible until server confirmation', async () => {
    const request = deferred();
    let calls = 0;
    const saved = [];
    const busy = [];
    const view = mount('Components/Owner/OwnerCountrySelector.vue', { state: state(), onSaved: (value) => saved.push(value), onBusy: (value) => busy.push(value) }, {
        fetch: () => { calls++; return request.promise; },
    });
    await change(view.countrySelect(), 'BE');
    const action = view.button('Enregistrer').props.onClick;
    action(); action(); await settle();
    assert.equal(calls, 1);
    assert.equal(saved.length, 0);
    assert.equal(view.find((item) => item.type === 'section').props['aria-busy'], true);
    assert.ok(view.countrySelect().props.disabled);
    request.resolve(response(state({ country: 'BE' })));
    await settle();
    assert.equal(saved.length, 1);
    assert.deepEqual(saved[0], state({ country: 'BE' }));
    assert.deepEqual(busy, [true, false]);
    assert.equal(view.find((item) => item.type === 'section').props['aria-busy'], false);
    view.app.unmount();
});

test('activation obeys server canStart, including a legacy account with an unknown country', () => {
    for (const ownerCountry of [state(), state({ country: 'BE' }), state({ country: 'CA', canStart: true }), state({ locked: true, canStart: true })]) {
        const view = mount('Components/Owner/OwnerActivation.vue', { readiness: ready(ownerCountry), disabled: false, operation: 'idle' });
        assert.equal(!!view.button('Activer avec Stripe').props.disabled, !ownerCountry.canStart);
        view.app.unmount();
    }
    const active = mount('Components/Owner/OwnerActivation.vue', { readiness: ready(state({ locked: true, canStart: true }), { ready: true, identityVerified: true, connectActive: true }), disabled: false, operation: 'idle' });
    assert.ok(active.text().includes('Vous pouvez passer à la publication'));
    assert.equal(active.nodes().filter((item) => item.type === 'select').length, 0);
    active.app.unmount();
});

test('activation stops using the old country while a different selection is unsaved or saving', async () => {
    const request = deferred();
    const actions = [];
    const view = mount('Components/Owner/OwnerActivation.vue', { readiness: ready(state({ country: 'CA', canStart: true })), disabled: false, operation: 'idle', onActivate: (kind) => actions.push(kind) }, { fetch: () => request.promise });
    await change(view.countrySelect(), 'BE');
    assert.ok(view.button('Activer avec Stripe').props.disabled);
    await click(view.button('Enregistrer le pays'));
    assert.ok(view.button('Activer avec Stripe').props.disabled);
    assert.ok(view.button('Vérifier mon identité').props.disabled);
    request.resolve(response(state({ country: 'BE', canStart: true, countries: countries.map((option) => ({ ...option, enabled: true })) })));
    await settle();
    assert.ok(!view.button('Activer avec Stripe').props.disabled);
    await click(view.button('Activer avec Stripe'));
    assert.deepEqual(actions, ['connect']);
    view.app.unmount();
});

test('profile uses Canadian provinces only for Canada and keeps European postal codes flexible', async () => {
    for (const code of ['CA', 'BE', null]) {
        const view = mount('Pages/Dashboard/Profile.vue', profile(state({ country: code, canStart: code === 'CA' })));
        await click(view.button('Modifier'));
        const region = view.find((item) => item.props.id === 'profile-region');
        assert.equal(region.type, code === 'CA' ? 'select' : 'input');
        if (code === 'CA') assert.equal(region.options.filter((option) => option.value).length, 13);
        else assert.equal(region.props.maxlength, '100');
        const postal = view.input('Code postal');
        assert.equal(postal.props.maxlength, '20');
        assert.equal(postal.props.type, 'text');
        assert.equal(postal.props.pattern, undefined);
        assert.equal(postal.props.placeholder, code === 'CA' ? 'H2X 1Y6' : undefined);
        assert.equal(!!view.button('Configurer mon compte bancaire').props.disabled, code !== 'CA');
        view.app.unmount();
    }
});

test('country change clears address display and editor but retains unsaved name and phone', async () => {
    const patches = [];
    const view = mount('Pages/Dashboard/Profile.vue', profile(state({ country: 'CA', canStart: true })), {
        fetch: async () => response(state({ country: 'BE' })),
        patch: (...args) => patches.push(args),
    });
    await click(view.button('Modifier'));
    await change(view.input('Nom complet'), 'Synthetic edited name');
    await change(view.input('Téléphone'), '+32000000000');
    await change(view.countrySelect(), 'BE');
    assert.ok(view.button('Sauvegarder').props.disabled);
    await click(view.button('Enregistrer le pays'));
    assert.equal(view.input('Nom complet').value, 'Synthetic edited name');
    assert.equal(view.input('Téléphone').value, '+32000000000');
    for (const label of ['Adresse', 'Ville', 'Code postal']) assert.equal(view.input(label).value, '');
    assert.equal(view.find((item) => item.props.id === 'profile-region').value, '');
    await click(view.button('Sauvegarder'));
    assert.equal(patches.length, 1);
    assert.equal(patches[0][0], '/dashboard/profile');
    assert.deepEqual(JSON.parse(JSON.stringify(patches[0][1])), { name: 'Synthetic edited name', phone: '+32000000000', expected_country: 'BE', address: '', city: '', province: '', postal_code: '' });
    patches[0][2].onSuccess(); patches[0][2].onFinish(); await settle();
    assert.ok(!view.text().includes('Synthetic street'));
    assert.ok(!view.text().includes('H2X 1Y6'));
    view.app.unmount();
});

test('cancel after a country change never restores the previous country address', async () => {
    const view = mount('Pages/Dashboard/Profile.vue', profile(state({ country: 'CA', canStart: true })), { fetch: async () => response(state({ country: 'BE' })) });
    await click(view.button('Modifier'));
    await change(view.countrySelect(), 'BE');
    await click(view.button('Enregistrer le pays'));
    await click(view.button('Annuler'));
    assert.ok(!view.text().includes('Synthetic street'));
    await click(view.button('Modifier'));
    for (const label of ['Adresse', 'Ville', 'Code postal']) assert.equal(view.input(label).value, '');
    view.app.unmount();
});

test('profile sends the country of its address snapshot and shows stale-tab rejection', async () => {
    for (const code of ['CA', 'BE', null]) {
        const patches = [];
        const view = mount('Pages/Dashboard/Profile.vue', profile(state({country:code})), {patch: (...args) => patches.push(args)});
        await click(view.button('Modifier'));
        await click(view.button('Sauvegarder'));
        assert.equal(patches[0][1].expected_country, code);
        patches[0][2].onError({country: 'Le pays a changé. Rechargez la page.'});
        patches[0][2].onFinish();
        await settle();
        assert.ok(textOf(view.find((item) => item.props.role === 'alert')).includes('Le pays a changé'));
        assert.equal(view.input('Adresse').value, 'Synthetic street');
        view.app.unmount();
    }
});

test('profile presents rejected Connect requests inline without falsely redirecting', async () => {
    const view = mount('Pages/Dashboard/Profile.vue', profile(state({ locked: true, canStart: true })), {
        fetch: async (url) => {
            assert.equal(url, '/api/stripe/onboarding');
            return response({ errors: { country: ['Activation non disponible.'] } }, 422);
        },
    });
    assert.equal(view.nodes().filter((item) => item.type === 'select').length, 0);
    await click(view.button('Configurer mon compte bancaire'));
    assert.equal(textOf(view.find((item) => item.props.role === 'alert')), 'Activation non disponible.');
    assert.ok(!view.button('Configurer mon compte bancaire').props.disabled);
    view.app.unmount();
});
