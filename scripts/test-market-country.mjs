import assert from 'node:assert/strict';
import test from 'node:test';
import { reactive } from 'vue';
import { execFileSync } from 'node:child_process';
import { mount, flush } from './helpers/service-access-test-harness.mjs';

const countries = [
    { code: 'CA', name: 'Canada', currency: 'CAD' },
    { code: 'BE', name: 'Belgique', currency: 'EUR' },
    { code: 'BG', name: 'Bulgarie', currency: 'EUR' },
];

test('Rollout defaults activate Canada and Europe and respect explicit rollback flags', () => {
    // Pure config loading: no Laravel bootstrap, .env, database or network.
    const php = `
        $values = [];
        function env($key, $default = null) { global $values; return $values[$key] ?? $default; }
        $result = [];
        foreach ([[], ['EQUITAB_EUR_ENABLED' => false, 'EQUITAB_EUROZONE_CONNECT_ENABLED' => false],
            ['EQUITAB_EUR_ENABLED' => true, 'EQUITAB_EUROZONE_CONNECT_ENABLED' => false]] as $values) {
            $result[] = require 'config/payments.php';
        }
        echo json_encode($result);
    `;
    const result = JSON.parse(execFileSync('php', ['-r', php], { cwd: new URL('..', import.meta.url), encoding: 'utf8' }));
    assert.deepEqual(result, [
        { eur_enabled: true, eurozone_connect_enabled: true },
        { eur_enabled: false, eurozone_connect_enabled: false },
        { eur_enabled: true, eurozone_connect_enabled: false },
    ]);
});

test('CountryField exposes an explicit accessible choice and native currency, without conversion', async () => {
    let selected;
    const ui = mount('Components/Experience/CountryField.vue', {
        modelValue: '', countries, label: 'Pays de résidence', required: true,
        form: 'registration-form', 'onUpdate:modelValue': value => { selected = value; },
    });
    try {
        const select = ui.find(n => n.type === 'select');
        assert.equal(select.props.required, true);
        assert.equal(select.props.form, 'registration-form');
        assert.equal(ui.find(n => n.type === 'label').props.for, select.props.id);
        assert.equal(select.props.value, '');
        assert.equal(ui.nodes().filter(n => n.type === 'option').length, 4);
        select.props.onChange({ target: { value: 'BE' } });
        assert.equal(selected, 'BE');
        await ui.update({ modelValue: selected });
        assert.match(ui.text(), /Devise proposée : euro \(EUR\)/);
        await ui.update({ modelValue: 'CA' });
        assert.match(ui.text(), /Devise proposée : dollar canadien \(CAD\)/);
        await ui.update({ modelValue: 'BG' });
        assert.match(ui.text(), /Devise proposée : euro \(EUR\)/);
        assert.deepEqual(ui.forbidden, []);
    } finally { ui.unmount(); }
});

test('CountryField announces validation errors and supports pending state', () => {
    const ui = mount('Components/Experience/CountryField.vue', {
        modelValue: '', countries, label: 'Pays', error: 'Pays non pris en charge.', disabled: true,
    });
    try {
        const select = ui.find(n => n.type === 'select');
        const error = ui.find(n => n.props.role === 'alert');
        assert.equal(select.props.disabled, true);
        assert.equal(select.props['aria-invalid'], true);
        assert.ok(select.props['aria-describedby'].includes(error.props.id));
        assert.match(ui.text(), /Pays non pris en charge/);
    } finally { ui.unmount(); }
});

function selector(market = { country: 'CA', currency: 'CAD', countries, enabled: true }) {
    const page = reactive({ props: { market } });
    let form;
    const posts = [];
    const ui = mount('Components/Experience/MarketSelector.vue', {}, { inertia: {
        usePage: () => page,
        useForm: data => form = reactive({ ...data, errors: {}, processing: false,
            clearErrors() { this.errors = {}; },
            post(url, options) { posts.push({ url, country: this.country, options }); },
        }),
    } });
    const submit = () => ui.find(n => n.type === 'form').props.onSubmit({ preventDefault() {} });
    return { ui, page, form, posts, submit };
}

test('MarketSelector saves only a changed country and prevents repeated pending submits', async () => {
    const { ui, form, posts, submit } = selector();
    try {
        submit();
        assert.equal(posts.length, 0);
        ui.find(n => n.type === 'select').props.onChange({ target: { value: 'BE' } });
        await flush();
        submit();
        assert.equal(posts.length, 1);
        assert.equal(posts[0].url, '/market');
        assert.equal(posts[0].country, 'BE');
        assert.equal(posts[0].options.preserveState, true);
        assert.equal(posts[0].options.preserveScroll, true);
        form.processing = true;
        await flush();
        submit();
        assert.equal(posts.length, 1);
        assert.equal(ui.find(n => n.type === 'button').props.disabled, true);
        assert.equal(ui.find(n => n.type === 'select').props.disabled, true);
        assert.match(ui.text(), /Enregistrement/);
    } finally { ui.unmount(); }
});

test('MarketSelector uses server-confirmed country and clears success on another selection', async () => {
    const { ui, page, form, posts, submit } = selector();
    try {
        form.country = 'BE';
        await flush();
        submit();
        page.props.market = { country: 'BE', currency: 'EUR', countries, enabled: true };
        await flush();
        posts[0].options.onSuccess();
        await flush();
        assert.match(ui.text(), /Pays enregistré/);
        assert.equal(ui.find(n => n.type === 'button').props.disabled, true);
        assert.match(ui.text(), /sans convertir vos abonnements/);
        form.country = 'CA';
        await flush();
        assert.doesNotMatch(ui.text(), /Pays enregistré/);
        assert.equal(ui.find(n => n.type === 'button').props.disabled, false);
        page.props.market.country = 'BG';
        await flush();
        assert.equal(form.country, 'BG');
    } finally { ui.unmount(); }
});

test('MarketSelector displays server rejection and allows correcting the choice', async () => {
    const { ui, form } = selector();
    try {
        form.errors.country = 'Ce pays est indisponible.';
        await flush();
        assert.match(ui.text(), /Ce pays est indisponible/);
        form.country = 'BE';
        await flush();
        assert.doesNotMatch(ui.text(), /Ce pays est indisponible/);
    } finally { ui.unmount(); }
});

test('MarketSelector explains an EUR rollout pause without changing the selected currency', () => {
    const { ui, form } = selector({ country: 'BE', currency: 'EUR', countries, enabled: false });
    try {
        assert.match(ui.text(), /paiements en EUR sont temporairement indisponibles/);
        assert.equal(form.country, 'BE');
    } finally { ui.unmount(); }
});

test('MarketSelector stays compatible with previews without market props', () => {
    const { ui } = selector(null);
    try { assert.equal(ui.nodes().some(n => n.type === 'form'), false); }
    finally { ui.unmount(); }
});
