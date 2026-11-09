// Refonte validée : nom du service sur l'illustration, accueil explicite,
// liste compacte des groupes et apparitions animées sans jamais masquer le contenu.
// Rendu réel des composants Vue, sans réseau ni Laravel.
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
import { installReveal } from '../resources/js/utils/reveal.ts';

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

const group = {
    id: 21, subscriptionName: 'Netflix', subscriptionSlug: 'netflix', ownerName: 'Camille',
    ownerIdentityStatus: 'verified', ownerActiveGroupsCount: 1, ownerTrustScore: 85, tier: 'standard',
    pricePerMember: 600, currency: 'CAD', spotsAvailable: 2, maxMembers: 6, createdAt: '01/10/2026',
};
const groupsPage = groups => render('Pages/ServiceGroups.vue', {
    subscription: { name: 'Netflix', slug: 'netflix' }, groups,
    canLogin: true, canRegister: true, isAuthenticated: false,
});

test('service groups are a compact list: owner, verification, places and share first', async () => {
    const html = await groupsPage([group, { ...group, id: 22, ownerName: 'Alex', ownerIdentityStatus: 'pending', spotsAvailable: 1 }]);
    assert.equal((html.match(/class="group-row eq-reveal"/g) ?? []).length, 2);
    assert.ok(html.includes('Avec Camille') && html.includes('Avec Alex'));
    assert.ok(html.includes('Identité vérifiée') && html.includes('Identité non vérifiée'));
    assert.ok(html.includes('2 places libres') && html.includes('1 place libre'));
    assert.ok(html.includes('4 / 6 membres'));
    assert.ok(html.includes('6,00 CAD') && html.includes('votre part par mois'));
    assert.ok(html.includes('--reveal-index:0') && html.includes('--reveal-index:1'));
    // The large decorative artwork is no longer repeated for each group.
    assert.doesNotMatch(html, /card-scene/);
});

test('a full group says so instead of offering places', async () => {
    const html = await groupsPage([{ ...group, spotsAvailable: 0 }]);
    assert.ok(html.includes('Complet'));
    assert.ok(html.includes('6 / 6 membres'));
    assert.doesNotMatch(html, /places? libres?/);
});

test('catalogue cards show the service name on the artwork, once, without any logo', async () => {
    const html = await render('Pages/Services.vue', { categories: [{ id: 1, name: 'Streaming', subscriptions: [
        { id: 1, name: 'Netflix', slug: 'netflix', category: 'Streaming', monthly_price: 2400, currency: 'CAD', max_members: 4 },
        { id: 2, name: 'illico+', slug: 'illico', category: 'Streaming', monthly_price: 1500, currency: 'CAD', max_members: 2 },
    ] }] });
    assert.match(html, /<strong class="scene-title"[^>]*>Netflix<\/strong>/);
    assert.match(html, /<strong class="scene-title"[^>]*>illico\+<\/strong>/);
    assert.match(html, /<h3 class="sr-only"[^>]*>Netflix<\/h3>/, 'Name kept for screen readers');
    assert.equal((html.match(/eq-reveal/g) ?? []).length >= 2, true);
    assert.doesNotMatch(html, /<img\b|Images\/services/);
    assert.ok(html.includes('6,00 CAD') && html.includes('7,50 CAD'));
});

test('decorative hero artwork keeps its tagline and never shows a service name', async () => {
    const html = await render('Components/Experience/ServiceArtwork.vue', {
        scene: 'cinema', slug: 'netflix', category: 'LE GRAND ÉCRAN', tagline: 'Les bonnes histoires se partagent.', motion: false,
    });
    assert.ok(html.includes('Les bonnes histoires se partagent.'));
    assert.doesNotMatch(html, /scene-title|has-title/);
});

test('home explains the service and repeats the trust arguments of the ads', async () => {
    const html = await render('Pages/Welcome.vue', { openGroups: [], catalogServices: [], canLogin: true, canRegister: true, isAuthenticated: false });
    assert.ok(html.includes('Partagez le coût de vos abonnements entre proches.'));
    assert.ok(html.includes('reçoit l&#39;argent') || html.includes("reçoit l'argent"));
    for (const text of ['Paiements sécurisés par Stripe', 'Responsables à l&#39;identité vérifiée', 'Remboursé si aucun accès sous 48 h']) {
        assert.ok(html.includes(text) || html.includes(text.replace('&#39;', "'")), text);
    }
    assert.ok(html.includes('hero-description eq-reveal') && html.includes('hero-trust eq-reveal'));
});

function fakeBrowser({ reduced }) {
    const added = [];
    const observed = [];
    let callback;
    const element = { classList: { add: name => added.push(name) } };
    globalThis.window = { matchMedia: () => ({ matches: reduced }) };
    globalThis.document = {
        documentElement: { classList: { add: name => added.push('html:' + name) } },
        body: {},
        querySelectorAll: () => [element],
    };
    globalThis.IntersectionObserver = class { constructor(cb) { callback = cb; } observe(el) { observed.push(el); } unobserve() {} };
    globalThis.MutationObserver = class { observe() {} };
    return { added, observed, element, intersect: () => callback([{ isIntersecting: true, target: element }]) };
}

test('reduced motion: nothing is hidden or animated', () => {
    const browser = fakeBrowser({ reduced: true });
    installReveal();
    assert.deepEqual(browser.added, []);
    assert.deepEqual(browser.observed, []);
});

test('with motion: content is hidden only until it enters the screen', () => {
    const browser = fakeBrowser({ reduced: false });
    installReveal();
    assert.deepEqual(browser.added, ['html:eq-reveal-ready']);
    assert.deepEqual(browser.observed, [browser.element]);
    browser.intersect();
    assert.deepEqual(browser.added, ['html:eq-reveal-ready', 'is-visible']);
});

test('the global stylesheet only hides elements while the reveal is armed, never with reduced motion', () => {
    const css = readFileSync(new URL('../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /\.eq-reveal-ready \.eq-reveal:not\(\.is-visible\) \{\s*opacity: 0;/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\) \{\s*\.eq-reveal-ready \.eq-reveal:not\(\.is-visible\),\s*\.eq-reveal-ready \.eq-reveal\.is-visible \{\s*opacity: 1;\s*animation: none;/);
});
