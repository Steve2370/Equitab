import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatMoney, isSupportedCurrency } from '../resources/js/utils/money.ts';
import { isPaymentQuote } from '../resources/js/utils/paymentQuote.ts';
import { createDraftController, createDraftState, draftFingerprint, emptyDraftData, isDraftSaved, preparationErrors, safeDraftData, serviceDefaults } from '../resources/js/utils/groupDraft.ts';

const compact = (value) => value.replace(/[\u00a0\u202f]/g, ' ');
const service = { id: 3, name: 'Synthetic', slug: 'synthetic', tier: 'famille', category: 'Test', monthly_price: 1599, currency: 'CAD', max_members: 6 };
const valid = () => ({ ...emptyDraftData(), ...serviceDefaults(service), renewal_date: '2099-12-01' });
const record = (data = valid()) => ({ id: 'synthetic', version: 3, status: 'draft', data, updated_at: '2026-10-07T00:00:00Z', published_group_id: null });
function controller(initial = record()) {
    const state = createDraftState(initial);
    const requests = [];
    const api = {
        async update(id, version, data) { requests.push({ id, version, data }); return { ...record(data), version: version + 1 }; },
    };
    return { state, requests, ...createDraftController(state, api, () => 'new-synthetic') };
}

test('money formats exact minor units in French with an unambiguous currency code', () => {
    for (const currency of ['CAD', 'EUR']) {
        for (const [cents, expected] of [[0, '0,00'], [1, '0,01'], [29, '0,29'], [1599, '15,99'], [-1, '-0,01'], [-1599, '-15,99'], [123456, '1 234,56'], [Number.MAX_SAFE_INTEGER, '90 071 992 547 409,91']]) {
            assert.equal(compact(formatMoney(cents, currency)), `${expected} ${currency}`);
        }
    }
});

test('invalid amounts or currency never get a CAD default, coercion or rounded invented amount', () => {
    for (const currency of [undefined, null, '', 'USD', 'eur', 'CAD ', 123, {}]) {
        assert.equal(formatMoney(1599, currency), 'Montant indisponible');
        assert.equal(isSupportedCurrency(currency), false);
    }
    for (const cents of [undefined, null, '1599', NaN, Infinity, -Infinity, 1.5, Number.MAX_SAFE_INTEGER + 1]) {
        assert.equal(formatMoney(cents, 'EUR'), 'Montant indisponible');
    }
});

test('quote validation requires both integer amounts and their native supported currency', () => {
    const quote = { amount_today: 0, amount_recurring: 500, currency: 'EUR' };
    assert.equal(isPaymentQuote(quote), true);
    for (const invalid of [null, {}, { ...quote, currency: undefined }, { ...quote, amount_today: -1 }, { ...quote, amount_recurring: 1.1 }, { ...quote, amount_today: '0' }, { ...quote, next_billing_date: 12 }]) assert.equal(isPaymentQuote(invalid), false);
});

test('changing draft currency clears the amount and persists the currency without conversion', async () => {
    const h = controller();
    h.changeCurrency('EUR', ['CAD', 'EUR']);
    assert.equal(h.state.data.currency, 'EUR');
    assert.equal(h.state.data.total_price, null);
    assert.match(h.state.priceNotice, /Saisissez à nouveau.*EUR/);
    assert.equal(isDraftSaved(h.state), false);
    assert.ok(preparationErrors(h.state.data, service).total_price);
    await h.save();
    assert.equal(h.requests[0].data.currency, 'EUR');
    assert.equal(h.requests[0].data.total_price, null);
    assert.equal(isDraftSaved(h.state), true);
    h.state.data.total_price = 1299;
    assert.deepEqual(preparationErrors(h.state.data, service), {});
    h.changeCurrency('CAD', ['CAD', 'EUR']);
    assert.equal(h.state.data.total_price, null);
    assert.equal(h.state.data.currency, 'CAD');
});

