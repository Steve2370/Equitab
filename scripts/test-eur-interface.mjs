// Real Vue scripts/templates; HTTP, Inertia navigation and decorative children are isolated.
// No Laravel bootstrap, environment file, external request or payment SDK.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp, defineComponent, h, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as money from '../resources/js/utils/money.ts';
import * as drafts from '../resources/js/utils/groupDraft.ts';
import * as quotes from '../resources/js/utils/paymentQuote.ts';
import * as presentation from '../resources/js/config/servicePresentation.ts';

const root = fileURLToPath(new URL('../resources/js/', import.meta.url));
const require = createRequire(import.meta.url);
const empty = defineComponent({ setup: () => () => null });
const icons = new Proxy({}, { get: () => empty });
const cache = new Map();
const ssrEffects = [];
const forbiddenSsrEffect = name => () => {
    ssrEffects.push(name);
    throw new Error(`Unexpected SSR side effect: ${name}`);
};
// Load the actual composable, not a synthetic ready/pending implementation.
// Vue's real SSR lifecycle must defer its GET and polling until client mount.
const serviceAccessModule = { exports: {} };
vm.runInNewContext(ts.transpileModule(readFileSync(resolve(root, 'composables/useServiceAccess.ts'), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, {
    exports: serviceAccessModule.exports, URL, AbortController, performance,
    fetch: forbiddenSsrEffect('fetch'),
    setTimeout: forbiddenSsrEffect('setTimeout'),
    clearTimeout: forbiddenSsrEffect('clearTimeout'),
    require(name) {
        if (name === 'vue') return require(name);
        throw new Error(`Unexpected composable dependency: ${name}`);
    },
});
function component(relative, { setupOnly = false, fetch = () => { throw new Error('Unexpected network'); } } = {}) {
    const path = resolve(root, relative);
    if (!setupOnly && cache.has(path)) return cache.get(path);
    const { descriptor, errors } = parse(readFileSync(path, 'utf8'));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: relative, inlineTemplate: !setupOnly, templateOptions: { ssr: true } });
    const compiled = ts.transpileModule(script.content.replaceAll('import.meta.env.VITE_STRIPE_KEY', '"pk_test_offline"'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const module = { exports: {} };
    vm.runInNewContext(compiled, {
        exports: module.exports, fetch, URL,
        require(name) {
            if (name === 'vue' || name === 'vue/server-renderer') return require(name);
            if (name === 'lucide-vue-next') return icons;
            if (name === '@inertiajs/vue3') return {
                Head: defineComponent({ props: ['title'], setup: props => () => h('title', props.title) }),
                Link: defineComponent({ props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
                usePage: () => ({ url: '/services', props: { auth: { user: null } } }),
            };
            if (name === '@/utils/money') return money;
            if (name === '@/utils/groupDraft') return drafts;
            if (name === '@/utils/paymentQuote') return quotes;
            if (name === '@/config/servicePresentation') return presentation;
            if (name === '@/config/brandGradients') return { getBrandGradient: () => ({ from: '#187a57', to: '#187a57' }) };
            if (name === '@/composables/useExperienceMotion') return { useExperienceMotion: () => ({ motion: ref(false) }) };
            if (name === '@/composables/useServiceAccess') return serviceAccessModule.exports;
            if (/\/(EquitabWordmark|ExperienceDialog|TierBadge|NavbarWithSearch|Footer|CollectionHeader)\.vue$/.test(name)) return { __esModule: true, default: empty };
            if (name.endsWith('.vue')) {
                const child = name.startsWith('@/') ? name.slice(2) : resolve(dirname(path), name);
                return { __esModule: true, default: component(child) };
            }
            throw new Error(`Unexpected dependency: ${name}`);
        },
    });
    if (!setupOnly) cache.set(path, module.exports.default);
    return module.exports.default;
}
async function render(path, props) {
    const app = createSSRApp(component(path), props);
    app.config.warnHandler = (message) => { throw new Error(message); };
    return (await renderToString(app)).replace(/[\u00a0\u202f]/g, ' ');
}
const service = { id: 3, name: 'Synthetic', slug: 'netflix', tier: 'famille', category: 'Test', monthly_price: 1599, currency: 'CAD', max_members: 6 };
const group = {
    id: 15, name: 'Synthetic group', subscriptionName: 'Synthetic', subscriptionSlug: 'netflix',
    ownerName: 'Synthetic owner', ownerTrustScore: null, pricePerMember: 789, currency: 'EUR',
    spotsAvailable: 2, maxMembers: 6, currentMembers: 4, ownerIdentityStatus: 'verified', ownerActiveGroupsCount: 1,
    tier: 'famille', createdAt: '07/10/2026', renewalDate: '01/11/2026', memberStatus: 'active',
    subscription: { currency: 'CAD' },
};

test('public group cards and servicegroups render group currency, including mixed groups', async () => {
    const html = await render('Pages/ServiceGroups.vue', {
        subscription: { name: 'Synthetic', slug: 'netflix', currency: 'CAD' },
        groups: [group, { ...group, id: 16, currency: 'CAD', pricePerMember: 123 }],
        canLogin: true, canRegister: true, isAuthenticated: false,
    });
    assert.ok(html.includes('7,89 EUR'));
    assert.ok(html.includes('1,23 CAD'));
    assert.ok(!html.includes('7,89 CAD'));
    assert.ok(!html.includes('stripe-card-element'));
});

test('home cards keep each group native amount and currency', async () => {
    const html = await render('Pages/Welcome.vue', {
        openGroups: [group], catalogServices: [], canLogin: true, canRegister: true, isAuthenticated: false,
    });
    assert.ok(html.includes('7,89 EUR'));
    assert.ok(!html.includes('7,89 CAD'));
    assert.ok(!html.includes('En dollars canadiens'));
});

test('catalogue prices retain the catalogue currency', async () => {
    const html = await render('Pages/Services.vue', { categories: [{ id: 1, name: 'Test', subscriptions: [service, { ...service, id: 4, currency: 'EUR', monthly_price: 2400 }] }] });
    assert.ok(html.includes('2,67 CAD'));
    assert.ok(html.includes('4,00 EUR'));
    const picker = await render('Components/Owner/OwnerServicePicker.vue', { subscriptions: [service], selected: null, disabled: false });
    assert.ok(picker.includes('15,99 CAD'));
});

test('selected invitation catalogue cards render supplied icons without a made-up zero price', async () => {
    const subscriptions = [
        { ...service, id: 10, name: 'Dropbox Family', slug: 'dropbox-family', monthly_price: null },
        { ...service, id: 11, name: 'NordPass Family', slug: 'nordpass-family', monthly_price: null },
    ];
    const html = await render('Pages/Services.vue', { categories: [{ id: 1, name: 'Test', subscriptions }] });
    assert.ok(html.includes('/Images/services/dropbox.svg'));
    assert.ok(html.includes('/Images/services/nordpass.png'));
    assert.doesNotMatch(html, /Bitwarden|0,00|NaN/);
});

test('invitation and success use server group currency independently from catalogue', async () => {
    for (const currency of ['CAD', 'EUR']) {
        const data = { ...group, currency };
        const invitation = await render('Pages/InvitePage.vue', { group: data, inviteToken: 'synthetic', accessState: 'guest', continueUrl: '/invite/synthetic/continue' });
        const success = await render('Pages/PaymentSuccess.vue', { group: data, credentials: null });
        assert.ok(invitation.includes(`7,89 ${currency}`));
        assert.ok(success.includes(`7,89 ${currency}`));
    }
    for (const page of ['Pages/InvitePage.vue', 'Pages/PaymentSuccess.vue']) {
        const html = await render(page, { group: { ...group, currency: undefined }, credentials: null, inviteToken: 'synthetic', accessState: 'guest', continueUrl: '/invite/synthetic/continue' });
        assert.ok(html.includes('Montant indisponible'));
        assert.ok(!html.includes('7,89 CAD'));
    }
});

test('payment SSR preserves CAD/EUR amounts but cannot claim success or reveal access before its authorized GET', async () => {
    const credentials = { email: 'ssr-synthetic@example.test', password: 'SSR-SYNTHETIC-NOT-REAL', notes: 'SSR-SYNTHETIC-NOTE' };
    const invitation = { channel: 'link', url: 'https://provider.example.test/invite/ssr-synthetic', provided_at: '2026-10-07T17:00:00Z', recipient_email: null };
    const states = [
        [undefined, 'Vérification de votre abonnement'],
        [{ status: 'payment_pending', mode: 'credentials', credentials: null, invitation: null }, 'Confirmation du paiement en cours'],
        [{ status: 'unavailable', mode: 'invitation', credentials: null, invitation: null }, 'Accès indisponible'],
        [{ status: 'ready', mode: 'credentials', credentials: null, invitation: null }, 'Vérification de votre abonnement'],
        [{ status: 'ready', mode: 'invitation', credentials: null, invitation: null }, 'Vérification de votre abonnement'],
        // Legacy/history props containing secrets must not bypass a fresh GET either.
        [{ status: 'ready', mode: 'credentials', credentials, invitation: null }, 'Vérification de votre abonnement'],
        [{ status: 'ready', mode: 'invitation', credentials: null, invitation }, 'Vérification de votre abonnement'],
    ];
    for (const currency of ['CAD', 'EUR']) {
        for (const [serviceAccess, expectedTitle] of states) {
            const html = await render('Pages/PaymentSuccess.vue', { group: { ...group, currency, memberStatus: 'active' }, credentials, serviceAccess });
            assert.ok(html.includes(`7,89 ${currency}`));
            assert.match(html, new RegExp(`<title>${expectedTitle} — Equitab</title>`));
            assert.match(html, new RegExp(`<h1[^>]*>${expectedTitle}</h1>`));
            assert.doesNotMatch(html, /Paiement confirmé|réalisé avec succès|Votre abonnement est actif/);
            for (const secret of [...Object.values(credentials), invitation.url]) {
                assert.ok(!html.includes(secret), 'SSR cannot serialize access from initial props');
            }
            assert.doesNotMatch(html, /Afficher le mot de passe|Ouvrir mon invitation/);
            assert.deepEqual(ssrEffects, [], 'SSR must not perform GET, poll or arm timers');
        }
    }
});

test('legacy cards consume minor units and require a currency without multiplying prices by 100', async () => {
    for (const page of ['Components/GroupCard.vue', 'Components/ServiceCard.vue']) {
        const html = await render(page, { groupId: 15, name: 'Synthetic', slug: 'netflix', subscriptionName: 'Synthetic', subscriptionSlug: 'netflix', pricePerMember: 789, currency: 'EUR', currentMembers: 2, maxMembers: 6, discountPercent: 0 });
        assert.ok(html.includes('7,89 EUR'));
        assert.ok(!html.includes('789,00'));
    }
    const tile = await render('Components/ServiceTile.vue', { name: 'Synthetic', slug: 'netflix', monthlyPrice: 2400, maxMembers: 6, currency: 'EUR' });
    assert.ok(tile.includes('4,00 EUR'));
    const unknown = await render('Components/Experience/CollectionCard.vue', { name: 'Synthetic', slug: 'netflix', price: 789 });
    assert.ok(unknown.includes('Montant indisponible'));
    assert.ok(!unknown.includes('CAD'));
});

test('owner fields distinguish draft currency, original catalogue price and availability', async () => {
    const state = drafts.createDraftState({ id: 'fixture', status: 'draft', version: 1, data: { ...drafts.serviceDefaults(service) } });
    const controller = drafts.createDraftController(state, {}, () => 'fixture');
    controller.changeCurrency('EUR', ['CAD', 'EUR']);
    const props = { modelValue: state.data, subscription: service, errors: {}, disabled: false, supportedCurrencies: ['CAD', 'EUR'], enabledCurrencies: ['CAD', 'EUR'], priceNotice: state.priceNotice };
    const html = await render('Components/Owner/OwnerPreparation.vue', props);
    assert.ok(html.includes('Prix total par mois (EUR)'));
    assert.ok(html.includes('15,99 CAD'));
    assert.match(html, /id="owner-field-total_price"[^>]*value=""/);
    assert.ok(html.includes('Saisissez à nouveau votre prix mensuel en EUR'));
    assert.match(html, /id="owner-price-notice"[^>]*role="status"/);
    assert.match(html, /id="owner-field-total_price"[^>]*aria-describedby="[^"]*owner-price-notice/);
    const draftOnly = await render('Components/Owner/OwnerPreparation.vue', { ...props, enabledCurrencies: ['CAD'] });
    assert.match(draftOnly, /<option[^>]*value="EUR"[^>]*>EUR · brouillon seulement/);
    assert.doesNotMatch(draftOnly, /<option[^>]*value="EUR"[^>]*disabled/);
    assert.ok(draftOnly.includes('Vous pouvez enregistrer ce brouillon.'));
    assert.ok(drafts.preparationErrors(state.data, service, ['CAD']).currency,
        'EUR drafts stay editable while publication remains blocked');
});

test('owner preview accepts only a server preview in the draft currency', async () => {
    const data = { ...drafts.serviceDefaults(service), currency: 'EUR' };
    const preview = { total_price: 1599, max_members: 6, currency: 'EUR', full_group_share: 777 };
    const html = await render('Components/Owner/OwnerGroupPreview.vue', { data, subscription: service, preview });
    assert.ok(html.includes('7,77 EUR'));
    assert.ok(html.includes('15,99 EUR'));
    assert.ok(!html.includes('CAD'));
    const stale = await render('Components/Owner/OwnerGroupPreview.vue', { data, subscription: service, preview: { ...preview, currency: 'CAD' } });
    assert.ok(!stale.includes('7,77'));
    assert.ok(stale.includes('2,67 EUR'));
});

test('checkout renders both amounts in quote currency and never substitutes a monthly price for missing proration', async () => {
    const html = await render('Components/StripeCardForm.vue', { groupId: 15, subscriptionName: 'Synthetic', amountToday: 321, pricePerMember: 789, currency: 'EUR' });
    assert.ok(html.includes('3,21 EUR'));
    assert.ok(html.includes('7,89 EUR'));
    const pending = await render('Components/StripeCardForm.vue', { groupId: 15, subscriptionName: 'Synthetic', pricePerMember: 789, currency: 'EUR' });
    assert.ok(pending.includes('Montant à confirmer'));
    assert.ok(!pending.includes('7,89'));
});

test('group detail retains validated proration currency and refuses malformed responses before opening checkout', async () => {
    for (const currency of ['CAD', 'EUR', undefined, 'USD']) {
        const subject = component('Components/OwnerGroupCard.vue', { setupOnly: true, fetch: async (url) => {
            assert.equal(url, '/api/groups/15/proration');
            return { ok: true, json: async () => ({ amount_today: 321, amount_recurring: 789, currency }) };
        } });
        const state = subject.setup({ ...group, groupId: 15 }, { expose() {} });
        await state.openSubscribeForm();
        if (currency === 'CAD' || currency === 'EUR') {
            assert.equal(state.showForm.value, true);
            assert.equal(state.prorationData.value.currency, currency);
        } else {
            assert.equal(state.showForm.value, false);
            assert.equal(state.prorationData.value, null);
            assert.ok(state.error.value);
        }
    }
});
