import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp, createRenderer, h, nextTick } from 'vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as presentation from '../resources/js/config/servicePresentation.ts';

// Compile the actual component; no substitute for the logo lookup or template.
const path = '../resources/js/Components/Experience/ServiceBrandMark.vue';
const { descriptor, errors } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { ssr: true } });
const compiled = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const require = createRequire(import.meta.url);
const module = { exports: {} };
vm.runInNewContext(compiled, {
    exports: module.exports,
    require(name) {
        if (name === 'vue' || name === 'vue/server-renderer') return require(name);
        if (name === '@/config/servicePresentation') return presentation;
        throw new Error(`Unexpected dependency: ${name}`);
    },
});
function render(props) {
    const app = createSSRApp(module.exports.default, props);
    app.config.warnHandler = (message) => { throw new Error(message); };
    return renderToString(app);
}

for (const [slug, name, file] of [
    ['crave', 'Crave', 'crave.jpg'],
    ['microsoft-365', 'Microsoft 365', 'microsoft365.svg'],
    ['readly', 'Readly', 'readly.png'],
]) {
    test(`${name} renders its supplied image in a wide badge, including dashboard name lookup`, async () => {
        for (const lookup of [slug, name]) {
            const html = await render({ slug: lookup, name });
            assert.match(html, new RegExp(`aria-label="${name}"`));
            assert.ok(html.includes('role="img"'));
            assert.ok(html.includes('service-brand-wide'));
            assert.equal((html.match(/<img\b/g) ?? []).length, 1);
            assert.ok(html.includes(`src="/Images/services/${file}"`));
            assert.ok(html.includes('alt=""'), 'The badge label supplies the accessible name');
        }
    });
}
test('unknown services keep an escaped name instead of a broken or invented logo', async () => {
    const html = await render({ slug: 'nouveau-service', name: '<script>synthetic()</script>' });
    assert.ok(!html.includes('<img'));
    assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('&lt;script&gt;synthetic()&lt;/script&gt;'));
});

const preparedServices = [
    ['dropbox-family', 'Dropbox Family', 'dropbox', 'dropbox.svg'],
    ['bitwarden-families', 'Bitwarden Families', 'bitwarden', 'bitwarden.svg'],
    ['nordpass-family', 'NordPass Family', 'nordpass', 'nordpass.png'],
];

for (const [slug, name, alias, file] of preparedServices) {
    test(`${name} reuses the wide brand badge with a local image and accessible label`, async () => {
        for (const lookup of [slug, name, alias]) {
            const html = await render({ slug: lookup, name: '<script>untrusted()</script>' });
            assert.ok(html.includes(`aria-label="${name}"`));
            assert.ok(html.includes('role="img"'));
            assert.ok(html.includes('service-brand-wide'));
            assert.equal((html.match(/<img\b/g) ?? []).length, 1);
            assert.ok(html.includes(`src="/Images/services/${file}"`));
            assert.ok(html.includes('alt=""'), 'The labelled wrapper supplies the name');
            assert.ok(html.includes('scale(1)'), 'Keep the complete mark visible');
            assert.ok(!html.includes('untrusted'));
        }
    });
}

