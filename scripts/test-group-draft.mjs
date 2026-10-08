import { test } from "node:test";
import assert from "node:assert/strict";
import {
    activationUrl, centsFromInput, createDraftController, createDraftState, draftFingerprint,
    draftIsLocked, emptyDraftData, formatGroupMoney, fullGroupShare, isDraftSaved,
    memberLimit, preparationErrors, safeDraftData, safeDraftRecord,
    serviceDefaults, DraftRequestError,
} from "../resources/js/utils/groupDraft.ts";

const id = "11111111-1111-4111-8111-111111111111";
const service = { id: 3, name: "Service test", slug: "test", tier: "famille", category: "Musique", monthly_price: 1599, currency: "CAD", max_members: 6 };
const ready = { ready: true, identityVerified: true, connectActive: true, identityStatus: "verified", connectStatus: "active" };
const credentials = { credential_email: "synthetic@example.test", credential_password: "synthetic-test-value", credential_notes: "Synthetic notes" };
const valid = () => ({ ...emptyDraftData(), ...serviceDefaults(service), renewal_date: "2099-12-01" });
const record = (data = valid(), version = 1, status = "draft") => ({ id, version, status, data: structuredClone(data), updated_at: `2026-10-06T12:00:0${version}Z`, published_group_id: null });
function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}
function harness(initial = null, overrides = {}) {
    const calls = [];
    const history = [];
    const state = createDraftState(initial);
    const api = {
        async create(uuid, data) { calls.push(["create", uuid, structuredClone(data)]); return record(data); },
        async update(uuid, version, data) { calls.push(["update", uuid, version, structuredClone(data)]); return record(data, version + 1); },
        async activate(kind, uuid) { calls.push([kind, uuid]); return "https://connect.stripe.com/synthetic"; },
        async publish(uuid, version, secret) { calls.push(["publish", uuid, version, secret]); return { redirect: "/dashboard/subscriptions", group_id: 42 }; },
        async reopen(uuid, version) { calls.push(["reopen", uuid, version]); return record(initial.data, version + 1); },
        ...overrides,
    };
    let uuids = 0;
    const controller = createDraftController(state, api, () => { uuids += 1; return id; }, (draft) => { history.push(structuredClone(draft)); });
    return { state, controller, calls, history, uuids: () => uuids };
}

test("new draft is incomplete, private until publication and has no invented date", () => {
    const h = harness();
    assert.equal(h.state.data.subscription_id, null);
    assert.equal(h.state.data.renewal_date, "");
    assert.equal(h.state.data.max_members, null);
    assert.equal(h.state.data.total_price, null);
    assert.equal(h.state.saved, null);
    assert.equal(isDraftSaved(h.state), false);
    assert.deepEqual(h.calls, []);
});

test("service defaults use real cents, real tier and a capacity capped at ten", () => {
    assert.deepEqual(serviceDefaults(service), { subscription_id: 3, name: "Groupe Service test", tier: "famille", max_members: 6, total_price: 1599, currency: "CAD" });
    assert.equal(memberLimit({ ...service, max_members: 25 }), 10);
    assert.equal(memberLimit({ ...service, max_members: 3 }), 3);
    assert.equal(memberLimit(undefined), 0);
    assert.equal(serviceDefaults(service).renewal_date, undefined);
});

test("currency input converts cents exactly and rejects excess precision or invalid inputs", () => {
    for (const [input, expected] of [["15,99", 1599], ["15.99", 1599], ["0.29", 29], ["1", 100], ["1,5", 150], [" 10.05 ", 1005], ["0", 0]]) assert.equal(centsFromInput(input), expected);
    for (const input of ["", "-1", "1.001", "Infinity", "1e3", "12abc", "9007199254740991"]) assert.equal(centsFromInput(input), null);
    assert.equal(formatGroupMoney(1599, "USD"), "Montant indisponible");
    assert.notEqual(formatGroupMoney(1599, "USD"), formatGroupMoney(1599, "CAD"));
});

test("estimation is per person at full capacity and never invents missing amounts", () => {
    assert.equal(fullGroupShare(valid()), 267);
    assert.equal(fullGroupShare({ ...valid(), max_members: 3 }), 533);
    for (const data of [{}, { total_price: 2000 }, { total_price: -1, max_members: 3 }, { total_price: 1599, max_members: 1 }, { total_price: 1599, max_members: 11 }]) assert.equal(fullGroupShare(data), null);
});

