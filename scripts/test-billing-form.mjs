// Run: node --test scripts/test-billing-form.mjs
// Exercises the real compiled Vue script with isolated browser/Stripe/HTTP
// boundaries. No DOM rendering, real card, network or Stripe account is used;
// these tests complement (and do not replace) the browser and sandbox recipes.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as money from '../resources/js/utils/money.ts';
import * as quotes from '../resources/js/utils/paymentQuote.ts';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../resources/js/Components/StripeCardForm.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source);
assert.deepEqual(errors, [], 'The production component must parse successfully');
const script = compileScript(descriptor, { id: 'isolated-billing-form' });
const compiled = ts.transpileModule(
    script.content.replaceAll('import.meta.env.VITE_STRIPE_KEY', JSON.stringify('pk_test_offline')),
    { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } },
).outputText;

async function exercise(options = {}) {
    const mounted = [], unmounted = [], calls = [], events = [], logs = [], challenges = [];
    let paymentMethods = 0;
    let destroyedCards = 0;
    const quote = options.quote ?? { amount_today: 420, amount_recurring: 500, currency: 'CAD' };
    const location = { href: '/invite/synthetic' };
    const stripe = {
        elements: () => ({ create: () => ({ mount() {}, destroy() { destroyedCards++; } }) }),
        createPaymentMethod: async () => {
            paymentMethods++;
            return { paymentMethod: { id: 'pm_synthetic' } };
        },
        confirmCardPayment: async (secret, parameters) => {
            challenges.push({ secret, parameters });
            return { paymentIntent: { status: 'succeeded' } };
        },
    };
    const module = { exports: {} };
    vm.runInNewContext(compiled, {
        exports: module.exports,
        require(name) {
            if (name === 'vue') {
                return {
                    ...require('vue'),
                    onMounted: callback => mounted.push(callback),
                    onUnmounted: callback => unmounted.push(callback),
                };
            }
            if (name === 'lucide-vue-next') return {};
            if (name === '@/utils/money') return money;
            if (name === '@/utils/paymentQuote') return quotes;
            throw new Error(`Unexpected component dependency: ${name}`);
        },
        URLSearchParams,
        window: { Stripe: () => stripe, location },
        document: { cookie: 'XSRF-TOKEN=synthetic' },
        console: Object.fromEntries(['log', 'error', 'warn', 'info', 'debug', 'table'].map(name => [name, (...args) => logs.push(args)])),
        fetch: async (url, init = {}) => {
            calls.push({ url, init });
            if (url.startsWith('/api/groups/15/proration')) {
                return { ok: !options.quoteFailure, json: async () => quote };
            }
            if (url === '/api/groups/15/subscribe') {
                return {
                    ok: true,
                    json: async () => ({
                        subscription_id: 'sub_synthetic',
                        ...(options.subscription ?? { status: 'active', client_secret: 'pi_SYNTHETIC_secret_NOT_REAL' }),
                    }),
                };
            }
            if (url === '/api/subscriptions/confirm') return { ok: !options.confirmFailure };
            throw new Error(`Unexpected HTTP request: ${url}`);
        },
    });
    const props = {
        groupId: 15, subscriptionName: 'Synthetic service', pricePerMember: 500, currency: 'CAD',
        inviteToken: options.withoutToken ? undefined : 'invite+synthetic&token',
        ...options.props,
    };
    const state = module.exports.default.setup(props, { expose() {}, emit: (...args) => events.push(args) });
    await Promise.all(mounted.map(callback => callback()));
    await state.handleSubmit();
    for (const callback of unmounted) callback();
    assert.equal(logs.length, 0, 'Payment responses and secrets must never be logged');
    assert.equal(destroyedCards, 1, 'The card element must be disposed when leaving');
    assert.equal(state.isLoading.value, false, 'Every outcome must release the loading state');
    return { state, calls, events, challenges, location, paymentMethods };
}

function assertNoSuccess(result) {
    assert.equal(result.events.length, 0);
    assert.equal(result.location.href, '/invite/synthetic');
    assert.ok(result.state.errorMessage.value, 'An unconfirmed outcome must remain visible');
}

test('invitation is encoded in proration and forwarded to checkout; only backend confirmation announces success', async () => {
    const result = await exercise();
    assert.equal(result.calls.length, 3);
    assert.equal(new URL(result.calls[0].url, 'http://offline.test').searchParams.get('invite_token'), 'invite+synthetic&token');
    assert.equal(result.calls[0].init.cache, 'no-store');
    assert.equal(result.calls[1].url, '/api/groups/15/subscribe');
    assert.equal(result.calls[1].init.method, 'POST');
    assert.deepEqual(JSON.parse(result.calls[1].init.body), { payment_method_id: 'pm_synthetic', invite_token: 'invite+synthetic&token' });
    assert.equal(result.calls[2].url, '/api/subscriptions/confirm');
    assert.deepEqual(JSON.parse(result.calls[2].init.body), { subscription_id: 'sub_synthetic' });
    assert.equal(result.calls[2].init.headers['X-XSRF-TOKEN'], 'synthetic');
    assert.equal(result.state.amountToday.value, 420);
    assert.equal(result.state.pricePerMember.value, 500);
    assert.equal(result.paymentMethods, 1);
    assert.deepEqual(result.events, [['success', 'sub_synthetic']]);
    assert.equal(result.location.href, '/payment/success?group_id=15');
});

