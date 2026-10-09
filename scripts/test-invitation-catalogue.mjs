import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as draft from '../resources/js/utils/groupDraft.ts';
import * as money from '../resources/js/utils/money.ts';
import * as presentation from '../resources/js/config/servicePresentation.ts';

const services = [
    ['Dropbox Family', 'dropbox-family', 'Productivité'],
    ['NordPass Family', 'nordpass-family', 'Sécurité'],
].map(([name, slug, category], index) => ({
    id: index + 1, name, slug, category, logo: null, monthly_price: null,
    currency: 'CAD', tier: 'famille', max_members: 6, access_mode: 'invitation',
}));

for (const service of services) {
    test(`${service.name}: defaults leave the real cost blank, retain owner capacity and require explicit cost`, () => {
        const data = draft.serviceDefaults(service);
        assert.equal(data.total_price, null);
        assert.equal(data.max_members, 6);
        assert.equal(data.currency, 'CAD');
        assert.equal(draft.fullGroupShare(data), null);
        assert.equal(draft.hasCataloguePrice(service), false);
        assert.ok(draft.preparationErrors({ ...data, renewal_date: '2099-12-01' }, service).total_price);
        const completed = { ...data, total_price: draft.centsFromInput('13,99'), renewal_date: '2099-12-01' };
        assert.equal(completed.total_price, 1399);
        assert.equal(draft.fullGroupShare(completed), 233);
        assert.deepEqual(draft.preparationErrors(completed, service), {});
        assert.ok(draft.preparationErrors({ ...completed, max_members: 7 }, service).max_members);
        assert.equal(draft.serviceDefaults(service, 'EUR').total_price, null);
    });
}

test('missing, non-integer, negative or unsafe catalogue prices never prefill a draft', () => {
    for (const price of [null, undefined, NaN, Infinity, -1, 1.1, Number.MAX_SAFE_INTEGER + 1, '1000']) {
        const service = { ...services[0], monthly_price: price };
        assert.equal(draft.serviceDefaults(service).total_price, null);
        assert.equal(draft.hasCataloguePrice(service), false);
    }
    const legacy = { ...services[0], monthly_price: 2345, access_mode: 'credentials' };
    assert.equal(draft.serviceDefaults(legacy).total_price, 2345);
    assert.equal(draft.hasCataloguePrice(legacy), true);
    assert.equal(draft.serviceDefaults(legacy, 'EUR').total_price, null);
});

