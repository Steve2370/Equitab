// Offline rendering checks. Optional --preview serves these same synthetic
// fixtures with the real application layouts; no Laravel bootstrap or .env.
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as money from '../resources/js/utils/money.ts';
import * as presentation from '../resources/js/config/servicePresentation.ts';

const root = fileURLToPath(new URL('../', import.meta.url));
const require = createRequire(import.meta.url);
const components = new Map();
const layout = defineComponent({ setup: (_, { slots }) => () => h('main', slots.default?.()) });
const empty = defineComponent({ setup: () => () => null });
function component(relative) {
    const path = resolve(root, relative);
    if (components.has(path)) return components.get(path);
    const { descriptor, errors } = parse(readFileSync(path, 'utf8'));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { ssr: true } });
    const compiled = ts.transpileModule(script.content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const module = { exports: {} };
    vm.runInNewContext(compiled, {
        exports: module.exports,
        require(name) {
            if (name === 'vue' || name === 'vue/server-renderer' || name === 'lucide-vue-next') return require(name);
            if (name === '@/utils/money') return money;
            if (name === '@/config/servicePresentation') return presentation;
            if (name === '@/composables/useToast') return { useToast: () => ({ success() {}, error() {} }) };
            if (name === '@inertiajs/vue3') return {
                Head: empty,
                Link: defineComponent({ props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
                router: { patch() { throw new Error('Mutations forbidden in render tests'); } },
            };
            if (name.includes('/Layouts/')) return { __esModule: true, default: layout };
            if (/ExperienceDialog|CredentialsModal|ServiceArtwork|ServiceBrandMark/.test(name)) return { __esModule: true, default: empty };
            if (name.endsWith('.vue')) return { __esModule: true, default: component(name.startsWith('@/') ? 'resources/js/' + name.slice(2) : resolve(dirname(path), name)) };
            if (name.endsWith('/adminPresentation')) {
                const source = readFileSync(resolve(root, 'resources/js/Components/Admin/adminPresentation.ts'), 'utf8');
                const result = { exports: {} };
                vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports: result.exports });
                return result.exports;
            }
            throw new Error(`Unexpected dependency: ${name}`);
        },
    });
    components.set(path, module.exports.default);
    return module.exports.default;
}

const page = (data) => ({ data, current_page: 1, last_page: 1, total: data.length, from: data.length ? 1 : null, to: data.length || null, links: [] });
function fixtures({ empty: isEmpty = false, long = false } = {}) {
    const amount = long ? 98765432100 : 1000;
    const payments = isEmpty ? [] : ['CAD', 'EUR'].map((currency, i) => ({
        id: i + 1, userName: 'Membre fictif', userEmail: 'membre@example.test', groupName: 'Groupe fictif',
        subscriptionName: 'Netflix', amount, equitabFee: i ? null : 37, currency,
        paidAt: i ? null : '07 oct. 2026', dueDate: '07 oct. 2026', status: 'completed',
    }));
    const totals = payments.map((p) => ({ currency: p.currency, totalRevenue: p.amount, totalPayments: 1, equitabEarnings: p.equitabFee ?? 0 }));
    const group = {
        id: 2, name: 'Groupe fictif EUR', subscriptionName: 'Netflix', subscriptionSlug: 'netflix',
        ownerName: 'Propriétaire fictif', ownerEmail: 'owner@example.test', status: 'open', visibility: 'public',
        currency: 'EUR', totalPrice: amount, pricePerMember: amount, membersCount: 2, maxMembers: 4,
        createdAt: '07 oct. 2026', joinedAt: null, renewalDate: null, inviteLink: null, spotsLeft: 2, members: [],
    };
    const groups = isEmpty ? [] : [group];
    return {
        '/admin': { component: 'Admin/Index', props: { stats: { totalUsers: 2, totalGroups: groups.length, activeGroups: groups.length, totalPayments: payments.length, paymentTotalsByCurrency: totals, openDisputes: 0, verifiedUsers: 2 } } },
        '/admin/payments': { component: 'Admin/Payments', props: { payments: page(payments), paymentTotalsByCurrency: totals } },
        '/admin/groups': { component: 'Admin/Groups', props: { groups: page(groups) } },
        '/admin/disputes': { component: 'Admin/Disputes', props: { disputes: page(isEmpty ? [] : [
            { id: 1, userName: 'Membre fictif', userEmail: 'membre@example.test', groupName: 'Groupe archivé', subscriptionName: 'Netflix', reason: 'no_access', description: 'Données fictives', status: 'open', amount, currency: 'EUR', adminNotes: null, createdAt: '07 oct. 2026' },
            { id: 2, userName: 'Utilisateur supprimé', userEmail: '—', groupName: 'Groupe indisponible', subscriptionName: 'Service indisponible', reason: 'other', description: '', status: 'under_review', amount: null, currency: null, adminNotes: null, createdAt: '07 oct. 2026' },
        ]) } },
        '/dashboard': { component: 'Dashboard/Index', props: {
            userName: 'Camille Démo', activeSubscriptionsCount: payments.length,
            monthlyTotalsByCurrency: totals.map((p) => ({ currency: p.currency, monthlySpend: p.totalRevenue, totalSavings: p.currency === 'EUR' ? null : 1900, unavailableSavingsCount: p.currency === 'EUR' ? 1 : 0 })),
            upcomingPayments: payments.map((p) => ({ ...p, status: 'pending', paidAt: null })),
        } },
        '/dashboard/payments': { component: 'Dashboard/Payments', props: { payments: page(payments), paidTotalsByCurrency: payments.map((p) => ({ currency: p.currency, amount: p.amount })) } },
        '/dashboard/subscriptions': { component: 'Dashboard/Subscriptions', props: { joinedSubscriptions: groups, ownedSubscriptions: groups, drafts: [] } },
    };
}
async function render(route, overrides = {}, options = {}) {
    const fixture = fixtures(options)[route];
    const app = createSSRApp(component(`resources/js/Pages/${fixture.component}.vue`), { ...fixture.props, ...overrides });
    app.config.warnHandler = (message) => { throw new Error(message); };
    return (await renderToString(app)).replaceAll('\u00a0', ' ').replaceAll('\u202f', ' ');
}

