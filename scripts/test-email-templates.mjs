import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const { templates, renderTemplate } = require('../resources/emails/compile.cjs');
const root = fileURLToPath(new URL('../', import.meta.url));

test('all mail templates validate strictly and match the committed Blade views', async () => {
    assert.equal(templates.length, 12);
    for (const { input, output } of templates) {
        const html = await renderTemplate(input);
        assert.equal(html, readFileSync(path.join(root, output), 'utf8'), output);
        assert.ok(html.includes('/Images/EquitabLogo.png'), input);
        assert.ok(html.includes('Montserrat, Arial, sans-serif'), input);
        assert.ok(html.includes('#187a57'), input);
        assert.ok(html.includes('max-width: 480px'), input);
        assert.ok(!html.includes('mj-include'), input);
        assert.ok(!html.includes('cdn.jsdelivr.net'), input);
    }
});

test('an invalid or missing source rejects instead of producing a successful build', async () => {
    await assert.rejects(renderTemplate('resources/emails/mjml/__missing__.mjml'));
    // A partial cannot be compiled as an independent document.
    await assert.rejects(renderTemplate('resources/emails/mjml/partials/header.mjml'));
});
