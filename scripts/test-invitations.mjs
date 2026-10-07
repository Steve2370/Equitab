import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as drafts from '../resources/js/utils/groupDraft.ts';

const require = createRequire(import.meta.url);
function component(path) {
    const { descriptor, errors } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { ssr: true } });
    const compiled = ts.transpileModule(script.content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const module = { exports: {} };
    vm.runInNewContext(compiled, {
        exports: module.exports,
        require(name) {
            if (name === 'vue' || name === 'vue/server-renderer') return require(name);
            if (name === '@inertiajs/vue3') return {
                Head: defineComponent({ setup: () => () => null }),
                Link: defineComponent({ props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
            };
            if (name === 'lucide-vue-next') return new Proxy({}, { get: () => defineComponent({ setup: () => () => h('svg') }) });
            if (name === '@/config/brandGradients') return { getBrandGradient: () => ({ from: '#187a57', to: '#187a57' }) };
            if (name === '@/utils/groupDraft') return drafts;
            if (name.endsWith('StripeCardForm.vue')) return { __esModule: true, default: defineComponent({ setup: () => () => h('div', { 'data-payment-form': true }) }) };
            if (name.endsWith('ServiceBrandMark.vue')) return { __esModule: true, default: defineComponent({ setup: () => () => h('span') }) };
            if (name.endsWith('EquitabWordmark.vue')) return { __esModule: true, default: defineComponent({ setup: () => () => h('img', { alt: 'EquitAb' }) }) };
            throw new Error(`Unexpected dependency: ${name}`);
        },
    });
    return module.exports.default;
}
const invite = component('../resources/js/Pages/InvitePage.vue');
const preparation = component('../resources/js/Components/Owner/OwnerPreparation.vue');
const preview = component('../resources/js/Components/Owner/OwnerGroupPreview.vue');
const group = {
    id: 15, name: 'Synthetic group', subscriptionName: 'Service test', subscriptionSlug: 'test',
    ownerName: 'Synthetic owner', ownerTrustScore: null, pricePerMember: 500, spotsAvailable: 2, maxMembers: 4,
};
function render(component, props) {
    const app = createSSRApp(component, props);
    app.config.warnHandler = (message) => { throw new Error(message); };
    return renderToString(app);
}
const renderInvite = (accessState, changes = {}) => render(invite, {
    group: { ...group, ...changes }, inviteToken: 'synthetic', continueUrl: '/invite/synthetic/continue', accessState,
});

test('guest receives explicit login and registration actions, never a card form', async () => {
    const html = await renderInvite('guest');
    assert.ok(html.includes('href="/invite/synthetic/continue?auth=register"'));
    assert.ok(html.includes('href="/invite/synthetic/continue?auth=login"'));
    assert.ok(html.includes('alt="EquitAb"'));
    assert.ok(!html.includes('data-payment-form'));
    assert.ok(!html.includes('Continuer vers le paiement'));
});
test('email verification is a separate required action before checkout', async () => {
    const html = await renderInvite('verify_email');
    assert.ok(html.includes('Confirmer mon courriel'));
    assert.ok(html.includes('href="/invite/synthetic/continue"'));
    assert.ok(!html.includes('data-payment-form'));
});
test('full, unavailable and already-admitted states cannot offer another payment', async () => {
    for (const state of ['full', 'unavailable', 'owner', 'member']) {
        const html = await renderInvite(state, { spotsAvailable: 0 });
        assert.ok(!html.includes('Continuer vers le paiement'), state);
        assert.ok(!html.includes('data-payment-form'), state);
        if (state === 'full') assert.ok(html.includes('Ce groupe est complet'));
        if (state === 'owner' || state === 'member') assert.ok(html.includes('Voir dans mon espace'));
    }
});
test('authorized checkout is explicit, including an already-reserved last seat', async () => {
    for (const spotsAvailable of [2, 0]) {
        const html = await renderInvite('checkout', { spotsAvailable });
        assert.ok(html.includes('Continuer vers le paiement'));
        assert.ok(!html.includes('data-payment-form'), 'Rendering a page must not initiate payment');
    }
});
test('unknown trust score stays unknown and arbitrary descriptions are escaped', async () => {
    const html = await renderInvite('guest', { description: '<script>synthetic()</script>' });
    assert.ok(!html.includes('Score de confiance'));
    assert.ok(!html.includes('<script>synthetic()'));
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(!html.includes('Remboursement garanti sous 48h'));
});
test('owner preparation renders exactly two visibility choices in the existing form', async () => {
    const html = await render(preparation, { modelValue: drafts.emptyDraftData(), errors: {}, disabled: false });
    assert.equal((html.match(/type="radio"/g) ?? []).length, 2);
    assert.ok(html.includes('value="public"'));
    assert.ok(html.includes('value="private"'));
    assert.ok(!html.includes('value="invite_only"'));
    assert.ok(html.includes('Privé — sur invitation'));
});
test('legacy draft visibility is normalized without losing its fields or becoming unsaved', async () => {
    const data = { ...drafts.emptyDraftData(), name: 'Saved legacy draft', visibility: 'invite_only' };
    const record = { id: 'fixture', version: 5, status: 'draft', data, updated_at: '2026-10-07T12:00:00Z', published_group_id: null };
    const state = drafts.createDraftState(record);
    assert.equal(state.data.visibility, 'private');
    assert.equal(state.data.name, data.name);
    assert.equal(state.saved.version, 5);
    assert.equal(drafts.isDraftSaved(state), true);
    assert.equal(drafts.draftFingerprint(data), drafts.draftFingerprint({ ...data, visibility: 'private' }));
    const html = await render(preview, { data: state.data });
    assert.ok(html.includes('Privé — sur invitation'));
});
