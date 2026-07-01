const mjml = require('mjml');
const fs = require('fs');
const path = require('path');

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
];

async function compile() {
    for (const { input, output } of templates) {
        const mjmlContent = fs.readFileSync(input, 'utf8');
        const result = await mjml(mjmlContent, { validationLevel: 'soft' });

        if (result.errors && result.errors.length > 0) {
            console.error(`Erreurs dans ${input}:`, result.errors);
        }

        const dir = path.dirname(output);
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });

        fs.writeFileSync(output, result.html);
        console.log(`Compilé : ${input} → ${output}`);
    }
}

compile().catch(console.error);