test("preparation requires a service, explicit renewal input, price and actual capacity", () => {
    assert.deepEqual(preparationErrors(valid(), service), {});
    const errors = preparationErrors({ ...valid(), max_members: 7, total_price: null, renewal_date: "" }, service);
    assert.deepEqual(Object.keys(errors).sort(), ["max_members", "renewal_date", "total_price"]);
    assert.ok(preparationErrors(valid(), undefined).subscription_id);
});

test("activation accepts secure external URLs and same-origin returns, including local development", () => {
    assert.equal(activationUrl("https://connect.stripe.com/synthetic", "http://localhost:8000"), "https://connect.stripe.com/synthetic");
    assert.equal(activationUrl(`/dashboard/groups/drafts/${id}`, "http://localhost:8000"), `http://localhost:8000/dashboard/groups/drafts/${id}`);
    for (const value of ["", "  ", "javascript:alert(1)", "data:text/html,bad", "http://external.example.test/"]) assert.throws(() => activationUrl(value, "http://localhost:8000"), DraftRequestError);
});

test("draft payload and history use an allowlist excluding every credential and extra server field", async () => {
    const contaminated = { ...valid(), ...credentials, owner_id: 900, ready: true };
    assert.deepEqual(safeDraftData(contaminated), valid());
    assert.equal(draftFingerprint(contaminated), draftFingerprint(valid()));
    const raw = { ...record(contaminated), ...credentials, preview: { full_group_share: 267, total_price: 1599, max_members: 6, currency: "CAD", ...credentials } };
    const clean = safeDraftRecord(raw);
    for (const value of Object.values(credentials)) assert.equal(JSON.stringify(clean).includes(value), false);
    const h = harness(null, { async create() { return raw; } });
    h.state.data = contaminated;
    await h.controller.save();
    for (const value of Object.values(credentials)) assert.equal(JSON.stringify(h.history).includes(value), false);
});

test("empty drafts are saved without activation or publication", async () => {
    const h = harness();
    const result = await h.controller.save();
    assert.equal(result.id, id);
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0][0], "create");
    assert.equal(isDraftSaved(h.state), true);
});

test("saved status requires a confirmed response and is invalidated immediately by editing", async () => {
    const pending = deferred();
    const h = harness(null, { create: () => pending.promise });
    h.state.data = valid();
    const saving = h.controller.save();
    assert.equal(isDraftSaved(h.state), false);
    assert.equal(h.state.operation, "saving");
    pending.resolve(record());
    await saving;
    assert.equal(isDraftSaved(h.state), true);
    h.state.data.name = "Changed";
    assert.equal(isDraftSaved(h.state), false);
});

test("double clicks cannot duplicate save requests or overtake an in-flight save", async () => {
    const pending = deferred();
    let count = 0;
    const h = harness(null, { create: () => { count += 1; return pending.promise; } });
    h.state.data = valid();
    const first = h.controller.save();
    assert.equal(await h.controller.save(), null);
    assert.equal(await h.controller.activate("connect"), null);
    assert.equal(await h.controller.publish(ready, true, credentials), null);
    assert.equal(count, 1);
    pending.resolve(record());
    await first;
});

test("failed creation retains the typed fields and the same UUID for explicit retry", async () => {
    const ids = [];
    let attempt = 0;
    const h = harness(null, { async create(uuid, data) { ids.push(uuid); if (++attempt === 1) throw new Error("network failure"); return record(data); } });
    h.state.data = valid();
    assert.equal(await h.controller.save(), null);
    assert.deepEqual(h.state.data, valid());
    assert.equal(h.state.saved, null);
    assert.equal(h.state.id, id);
    assert.equal(h.history.length, 0);
    assert.ok(h.state.message);
    await h.controller.save();
    assert.deepEqual(ids, [id, id]);
    assert.equal(h.uuids(), 1);
    assert.equal(isDraftSaved(h.state), true);
});

test("a recovered POST conflict retains its UUID and edits and requires an explicit reload", async () => {
    let count = 0;
    const h = harness(null, { async create() { count += 1; throw new DraftRequestError(409, "Reload"); } });
    h.state.data = valid();
    await h.controller.save();
    assert.equal(h.state.id, id);
    assert.deepEqual(h.state.data, valid());
    assert.equal(h.state.conflict, true);
    assert.equal(await h.controller.save(), null);
    assert.equal(count, 1);
});