if (process.argv.includes('--preview')) {
    const { createServer } = await import('node:http');
    const { createServer: createViteServer } = await import('vite');
    const { default: vue } = await import('@vitejs/plugin-vue');
    const { default: tailwindcss } = await import('@tailwindcss/vite');
    const port = Number(process.env.EQUITAB_REPORTS_PREVIEW_PORT || 4187);
    const vite = await createViteServer({
        configFile: false, envFile: false, root,
        cacheDir: mkdtempSync('/private/tmp/equitab-reports-vite-'),
        plugins: [vue(), tailwindcss()], resolve: { alias: { '@': resolve(root, 'resources/js') } },
        server: { host: '127.0.0.1', middlewareMode: true, hmr: { host: '127.0.0.1', port: port + 1 }, fs: { allow: [root] } }, appType: 'custom',
    });
    const server = createServer((req, res) => {
        if (!['GET', 'HEAD'].includes(req.method)) { res.writeHead(405); res.end('Aperçu sans mutations'); return; }
        res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'");
        vite.middlewares(req, res, async () => {
            const url = new URL(req.url, `http://127.0.0.1:${port}`);
            const fixture = fixtures({ empty: url.searchParams.has('empty'), long: url.searchParams.has('long') })[url.pathname];
            if (!fixture) { res.writeHead(404); res.end('Aperçu rapports uniquement'); return; }
            const data = {
                ...fixture, props: { auth: { user: { name: 'Camille Démo', email: 'demo@example.test', avatar: null, identity_status: 'verified' } }, isAdmin: true, flash: {}, ...fixture.props, initialTab: url.searchParams.get('tab') === 'owned' ? 'owned' : 'joined' },
                url: url.pathname + url.search, version: 'currency-reports-preview', clearHistory: false, encryptHistory: false,
            };
            res.setHeader('Cache-Control', 'no-store');
            if (req.headers['x-inertia']) { res.writeHead(200, { 'Content-Type': 'application/json', 'X-Inertia': 'true' }); res.end(JSON.stringify(data)); return; }
            const encoded = JSON.stringify(data).replaceAll('&', '&amp;').replaceAll("'", '&#39;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
            const html = await vite.transformIndexHtml(url.pathname, `<!doctype html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rapports fictifs CAD/EUR</title></head><body><div id="app" data-page='${encoded}'></div><script type="module" src="/resources/js/design-preview.ts"></script></body></html>`);
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html);
        });
    });
    server.listen(port, '127.0.0.1', () => console.log(`Aperçu rapports : http://127.0.0.1:${port}/dashboard`));
    for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => { await vite.close(); server.close(); });
} else {
    test('admin volumes and commissions display separate native currencies', async () => {
        const html = await render('/admin');
        assert.ok(html.includes('10,00 CAD'));
        assert.ok(html.includes('10,00 EUR'));
        assert.ok(!html.includes('20,00'));
        assert.ok(html.includes('0,37 CAD'));
        assert.ok(html.includes('0,00 EUR'));
    });
    test('admin payment rows preserve missing fees and native amount currency', async () => {
        const html = await render('/admin/payments');
        assert.ok(html.includes('10,00 CAD'));
        assert.ok(html.includes('10,00 EUR'));
        assert.ok(html.includes('Montant indisponible'));
        assert.ok(!html.includes('dollars canadiens'));
    });
    test('admin groups and disputes use their group and historical payment currencies', async () => {
        for (const route of ['/admin/groups', '/admin/disputes']) {
            const html = await render(route);
            assert.ok(html.includes('10,00 EUR'));
            assert.ok(!html.includes('10,00 CAD'));
        }
        assert.ok((await render('/admin/disputes')).includes('Montant indisponible'));
    });
    test('dashboard shows separate budgets and explains unavailable catalogue comparisons', async () => {
        const html = await render('/dashboard');
        for (const text of ['19,00 CAD', '10,00 CAD', '10,00 EUR', 'Indisponibles', 'Comparaison catalogue indisponible']) assert.ok(html.includes(text), text);
        assert.ok(!html.includes('19,00 EUR'));
    });
    test('member history consumes the server page subtotals without adding payment rows', async () => {
        const html = await render('/dashboard/payments', { paidTotalsByCurrency: [{ currency: 'EUR', amount: 12345 }] });
        assert.ok(html.includes('123,45 EUR'));
        assert.ok(html.includes('10,00 CAD'));
        assert.ok(html.includes('10,00 EUR'));
        assert.ok(!html.includes('20,00'));
    });
    test('joined and owned collection cards receive the explicit group currency', async () => {
        for (const initialTab of ['joined', 'owned']) {
            const html = await render('/dashboard/subscriptions', { initialTab });
            assert.ok(html.includes('10,00 EUR'));
            assert.ok(!html.includes('10,00 CAD'));
        }
    });
    test('empty reports do not fabricate a zero CAD total', async () => {
        for (const route of Object.keys(fixtures())) {
            const html = await render(route, {}, { empty: true });
            assert.ok(!html.includes('0,00 CAD'), route);
            assert.ok(!html.includes('undefined'), route);
        }
    });
}
