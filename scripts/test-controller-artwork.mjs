import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as presentation from '../resources/js/config/servicePresentation.ts';

const require = createRequire(import.meta.url);
const compiled = new Map();
function component(name) {
    if (compiled.has(name)) return compiled.get(name);
    const { descriptor, errors } = parse(readFileSync(new URL(`../resources/js/Components/Experience/${name}.vue`, import.meta.url), 'utf8'));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: name, inlineTemplate: true, templateOptions: { ssr: true } });
    const module = { exports: {} };
    vm.runInNewContext(ts.transpileModule(script.content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, {
        exports: module.exports,
        require(dependency) {
            if (dependency === 'vue' || dependency === 'vue/server-renderer') return require(dependency);
            if (dependency === '@/config/servicePresentation') return presentation;
            if (/^\.\/(XboxControllerArtwork|ServiceBrandMark)\.vue$/.test(dependency)) {
                return { __esModule: true, default: component(dependency.slice(2, -4)) };
            }
            throw new Error(`Unexpected artwork dependency: ${dependency}`);
        },
    });
    compiled.set(name, module.exports.default);
    return module.exports.default;
}
async function render(name, props = {}, count = 1) {
    const app = createSSRApp({ render: () => h('div', Array.from({ length: count }, () => h(component(name), props))) });
    app.config.warnHandler = (message) => { throw new Error(message); };
    return renderToString(app);
}

test('Xbox-style artwork has offset sticks, a directional pad and all four face buttons', async () => {
    const html = await render('XboxControllerArtwork');
    assert.match(html, /controller-stick-left" transform="translate\(87 78\)"/);
    assert.match(html, /controller-stick-right" transform="translate\(196 127\)"/);
    assert.match(html, /class="controller-dpad"/);
    for (const label of ['A', 'B', 'X', 'Y']) assert.match(html, new RegExp(`>${label}</text>`));
    assert.match(html, /viewBox="0 0 320 220"/i);
    assert.match(html, /aria-hidden="true"/);
    assert.match(html, /focusable="false"/);
    assert.doesNotMatch(html, /<image\b|<script\b|https?:/);
});

test('multiple cards have unique paint IDs and every paint reference resolves', async () => {
    const html = await render('XboxControllerArtwork', {}, 4);
    const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map((match) => match[1]);
    assert.equal(ids.length, 12);
    assert.equal(new Set(ids).size, ids.length);
    for (const [, reference] of html.matchAll(/url\(#([^)]+)\)/g)) assert.ok(ids.includes(reference));
});

for (const slug of ['xbox-game-pass', 'nintendo', 'jeu-inconnu']) {
    test(`gaming card ${slug} uses the controller without replacing its own brand`, async () => {
        const html = await render('ServiceArtwork', { scene: 'arcade', slug, category: 'Jeux vidéo', tagline: 'À plusieurs.', motion: false });
        assert.match(html, /class="xbox-controller"/);
        assert.doesNotMatch(html, /class="game-cross"|class="game-buttons"|motion-enabled/);
        assert.ok(html.includes('Jeux vidéo') && html.includes('À plusieurs.'));
        const brand = presentation.serviceBrand(slug);
        if (brand) {
            assert.ok(html.includes(`aria-label="${brand.name}"`));
            assert.ok(html.includes(`--service-accent:${brand.accent}`));
        }
    });
}

test('the other artwork scenes remain unchanged and contain no controller', async () => {
    for (const scene of ['music', 'cinema', 'world', 'cloud', 'play', 'shield', 'book']) {
        const html = await render('ServiceArtwork', { scene, category: 'Démo', tagline: 'Ensemble.', motion: true });
        assert.doesNotMatch(html, /xbox-controller|class="gamepad"/);
        assert.ok(html.includes('motion-enabled'));
    }
});