test("updates use the last confirmed version and successful saves advance it", async () => {
    const h = harness(record(valid(), 4));
    h.state.data.name = "First edit";
    await h.controller.save();
    assert.equal(h.calls[0][2], 4);
    assert.equal(h.state.saved.version, 5);
    h.state.data.name = "Second edit";
    await h.controller.save();
    assert.equal(h.calls[1][2], 5);
    assert.equal(h.state.saved.version, 6);
});

test("409 from another tab freezes mutations without changing version or losing input", async () => {
    let requests = 0;
    const h = harness(record(valid(), 4), { async update() { requests += 1; throw new DraftRequestError(409, "Reload"); } });
    h.state.data.name = "Unsaved local name";
    await h.controller.save();
    assert.equal(h.state.saved.version, 4);
    assert.equal(h.state.data.name, "Unsaved local name");
    assert.equal(draftIsLocked(h.state), true);
    assert.equal(isDraftSaved(h.state), false);
    await h.controller.save();
    await h.controller.activate("identity");
    await h.controller.publish(ready, true, credentials);
    assert.equal(requests, 1);
    assert.equal(h.calls.length, 0);
});

test("a late save response cannot erase edits made while saving", async () => {
    const pending = deferred();
    const h = harness(record(), { update: () => pending.promise });
    h.state.data.name = "Submitted";
    const saving = h.controller.save();
    h.state.data.name = "Typed afterwards";
    pending.resolve(record({ ...valid(), name: "Submitted" }, 2));
    await saving;
    assert.equal(h.state.data.name, "Typed afterwards");
    assert.equal(h.state.saved.data.name, "Submitted");
    assert.equal(h.state.saved.version, 2);
    assert.equal(isDraftSaved(h.state), false);
});

test("server canonicalization is accepted only when the submitted fields remain unchanged", async () => {
    const canonical = { ...valid(), name: "Trimmed", description: null };
    const h = harness(null, { async create() { return record(canonical); } });
    h.state.data = { ...valid(), name: " Trimmed ", description: "" };
    await h.controller.save();
    assert.equal(h.state.data.name, "Trimmed");
    assert.equal(h.state.data.description, null);
    assert.equal(isDraftSaved(h.state), true);
});

test("Laravel null text and Vue empty input emissions remain semantically saved after POST", async () => {
    const state = createDraftState(null);
    state.data = { ...valid(), name: "Spotify test", description: "", renewal_date: "" };
    const serverData = { ...state.data, description: null, renewal_date: null };
    let creates = 0;
    let updates = 0;
    const transport = {
        async create() { creates += 1; return record(serverData); },
        async update() { updates += 1; throw new Error("No update should be needed"); },
    };
    const controller = createDraftController(state, transport, () => id, async () => {
        // Actual browser behavior: text/date v-model can emit blanks after the null response.
        await Promise.resolve();
        state.data.description = "";
        state.data.renewal_date = "";
    });
    assert.equal((await controller.save()).version, 1);
    assert.equal(state.saved.data.description, null);
    assert.equal(state.saved.data.renewal_date, null);
    assert.equal(state.data.description, "");
    assert.equal(state.data.renewal_date, "");
    assert.equal(state.message, "");
    assert.equal(isDraftSaved(state), true);
    await controller.save();
    assert.equal(creates, 1);
    assert.equal(updates, 0);
    state.data.total_price = 1600;
    assert.equal(isDraftSaved(state), false);
});

test("blank text normalization never hides a changed date, price, type, capacity or boolean", () => {
    for (const key of ["name", "description", "renewal_date"]) {
        assert.equal(draftFingerprint({ ...valid(), [key]: null }), draftFingerprint({ ...valid(), [key]: "" }));
        assert.notEqual(draftFingerprint({ ...valid(), [key]: "changed" }), draftFingerprint({ ...valid(), [key]: "" }));
    }
    for (const [key, left, right] of [
        ["total_price", null, 0], ["total_price", null, ""], ["total_price", 1599, "1599"],
        ["max_members", 6, "6"], ["max_members", null, 0], ["max_members", 6, 5],
        ["auto_renew", null, false], ["auto_renew", false, true],
        ["tier", null, ""], ["subscription_id", null, 0],
    ]) assert.notEqual(draftFingerprint({ ...valid(), [key]: left }), draftFingerprint({ ...valid(), [key]: right }));
});

