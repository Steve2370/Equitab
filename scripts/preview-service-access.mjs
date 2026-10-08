// Local synthetic recipe only. No Laravel, app .env, database, Stripe, email or outbound transport.
// Run: node scripts/preview-service-access.mjs
import { createServer } from 'node:http';
import { mkdtempSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { createServer as createViteServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const root = fileURLToPath(new URL('../', import.meta.url));
const port = 4297;
const entry = 'virtual:service-access-preview';
const requests = new Map();
const empty = (status, mode = 'credentials') => ({ status, mode, credentials: null, invitation: null });
const credentials = (long = false) => ({ status: 'ready', mode: 'credentials', invitation: null,
    credentials: {
        email: long ? `${'identifiant-synthetique-'.repeat(8)}@example.test` : 'membre-fictif@example.test',
        password: long ? 'MOT-DE-PASSE-FICTIF-'.repeat(12) : 'DEMONSTRATION-UNIQUEMENT',
        notes: 'Données fictives : aucun de ces identifiants ne fonctionne sur un service réel.\nVous pouvez tester afficher, masquer et copier.',
    },
});
const invitation = (email = false) => ({ status: 'ready', mode: 'invitation', credentials: null,
    invitation: {
        channel: email ? 'provider_email' : 'link',
        url: email ? null : 'https://service.example.invalid/invitation-demonstration',
        recipient_email: email ? 'destinataire-fictif@example.test' : null,
        provided_at: '2026-10-07T17:00:00Z',
    },
});

const vite = await createViteServer({
    configFile: false, root, envFile: false, envPrefix: '__EQUITAB_PREVIEW_UNUSED_',
    envDir: mkdtempSync('/private/tmp/equitab-access-preview-'),
    plugins: [vue(), tailwindcss(), {
        name: 'isolated-service-access-preview',
        resolveId(id) { if (id === entry) return '\0' + entry; },
        load(id) {
            if (id !== '\0' + entry) return;
            return `
                import '/resources/css/app.css';
                import { createApp, h } from 'vue';
                import { createInertiaApp } from '@inertiajs/vue3';
                import Preview from '/scripts/fixtures/ServiceAccessPreview.vue';
                const localFetch = window.fetch.bind(window);
                window.fetch = (input, init) => {
                    const url = new URL(typeof input === 'string' ? input : input.url ?? input.toString(), window.location.origin);
                    if (url.origin !== window.location.origin || !/^\\/api\\/groups\\/\\d+\\/service-access$/.test(url.pathname)) {
                        return Promise.reject(new Error('Requête non autorisée dans la recette isolée.'));
                    }
                    return localFetch(input, init);
                };
                // Keep the real invitation anchor visible, but never navigate out of the fixture.
                document.addEventListener('click', (event) => {
                    const anchor = event.target instanceof Element ? event.target.closest('a') : null;
                    if (!anchor) return;
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    window.dispatchEvent(new Event('service-access-preview-navigation'));
                }, true);
                createInertiaApp({ page: { component: 'Preview', props: { auth: { user: null } }, url: '/', version: 'synthetic' },
                    resolve: () => Preview,
                    setup({ el, App, props, plugin }) { createApp({ render: () => h(App, props) }).use(plugin).mount(el); }
                });
            `;
        },
    }],
    resolve: { alias: { '@': resolve(root, 'resources/js') } },
    server: { host: '127.0.0.1', middlewareMode: true,
        hmr: { port: port + 1, host: '127.0.0.1' }, fs: { allow: [root] } },
    appType: 'custom',
});

const server = createServer((req, res) => {
    if (req.headers.host !== `127.0.0.1:${port}` && req.headers.host !== `localhost:${port}`) {
        res.writeHead(403).end('Local preview only');
        return;
    }
    res.setHeader('Content-Security-Policy', `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' ws://127.0.0.1:${port + 1}; font-src 'self'; object-src 'none'; frame-src 'none'; frame-ancestors 'none'; form-action 'none'; base-uri 'self'`);
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('Cache-Control', 'no-store');
    const path = new URL(req.url ?? '/', `http://127.0.0.1:${port}`).pathname;
    const match = /^\/api\/groups\/(\d+)\/service-access$/.exec(path);
    if (match && req.method === 'GET') {
        const groupId = Number(match[1]);
        const scenario = Math.floor(groupId / 100_000);
        const count = (requests.get(groupId) ?? 0) + 1;
        if (requests.size > 500) requests.clear();
        requests.set(groupId, count);
        let body, status = 200;
        switch (scenario) {
            case 1: body = count === 1 ? empty('payment_pending') : credentials(); break;
            case 2: body = count === 1 ? empty('payment_pending', 'invitation') : invitation(); break;
            case 3: body = count === 1 ? empty('payment_pending', 'invitation') : invitation(true); break;
            case 4: body = empty('payment_pending'); break;
            case 5: body = count === 1 ? empty('awaiting_owner', 'invitation') : invitation(); break;
            case 6: body = count === 1 ? empty('awaiting_owner', 'invitation') : invitation(true); break;
            case 7: status = 403; body = { message: 'Refus synthétique' }; break;
            case 8: body = empty('unavailable'); break;
            case 9: status = 503; body = { message: 'Indisponibilité synthétique' }; break;
            case 10: return; // Client abort at 10 seconds: no persistent job or remote call.
            case 11: body = credentials(); break;
            case 12: body = invitation(); break;
            case 13: body = invitation(true); break;
            case 14: body = credentials(true); break;
            default: status = 404; body = { message: 'Scénario inconnu' };
        }
        res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(body));
    } else if (path.startsWith('/api/')) {
        res.writeHead(404).end('Synthetic GET API only');
    } else if (path === '/' && req.method === 'GET') {
        vite.transformIndexHtml('/', '<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recette accès isolée</title></head><body><div id="app"></div><script type="module" src="/@id/__x00__virtual:service-access-preview"></script></body></html>')
            .then(html => { res.setHeader('Content-Type', 'text/html'); res.end(html); })
            .catch(() => { res.statusCode = 500; res.end('Preview failed'); });
    } else vite.middlewares(req, res, () => { res.statusCode = 404; res.end('Not found'); });
});
server.listen(port, '127.0.0.1', () => console.log(`Synthetic access preview: http://127.0.0.1:${port}`));
async function close() { server.closeAllConnections(); server.close(); await vite.close(); }
process.on('SIGINT', close);
process.on('SIGTERM', close);
