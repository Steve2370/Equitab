// Local synthetic UI only: no Laravel, .env, database, mail, live SDK or remote request.
// Run: node scripts/preview-eur-interface.mjs
import { createServer } from 'node:http';
import { mkdtempSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { createServer as createViteServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const root = fileURLToPath(new URL('../', import.meta.url));
const port = 4287;
const entry = 'virtual:eur-interface';
const vite = await createViteServer({
    configFile: false, root, envFile: false, envDir: mkdtempSync('/private/tmp/equitab-eur-preview-'),
    plugins: [vue(), tailwindcss(), {
        name: 'isolated-eur-preview',
        resolveId(id) { if (id === entry) return '\0' + entry; },
        load(id) {
            if (id !== '\0' + entry) return;
            return `
                import '/resources/css/app.css';
                import { createApp, h } from 'vue';
                import { createInertiaApp } from '@inertiajs/vue3';
                import Preview from '/scripts/fixtures/EurInterfacePreview.vue';
                window.fetch = async () => { throw new Error('Réseau désactivé dans cette recette'); };
                window.Stripe = () => ({ elements: () => ({ create: () => ({ mount() {}, destroy() {} }) }), createPaymentMethod: async () => ({ error: { message: 'Paiement désactivé dans cette recette.' } }) });
                createInertiaApp({ page: { component: 'Preview', props: { auth: { user: null } }, url: '/', version: 'synthetic' }, resolve: () => Preview, setup({ el, App, props, plugin }) { createApp({ render: () => h(App, props) }).use(plugin).mount(el); } });
            `;
        },
    }],
    resolve: { alias: { '@': resolve(root, 'resources/js') } },
    server: { host: '127.0.0.1', middlewareMode: true, hmr: { port: port + 1, host: '127.0.0.1' }, fs: { allow: [root] } },
    appType: 'custom',
});
const server = createServer((req, res) => {
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' ws://127.0.0.1:4288; font-src 'self'");
    if (req.url === '/') {
        vite.transformIndexHtml('/', '<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recette EUR isolée</title></head><body><div id="app"></div><script type="module" src="/@id/__x00__virtual:eur-interface"></script></body></html>')
            .then(html => { res.setHeader('Content-Type', 'text/html'); res.end(html); })
            .catch(error => { console.error(error); res.statusCode = 500; res.end('Preview failed'); });
    } else vite.middlewares(req, res, () => { res.statusCode = 404; res.end('Not found'); });
});
server.listen(port, '127.0.0.1', () => console.log(`Synthetic preview: http://127.0.0.1:${port}`));
async function close() { server.close(); await vite.close(); }
process.on('SIGINT', close);
process.on('SIGTERM', close);