test("restore on navigation cancels the effect of earlier responses and errors", async () => {
    for (const fail of [false, true]) {
        const pending = deferred();
        const h = harness(null, { create: () => pending.promise });
        const saving = h.controller.save();
        const destination = { ...record({ ...valid(), name: "Other draft" }, 7), id: "22222222-2222-4222-8222-222222222222" };
        h.controller.restore(destination);
        if (fail) pending.reject(new DraftRequestError(409, "Stale")); else pending.resolve(record());
        assert.equal(await saving, null);
        assert.equal(h.state.id, destination.id);
        assert.equal(h.state.data.name, "Other draft");
        assert.equal(h.state.saved.version, 7);
        assert.equal(h.state.conflict, false);
        assert.equal(h.history.length, 0);
    }
});

test("unmounted controllers never update history on late response", async () => {
    const pending = deferred();
    const h = harness(null, { create: () => pending.promise });
    const saving = h.controller.save();
    h.controller.dispose();
    pending.resolve(record());
    assert.equal(await saving, null);
    assert.equal(h.history.length, 0);
});

test("activation saves non-sensitive data before starting either Stripe flow", async () => {
    for (const kind of ["identity", "connect"]) {
        const h = harness();
        h.state.data = valid();
        assert.equal(await h.controller.activate(kind), "https://connect.stripe.com/synthetic");
        assert.deepEqual(h.calls.map((call) => call[0]), ["create", kind]);
        assert.deepEqual(h.calls[1], [kind, id]);
        assert.equal(h.state.leaving, true);
        assert.equal(h.history.length, 1);
    }
});

test("a failed save or new edits prevent departure to Stripe", async () => {
    const h = harness(null, { async create() { throw new DraftRequestError(503, "Unavailable"); } });
    assert.equal(await h.controller.activate("connect"), null);
    assert.deepEqual(h.calls, []);
    assert.equal(h.state.leaving, false);
    const pending = deferred();
    const changed = harness(null, { create: () => pending.promise });
    changed.state.data = valid();
    const activating = changed.controller.activate("identity");
    changed.state.data.name = "Changed while saving";
    pending.resolve(record());
    assert.equal(await activating, null);
    assert.deepEqual(changed.calls, []);
});

test("Stripe errors keep the saved draft resumable and do not publish", async () => {
    const h = harness(null, { async activate() { throw new DraftRequestError(503, "Unavailable"); } });
    h.state.data = valid();
    assert.equal(await h.controller.activate("identity"), null);
    assert.equal(isDraftSaved(h.state), true);
    assert.equal(h.state.leaving, false);
    assert.equal(h.state.operation, "idle");
    assert.equal(h.state.message, "Unavailable");
});

test("publication requires explicit certification and all three readiness flags", async () => {
    const h = harness(record());
    assert.equal(await h.controller.publish(ready, false, credentials), null);
    for (const key of ["ready", "identityVerified", "connectActive"]) assert.equal(await h.controller.publish({ ...ready, [key]: false }, true, credentials), null);
    assert.deepEqual(h.calls, []);
});

test("already-active owner saves then explicitly publishes without any onboarding", async () => {
    const h = harness();
    h.state.data = valid();
    const result = await h.controller.publish(ready, true, credentials);
    assert.equal(result.group_id, 42);
    assert.deepEqual(h.calls.map((call) => call[0]), ["create", "publish"]);
    assert.deepEqual(h.calls[1], ["publish", id, 1, credentials]);
    assert.equal(h.state.saved.status, "published");
    assert.equal(h.state.leaving, true);
    for (const value of Object.values(credentials)) {
        assert.equal(JSON.stringify(h.calls[0]).includes(value), false);
        assert.equal(JSON.stringify(h.history).includes(value), false);
        assert.equal(JSON.stringify(h.state).includes(value), false);
    }
    assert.equal(await h.controller.publish(ready, true, credentials), null);
});

