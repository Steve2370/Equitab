const mjml = require('mjml');
const fs = require('fs');
const path = require('path');
const projectRoot = path.resolve(__dirname, '../..');

const templates = [
    {
        input: 'resources/emails/mjml/payment-confirmed.mjml',
        output: 'resources/views/emails/payment/confirmed.blade.php',
    },
    {
        input: 'resources/emails/mjml/new-member-joined.mjml',
        output: 'resources/views/emails/group/new-member.blade.php',
    },
    {
        input: 'resources/emails/mjml/welcome.mjml',
        output: 'resources/views/emails/auth/welcome.blade.php',
    },
    {
        input: 'resources/emails/mjml/renewal-reminder.mjml',
        output: 'resources/views/emails/subscription/renewal-reminder.blade.php',
    },
    {
        input: 'resources/emails/mjml/payment-failed.mjml',
        output: 'resources/views/emails/payment/failed.blade.php',
    },
    {
        input: 'resources/emails/mjml/auto-refund.mjml',
        output: 'resources/views/emails/payment/refund.blade.php',
    },
    {
        input: 'resources/emails/mjml/new-message.mjml',
        output: 'resources/views/emails/chat/new-message.blade.php',
    },
    {
        input: 'resources/emails/mjml/price-changed.mjml',
        output: 'resources/views/emails/subscription/price-changed.blade.php',
    },
    {
        input: 'resources/emails/mjml/identity-verified.mjml',
        output: 'resources/views/emails/auth/identity-verified.blade.php',
    },
    {
        input: 'resources/emails/mjml/connect-activated.mjml',
        output: 'resources/views/emails/auth/connect-activated.blade.php',
    },
    {
        input: 'resources/emails/mjml/admin-message.mjml',
        output: 'resources/views/emails/admin/message.blade.php',
    },
    {
        input: 'resources/emails/mjml/notification-layout.mjml',
        output: 'resources/views/vendor/mail/html/layout.blade.php',
    },
];

async function renderTemplate(input) {
    const filePath = path.join(projectRoot, input);
    const result = await mjml(fs.readFileSync(filePath, 'utf8'), {
        filePath,
        ignoreIncludes: false,
        validationLevel: 'strict',
    });
    return result.html.split('\n').map((line) => line.trimEnd()).join('\n').trimEnd() + '\n';
}

async function compile({ check = false } = {}) {
    // Validate every template before replacing any generated view.
    const rendered = await Promise.all(templates.map(async (template) => ({
        ...template,
        html: await renderTemplate(template.input),
    })));
    for (const { input, output, html } of rendered) {
        const destination = path.join(projectRoot, output);
        if (check) {
            if (!fs.existsSync(destination) || fs.readFileSync(destination, 'utf8') !== html) {
                throw new Error(`Vue obsolète : ${output}. Exécutez npm run emails.`);
            }
        } else {
            fs.mkdirSync(path.dirname(destination), { recursive: true });
            fs.writeFileSync(destination, html);
        }
        console.log(`${check ? 'Vérifié' : 'Compilé'} : ${input} → ${output}`);
    }
}

module.exports = { compile, renderTemplate, templates };

if (require.main === module) {
    compile({ check: process.argv.includes('--check') }).catch((error) => {
        console.error(error);
        process.exitCode = 1;
    });
}
