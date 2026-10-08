// Actual Vue SFCs/composable, in-memory renderer and controlled browser/HTTP/time boundaries.
// No app environment, database, network, storage or Stripe SDK is loaded.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createRenderer, defineComponent, h, nextTick, reactive, ref } from 'vue';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = fileURLToPath(new URL('../../resources/js/', import.meta.url));
export const deferred = () => {
    let resolve, reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
};
export const response = (data, status = 200, options = {}) => ({
    ok: status >= 200 && status < 300, status, redirected: false,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => data, ...options,
});
export async function flush() {
    for (let i = 0; i < 16; i++) await nextTick();
}
export function clock() {
    let now = 0, nextId = 0;
    const tasks = new Map();
    return {
        get now() { return now; },
        get size() { return tasks.size; },
        elapseWithoutTimers(ms) { now += ms; },
        setTimeout(callback, delay = 0) { const id = ++nextId; tasks.set(id, { at: now + delay, callback }); return id; },
        clearTimeout(id) { tasks.delete(id); },
        async advance(ms) {
            const until = now + ms;
            await flush();
            let iterations = 0;
            while (true) {
                const next = [...tasks].sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
                if (!next || next[1].at > until) break;
                assert.ok(++iterations < 1000, 'Unexpected infinite timer loop');
                now = next[1].at;
                tasks.delete(next[0]);
                next[1].callback();
                await flush();
            }
            now = until;
            await flush();
        },
    };
}
export const textOf = node => node.type === '#comment' ? '' : node.text + node.children.map(textOf).join('');
const walk = node => [node, ...node.children.flatMap(walk)];

export function mount(relative, initialProps, options = {}) {
    const time = clock(), calls = [], logs = [], copied = [], forbidden = [];
    const doc = { body: { style: { overflow: 'auto' } }, activeElement: null };
    const trigger = { focus() { doc.activeElement = trigger; } };
    trigger.focus();
    const element = (type, text = '') => {
        const node = { type, text, children: [], props: {}, parent: null, open: false };
        node.focus = () => { doc.activeElement = node; };
        node.showModal = () => { node.open = true; (walk(node).find(n => n.props.autofocus !== undefined) ?? node).focus(); };
        node.close = () => { node.open = false; };
        return node;
    };
    const remove = node => {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        node.parent = null;
    };
    const renderer = createRenderer({
        createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
        setText: (node, text) => { node.text = text; },
        setElementText: (node, text) => { node.text = text; node.children = []; },
        parentNode: node => node.parent,
        nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
        patchProp: (node, key, _previous, value) => { node.props[key] = value; },
        insert(node, parent, anchor = null) {
            remove(node);
            const index = anchor ? parent.children.indexOf(anchor) : parent.children.length;
            assert.ok(index >= 0);
            parent.children.splice(index, 0, node);
            node.parent = parent;
        }, remove,
    });
    const boundaryDenied = name => { forbidden.push(name); throw new Error(`Forbidden boundary: ${name}`); };
    const storage = new Proxy({}, { get: () => boundaryDenied('storage') });
    const cache = new Map();
    function load(path) {
        if (cache.has(path)) return cache.get(path);
        let source = readFileSync(path, 'utf8');
        if (extname(path) === '.vue') {
            const { descriptor, errors } = parse(source, { filename: path });
            assert.deepEqual(errors, []);
            source = compileScript(descriptor, { id: path, inlineTemplate: true,
                templateOptions: { compilerOptions: { hoistStatic: false } } }).content;
        }
        const compiled = ts.transpileModule(source, {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
        }).outputText;
        const module = { exports: {} };
        vm.runInNewContext(compiled, {
            exports: module.exports, URL, AbortController, performance: { now: () => time.now },
            setTimeout: time.setTimeout, clearTimeout: time.clearTimeout,
            document: doc, localStorage: storage, sessionStorage: storage,
            window: { location: { href: 'https://offline.test/payment/success?group_id=15&status=succeeded' },
                open: () => boundaryDenied('auto-open'), localStorage: storage, sessionStorage: storage,
                Stripe: () => boundaryDenied('Stripe') },
            navigator: {
                sendBeacon: () => boundaryDenied('telemetry'),
                clipboard: { writeText: async value => { copied.push(value); return options.clipboard?.(value); } },
            },
            console: Object.fromEntries(['log', 'warn', 'error', 'info', 'debug', 'table'].map(name => [name, (...args) => logs.push(args)])),
            fetch: async (url, init) => {
                assert.match(url, /^\/api\/groups\/\d+\/service-access$/);
                calls.push({ url, init, at: time.now });
                return options.fetch ? options.fetch(url, init, calls.length) : response({ status: 'unavailable', mode: 'credentials', credentials: null, invitation: null });
            },
            require(name) {
                if (name === 'vue' || name === 'lucide-vue-next') return require(name);
                if (name === '@inertiajs/vue3') return {
                    Head: defineComponent({ props: ['title'], setup: props => () => h('head-state', { title: props.title }) }),
                    Link: defineComponent({ props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
                };
                if (name.startsWith('@/') || name.startsWith('.')) {
                    const target = name.startsWith('@/') ? resolve(root, name.slice(2)) : resolve(dirname(path), name);
                    return load(extname(target) ? target : `${target}.ts`);
                }
                throw new Error(`Unexpected dependency: ${name}`);
            },
        });
        cache.set(path, module.exports);
        return module.exports;
    }
    const component = load(resolve(root, relative)).default;
    const props = reactive({ ...initialProps }), visible = ref(true);
    const closeListener = relative.endsWith('CredentialsModal.vue')
        ? { onClose: () => { visible.value = false; options.onClose?.(); } } : {};
    const app = renderer.createApp(defineComponent({ setup: () => () => visible.value
        ? h(component, { ...props, ...closeListener }) : null }));
    app.config.warnHandler = message => { throw new Error(message); };
    const container = element('root');
    app.mount(container);
    return {
        app, props, time, calls, logs, copied, forbidden, container, doc, trigger,
        text: () => textOf(container), nodes: () => walk(container),
        title: () => walk(container).find(node => node.type === 'head-state')?.props.title,
        find: predicate => { const found = walk(container).find(predicate); assert.ok(found, 'Expected rendered element'); return found; },
        async update(values) { Object.assign(props, values); await flush(); },
        unmount() { app.unmount(); },
    };
}