test("422, 403 and 503 never erase input or masquerade as successful publication", async () => {
    for (const status of [422, 403, 503]) {
        const h = harness(record(), { async publish() { throw new DraftRequestError(status, "Safe failure", { renewal_date: "Correct date" }); } });
        assert.equal(await h.controller.publish(ready, true, credentials), null);
        assert.deepEqual(h.state.data, valid());
        assert.equal(h.state.errors.renewal_date, "Correct date");
        assert.equal(h.state.saved.status, "draft");
        assert.equal(h.state.leaving, false);
        assert.equal(h.state.operation, "idle");
    }
});

test("publication blocks double clicks and a competing reopen/save until the response", async () => {
    const pending = deferred();
    let calls = 0;
    const h = harness(record(), { publish: () => { calls += 1; return pending.promise; } });
    const first = h.controller.publish(ready, true, credentials);
    assert.equal(await h.controller.publish(ready, true, credentials), null);
    assert.equal(await h.controller.save(), null);
    assert.equal(await h.controller.reopen(), null);
    pending.resolve({ redirect: "/dashboard/subscriptions", group_id: 42 });
    await first;
    assert.equal(calls, 1);
});

test("restoring a publishing record freezes editing and never automatically retries publication", async () => {
    const h = harness(record(valid(), 4, "publishing"));
    assert.equal(draftIsLocked(h.state), true);
    assert.equal(await h.controller.save(), null);
    assert.equal(await h.controller.activate("connect"), null);
    assert.deepEqual(h.calls, []);
});

test("an explicit retry of publishing uses its unchanged version and requires consent", async () => {
    const h = harness(record(valid(), 4, "publishing"));
    assert.equal(await h.controller.publish(ready, false, credentials), null);
    await h.controller.publish(ready, true, credentials);
    assert.deepEqual(h.calls, [["publish", id, 4, credentials]]);
});

test("reopen recovers interrupted publication, advances version and allows correcting expired date", async () => {
    const initial = record({ ...valid(), renewal_date: "2020-01-01" }, 4, "publishing");
    const h = harness(initial);
    const reopened = await h.controller.reopen();
    assert.equal(reopened.status, "draft");
    assert.equal(reopened.version, 5);
    assert.equal(draftIsLocked(h.state), false);
    assert.deepEqual(h.calls, [["reopen", id, 4]]);
    assert.equal(h.state.data.renewal_date, "2020-01-01");
    h.state.data.renewal_date = "2099-11-01";
    assert.equal(isDraftSaved(h.state), false);
    await h.controller.save();
    assert.equal(h.calls[1][0], "update");
    assert.equal(h.calls[1][2], 5);
    assert.equal(h.state.saved.data.renewal_date, "2099-11-01");
    assert.equal(h.calls.some((call) => call[0] === "publish"), false);
});

test("reopen refuses non-publishing states and serializes double clicks", async () => {
    for (const status of ["draft", "published"]) {
        const h = harness(record(valid(), 4, status));
        assert.equal(await h.controller.reopen(), null);
        assert.deepEqual(h.calls, []);
    }
    const pending = deferred();
    let calls = 0;
    const h = harness(record(valid(), 4, "publishing"), { reopen: () => { calls += 1; return pending.promise; } });
    const first = h.controller.reopen();
    assert.equal(await h.controller.reopen(), null);
    assert.equal(await h.controller.publish(ready, true, credentials), null);
    pending.resolve(record(valid(), 5));
    await first;
    assert.equal(calls, 1);
});

test("stale reopen keeps fields frozen until reload instead of inventing a version", async () => {
    const h = harness(record(valid(), 4, "publishing"), { async reopen() { throw new DraftRequestError(409, "Reload"); } });
    await h.controller.reopen();
    assert.equal(h.state.saved.version, 4);
    assert.equal(h.state.saved.status, "publishing");
    assert.equal(h.state.conflict, true);
    assert.equal(await h.controller.reopen(), null);
    assert.deepEqual(h.state.data, valid());
});

test("restoring saved data on reload/back-forward retains only the matching draft", () => {
    const h = harness(record());
    h.state.data.name = "Unsaved";
    h.controller.restore(record({ ...valid(), name: "Restored" }, 8));
    assert.equal(h.state.data.name, "Restored");
    assert.equal(h.state.saved.version, 8);
    assert.equal(isDraftSaved(h.state), true);
    assert.deepEqual(h.calls, []);
    h.controller.restore(null);
    assert.equal(h.state.id, null);
    assert.equal(h.state.data.subscription_id, null);
});