// Render the real Vue components and their brand component, not template string snapshots.
const require = createRequire(import.meta.url);
const compiledComponents = new Map();
function component(path) {
    if (compiledComponents.has(path)) return compiledComponents.get(path);
    const { descriptor, errors } = parse(readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8'));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { ssr: true } });
    const module = { exports: {} };
    vm.runInNewContext(ts.transpileModule(script.content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, {
        exports: module.exports,
        require(name) {
            if (name === 'vue' || name === 'vue/server-renderer') return require(name);
            if (name === '@/utils/groupDraft') return draft;
            if (name === '@/utils/money') return money;
            if (name === '@/config/servicePresentation') return presentation;
            if (name.startsWith('@/Components/') && name.endsWith('.vue')) return { __esModule: true, default: component(name.slice(2)) };
            throw new Error(`Unexpected dependency: ${name}`);
        },
    });
    compiledComponents.set(path, module.exports.default);
    return module.exports.default;
}
async function render(path, props) {
    const app = createSSRApp(component(path), props);
    app.config.warnHandler = message => { throw new Error(message); };
    return renderToString(app);
}

test('service picker shows the two selected names, no logo, no Bitwarden, invented price or monthly provider claim', async () => {
    const html = await render('Components/Owner/OwnerServicePicker.vue', { subscriptions: services, selected: 2, disabled: false });
    for (const service of services) {
        assert.ok(html.includes(`<span class="owner-service-name">${service.name}</span>`));
    }
    assert.doesNotMatch(html, /<img\b|Images\/services/);
    assert.equal((html.match(/Coût à renseigner/g) ?? []).length, 2);
    assert.equal((html.match(/vous compris/g) ?? []).length, 2);
    assert.doesNotMatch(html, /bitwarden/i);
    assert.ok(html.includes('aria-pressed="true"'));
    assert.doesNotMatch(html, /0,00|Prix du catalogue|\/ mois|NaN|null/);
});

test('preparation keeps blank price input and explains monthly contributions versus annual supplier billing', async () => {
    const subscription = services[1];
    const html = await render('Components/Owner/OwnerPreparation.vue', {
        subscription, modelValue: draft.serviceDefaults(subscription), errors: {}, disabled: false,
        supportedCurrencies: ['CAD', 'EUR'], enabledCurrencies: ['CAD'],
    });
    assert.ok(html.includes('Aucun tarif prérempli.'));
    assert.ok(html.includes('cotisations EquitAb sont mensuelles'));
    assert.ok(html.includes('vous avancez ce coût'));
    assert.ok(html.includes('sans modifier votre contrat fournisseur'));
    assert.ok(html.includes('id="owner-price-hint"'));
    assert.match(html, /id="owner-field-total_price"[^>]*value=""/);
    assert.doesNotMatch(html, /Catalogue :|NaN|Montant indisponible/);
});

test('legacy known price still renders monthly catalogue amount and owner-entered cost is preserved', async () => {
    const subscription = { ...services[0], access_mode: 'credentials', monthly_price: 2345 };
    const html = await render('Components/Owner/OwnerPreparation.vue', {
        subscription, modelValue: { ...draft.serviceDefaults(subscription), total_price: 3768 },
        errors: {}, disabled: false, supportedCurrencies: ['CAD'], enabledCurrencies: ['CAD'],
    });
    assert.ok(html.includes('Catalogue : 23,45'));
    assert.match(html, /id="owner-field-total_price"[^>]*value="37,68"/);
    assert.doesNotMatch(html, /Aucun tarif prérempli|vous avancez ce coût/);
});

test('NordPass annual CAD reference prefills a monthly cost but preserves the owner real price', async () => {
    const subscription = { ...services[1], monthly_price: 749, billing_cycle: 'yearly' };
    const defaults = draft.serviceDefaults(subscription);
    assert.equal(defaults.total_price, 749);
    assert.equal(draft.fullGroupShare(defaults), 125);
    assert.equal(presentation.indicativeShare(subscription.monthly_price, subscription.max_members), 125);
    assert.equal(draft.serviceDefaults(subscription, 'EUR').total_price, null);
    const picker = await render('Components/Owner/OwnerServicePicker.vue', { subscriptions: [subscription], selected: 2, disabled: false });
    assert.match(picker, /7,49\u00a0CAD/);
    assert.doesNotMatch(picker, /Coût à renseigner/);
    const html = await render('Components/Owner/OwnerPreparation.vue', {
        subscription, modelValue: { ...defaults, total_price: 900 }, errors: {}, disabled: false,
        supportedCurrencies: ['CAD', 'EUR'], enabledCurrencies: ['CAD'],
    });
    assert.match(html, /Catalogue : 7,49\u00a0CAD/);
    assert.match(html, /id="owner-field-total_price"[^>]*value="9,00"/);
    assert.ok(html.includes('cotisations EquitAb sont mensuelles'));
    assert.ok(html.includes('vous avancez ce coût'));
});

test('Dropbox CAD reference calculates the same six-person share and stays editable', async () => {
    const subscription = { ...services[0], monthly_price: 2649, billing_cycle: 'monthly' };
    const defaults = draft.serviceDefaults(subscription);
    assert.equal(defaults.total_price, 2649);
    assert.equal(draft.fullGroupShare(defaults), 442);
    assert.equal(presentation.indicativeShare(subscription.monthly_price, subscription.max_members), 442);
    assert.equal(draft.serviceDefaults(subscription, 'EUR').total_price, null);
    const picker = await render('Components/Owner/OwnerServicePicker.vue', { subscriptions: [subscription], selected: 1, disabled: false });
    assert.match(picker, /26,49\u00a0CAD/);
    assert.doesNotMatch(picker, /Coût à renseigner/);
    const html = await render('Components/Owner/OwnerPreparation.vue', {
        subscription, modelValue: { ...defaults, total_price: 3000 }, errors: {}, disabled: false,
        supportedCurrencies: ['CAD', 'EUR'], enabledCurrencies: ['CAD'],
    });
    assert.match(html, /Catalogue : 26,49\u00a0CAD/);
    assert.match(html, /id="owner-field-total_price"[^>]*value="30,00"/);
});