test('same currency, unsupported/disabled currency, busy and locked drafts preserve the declared amount', () => {
    for (const [currency, enabled] of [['CAD', ['CAD', 'EUR']], ['EUR', ['CAD']], ['USD', ['USD']], ['', ['']]]) {
        const h = controller();
        h.changeCurrency(currency, enabled);
        assert.deepEqual(h.state.data, valid());
        assert.equal(h.state.priceNotice, '');
    }
    for (const changedState of [{ operation: 'saving' }, { conflict: true }, { leaving: true }, { saved: { ...record(), status: 'publishing' } }]) {
        const h = controller();
        Object.assign(h.state, changedState);
        h.changeCurrency('EUR', ['CAD', 'EUR']);
        assert.deepEqual(h.state.data, valid());
    }
});

test('service changes retain the draft currency and never reuse a foreign catalogue price', () => {
    assert.equal(serviceDefaults(service, 'EUR').currency, 'EUR');
    assert.equal(serviceDefaults(service, 'EUR').total_price, null);
    assert.equal(serviceDefaults(service, 'CAD').total_price, 1599);
    assert.equal(serviceDefaults({ ...service, currency: 'USD' }).currency, null);
    assert.equal(serviceDefaults({ ...service, currency: 'USD' }).total_price, null);
});

test('server-normalized legacy drafts preserve price, currency and saved state on restore', () => {
    for (const currency of ['CAD', 'EUR']) {
        const initial = record({ ...valid(), currency, visibility: 'invite_only' });
        const h = controller(initial);
        assert.equal(h.state.data.total_price, 1599);
        assert.equal(h.state.data.currency, currency);
        assert.equal(h.state.data.visibility, 'private');
        assert.equal(isDraftSaved(h.state), true);
        h.changeCurrency(currency === 'CAD' ? 'EUR' : 'CAD', ['CAD', 'EUR']);
        h.restore(initial);
        assert.equal(h.state.data.total_price, 1599);
        assert.equal(h.state.data.currency, currency);
        assert.equal(h.state.priceNotice, '');
        assert.equal(isDraftSaved(h.state), true);
    }
    const missing = valid();
    delete missing.currency;
    assert.equal(controller(record(missing)).state.data.currency, undefined, 'The browser must not guess a legacy currency before server normalization');
});

test('currency participates in dirty detection, safe data and publication validation', () => {
    assert.equal(Object.hasOwn(safeDraftData(emptyDraftData()), 'currency'), false, 'Incomplete new drafts omit currency rather than send a rejected null');
    assert.notEqual(draftFingerprint(valid()), draftFingerprint({ ...valid(), currency: 'EUR' }));
    assert.equal(safeDraftData({ ...valid(), currency: 'EUR', credential_password: 'excluded' }).currency, 'EUR');
    assert.ok(preparationErrors({ ...valid(), currency: null }, service).currency);
    assert.ok(preparationErrors({ ...valid(), currency: 'EUR' }, service, ['CAD']).currency);
    assert.deepEqual(preparationErrors({ ...valid(), currency: 'EUR' }, service, ['CAD', 'EUR']), {});
});

test('legacy payloads use server preview currency without losing price or inventing unsaved changes', async () => {
    for (const currency of ['CAD', 'EUR']) {
        const data = valid();
        delete data.currency;
        const initial = { ...record(data), preview: { currency, total_price: 1599, full_group_share: 267, max_members: 6 } };
        const h = controller(initial);
        assert.equal(h.state.data.currency, currency);
        assert.equal(h.state.data.total_price, 1599);
        assert.equal(h.state.saved.data.currency, undefined, 'Keep the persisted snapshot faithful');
        assert.equal(isDraftSaved(h.state), true);
        h.state.data.name = 'Explicit edit';
        await h.save();
        assert.equal(h.requests[0].data.currency, currency);
        assert.equal(h.state.saved.data.currency, currency);
        assert.equal(isDraftSaved(h.state), true);
        h.restore(initial);
        assert.equal(h.state.data.currency, currency);
        assert.equal(isDraftSaved(h.state), true);
        h.changeCurrency(currency === 'EUR' ? 'CAD' : 'EUR', ['CAD', 'EUR']);
        assert.equal(h.state.data.total_price, null);
        assert.equal(isDraftSaved(h.state), false);
    }
    const explicitInvalid = { ...record({ ...valid(), currency: null }), preview: { currency: 'CAD' } };
    assert.equal(controller(explicitInvalid).state.data.currency, null, 'Do not repair an explicit invalid currency from another field');
});