test('public checkout omits the invitation token instead of sending an invented value', async () => {
    const result = await exercise({ withoutToken: true });
    assert.equal(result.calls[0].url, '/api/groups/15/proration');
    assert.deepEqual(JSON.parse(result.calls[1].init.body), { payment_method_id: 'pm_synthetic' });
    assert.deepEqual(result.events, [['success', 'sub_synthetic']]);
});

test('unavailable proration prevents payment method creation and subscription requests', async () => {
    const result = await exercise({ quoteFailure: true });
    assert.equal(result.paymentMethods, 0);
    assert.equal(result.calls.length, 2, 'Initial load and explicit retry only');
    assert.equal(result.calls.every(call => call.url.includes('/proration')), true);
    assertNoSuccess(result);
});

test('malformed proration never becomes a trusted amount or starts a payment', async () => {
    const result = await exercise({ quote: { amount_today: '420', amount_recurring: 500 } });
    assert.equal(result.paymentMethods, 0);
    assert.equal(result.state.amountToday.value, undefined);
    assert.equal(result.calls.every(call => call.url.includes('/proration')), true);
    assertNoSuccess(result);
});

test('a failed backend confirmation cannot emit success or redirect', async () => {
    const result = await exercise({ confirmFailure: true });
    assert.equal(result.calls.at(-1).url, '/api/subscriptions/confirm');
    assert.equal(result.paymentMethods, 1);
    assertNoSuccess(result);
});

test('an incomplete subscription without a confirmation secret remains unconfirmed', async () => {
    const result = await exercise({ subscription: { status: 'incomplete' } });
    assert.equal(result.calls.some(call => call.url.endsWith('/confirm')), false);
    assert.equal(result.challenges.length, 0);
    assertNoSuccess(result);
});

test('a successful 3DS challenge still requires backend confirmation and does not log its secret', async () => {
    const result = await exercise({ subscription: { status: 'incomplete', client_secret: 'pi_SYNTHETIC_secret_NOT_REAL' } });
    assert.equal(result.challenges.length, 1);
    assert.equal(result.challenges[0].secret, 'pi_SYNTHETIC_secret_NOT_REAL');
    assert.equal(result.challenges[0].parameters.payment_method, 'pm_synthetic');
    assert.equal(result.calls.filter(call => call.url.endsWith('/confirm')).length, 1);
    assert.deepEqual(result.events, [['success', 'sub_synthetic']]);
    assert.equal(result.location.href, '/payment/success?group_id=15');
});

test('both checkout amounts use the server quote currency independently of initial group props', async () => {
    const result = await exercise({ quote: { amount_today: 321, amount_recurring: 789, currency: 'EUR' } });
    assert.equal(result.state.currency.value, 'EUR');
    assert.match(result.state.formatCurrency(result.state.amountToday.value), /3,21\sEUR/);
    assert.match(result.state.formatCurrency(result.state.pricePerMember.value), /7,89\sEUR/);
    assert.equal(result.events.length, 1);
});

test('missing or unsupported quote currency cannot initiate checkout or display a CAD fallback', async () => {
    for (const currency of [undefined, null, '', 'USD', 'eur']) {
        const result = await exercise({ quote: { amount_today: 420, amount_recurring: 500, currency } });
        assert.equal(result.paymentMethods, 0);
        assert.equal(result.state.prorationReady.value, false);
        assert.equal(result.state.formatCurrency(500), 'Montant à confirmer');
        assertNoSuccess(result);
    }
});

test('a validated EUR quote supplied by the parent retains its currency and needs no second quote', async () => {
    const result = await exercise({ props: { currency: 'EUR', amountToday: 123, pricePerMember: 456 } });
    assert.equal(result.calls.some(call => call.url.includes('/proration')), false);
    assert.match(result.state.formatCurrency(result.state.amountToday.value), /1,23\sEUR/);
    assert.match(result.state.formatCurrency(result.state.pricePerMember.value), /4,56\sEUR/);
    assert.equal(result.events.length, 1);
});

test('invalid supplied amounts or currency trigger verification before accepting payment', async () => {
    for (const props of [{ amountToday: -1 }, { amountToday: 1.1 }, { amountToday: 420, pricePerMember: NaN }, { amountToday: 420, currency: '' }]) {
        const result = await exercise({ props, quoteFailure: true });
        assert.equal(result.paymentMethods, 0);
        assertNoSuccess(result);
    }
});