test('all 22 previous identities retain their names and rendered logo sources', async () => {
    const legacy = [
        ['netflix', 'Netflix', 'netflix.svg'],
        ['disney', 'Disney+', 'Disney+.png'],
        ['youtube-premium', 'YouTube Premium', 'youtube.svg'],
        ['crave', 'Crave', 'crave.jpg'],
        ['crunchyroll', 'Crunchyroll', 'crunchyroll.svg'],
        ['paramount', 'Paramount+', 'paramountplus.svg'],
        ['canal', 'CANAL+', 'canal.png'],
        ['amazon-prime', 'Amazon Prime', 'amazonprime.svg'],
        ['spotify', 'Spotify', 'spotify.svg'],
        ['apple-music', 'Apple Music', 'applemusic.svg'],
        ['deezer', 'Deezer', 'deezer-logo.png'],
        ['tidal', 'TIDAL', 'tidal.svg'],
        ['xbox-game-pass', 'Xbox Game Pass', 'Xbox_Game_Pass_2020_logo_-_colored_version.svg.webp'],
        ['nintendo', 'Nintendo', 'nintendo.svg'],
        ['nordvpn', 'NordVPN', 'nordvpn.svg'],
        ['cyberghost', 'CyberGhost', 'cyberghost.png'],
        ['envato', 'Envato', 'envato.svg'],
        ['google-one', 'Google One', 'google.svg'],
        ['microsoft-365', 'Microsoft 365', 'microsoft365.svg'],
        ['apple-one-family', 'Apple One', 'apple.svg'],
        ['duolingo', 'Duolingo', 'duolingo.svg'],
        ['readly', 'Readly', 'readly.png'],
    ];
    assert.equal(legacy.length, 22);
    for (const [slug, name, file] of legacy) {
        const html = await render({ slug });
        assert.ok(html.includes(`aria-label="${name}"`), slug);
        assert.ok(html.includes(`src="/Images/services/${file}"`), slug);
        assert.equal((html.match(/<img\b/g) ?? []).length, 1, slug);
    }
});

// Exercise the real client error handler and watcher using Vue's in-memory host.
// No DOM package, HTTP server, database, mail or external account is involved.
const clientScript = compileScript(descriptor, { id: path, inlineTemplate: true });
const clientModule = { exports: {} };
vm.runInNewContext(ts.transpileModule(clientScript.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, {
    exports: clientModule.exports,
    require(name) {
        if (name === 'vue') return require(name);
        if (name === '@/config/servicePresentation') return presentation;
        throw new Error(`Unexpected dependency: ${name}`);
    },
});

function memoryHost() {
    const node = (tag, text = '') => ({ tag, text, props: {}, children: [], parent: null });
    const remove = (child) => {
        if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1);
        child.parent = null;
    };
    const renderer = createRenderer({
        createElement: node,
        createText: (text) => node('#text', text),
        createComment: (text) => node('#comment', text),
        setText: (target, text) => { target.text = text; },
        setElementText: (target, text) => { target.text = text; target.children = []; },
        patchProp: (target, key, previous, next) => { target.props[key] = next; },
        parentNode: (target) => target.parent,
        nextSibling: (target) => target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
        insert(child, parent, anchor = null) {
            remove(child);
            parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child);
            child.parent = parent;
        },
        remove,
    });
    const root = node('root');
    const descendants = (target = root) => [target, ...target.children.flatMap(descendants)];
    return { renderer, root, descendants };
}

for (const [slug, name] of preparedServices) {
    test(`${name} falls back after a failed image and retries when the service changes`, async () => {
        const { renderer, root, descendants } = memoryHost();
        renderer.render(h(clientModule.exports.default, { slug }), root);
        try {
            const image = descendants().find(({ tag }) => tag === 'img');
            assert.ok(image);
            assert.equal(image.props.alt, '');
            assert.equal(typeof image.props.onError, 'function');
            image.props.onError();
            await nextTick();
            assert.ok(!descendants().some(({ tag }) => tag === 'img'));
            assert.ok(descendants().some(({ text }) => text === name));
            assert.equal(root.children[0].props['aria-label'], name);

            renderer.render(h(clientModule.exports.default, { slug: 'spotify' }), root);
            await nextTick();
            assert.equal(descendants().find(({ tag }) => tag === 'img')?.props.src, '/Images/services/spotify.svg');
            assert.equal(root.children[0].props['aria-label'], 'Spotify');

            renderer.render(h(clientModule.exports.default, { slug }), root);
            await nextTick();
            assert.equal(descendants().find(({ tag }) => tag === 'img')?.props.src,
                presentation.serviceLogoSource(presentation.serviceBrand(slug)));
        } finally {
            renderer.render(null, root);
        }
    });
}
