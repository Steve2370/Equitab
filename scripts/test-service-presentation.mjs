import { test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import {
    servicePresentation,
    indicativeShare,
    formatCad,
    serviceBrand,
} from "../resources/js/config/servicePresentation.ts";


test("monthly cents are divided by full-group capacity without dividing twice", () => {
    assert.equal(indicativeShare(2400, 4), 600);
    assert.equal(indicativeShare(1599, 6), 267);
    assert.equal(indicativeShare(0, 4), 0);
    assert.equal(formatCad(600).replaceAll("\u00a0", " "), "6,00 $");
});
test("unknown or invalid prices and capacities never become made-up prices", () => {
    for (const pair of [
        [null, 4],
        [1000, null],
        [1000, 0],
        [1000, -1],
        [1000, 2.5],
        [-1, 4],
        [Infinity, 4],
        [NaN, 4],
    ]) {
        assert.equal(indicativeShare(...pair), null);
    }
});
test("explicit service identities have priority over a category", () => {
    assert.equal(servicePresentation("spotify", "Streaming").scene, "music");
    assert.equal(servicePresentation("disney", "Films").scene, "world");
    assert.equal(servicePresentation("disney-plus", "Films").scene, "world");
    assert.equal(servicePresentation("apple-music", "Musique").palette, "rose");
    assert.equal(
        servicePresentation("youtube-premium", "Streaming").scene,
        "play",
    );
});
test("all seeded categories receive a scene, including accented labels", () => {
    for (const [category, scene] of [
        ["Musique", "music"],
        ["Jeux", "arcade"],
        ["Sécurité", "shield"],
        ["Productivité", "cloud"],
        ["Éducation", "book"],
        ["Lecture", "book"],
        ["Streaming", "cinema"],
        ["Films et séries", "cinema"],
    ]) {
        assert.equal(
            servicePresentation("nouveau-service", category).scene,
            scene,
        );
    }
});
test("unknown services have a usable, non-crashing visual fallback", () => {
    assert.equal(
        servicePresentation("service-inconnu", "Autre catégorie").scene,
        "world",
    );
});
test("service identities are distinct and support real catalogue aliases", () => {
    assert.equal(serviceBrand("netflix").accent, "#e50914");
    assert.equal(serviceBrand("spotify").accent, "#1ed760");
    assert.equal(serviceBrand("Disney+").name, "Disney+");
    assert.equal(serviceBrand("canal").name, "CANAL+");
    assert.equal(serviceBrand("canal-plus").name, "CANAL+");
    assert.equal(serviceBrand("NordVPN").name, "NordVPN");
    assert.equal(serviceBrand("Microsoft 365").name, "Microsoft 365");
    assert.equal(serviceBrand("nouveau-service"), null);
    assert.equal(serviceBrand("__proto__"), null);
});
test("each seeded service has a palette and only a name as identity, never a logo", () => {
    for (const slug of [
        "netflix",
        "disney",
        "youtube-premium",
        "crave",
        "crunchyroll",
        "paramount",
        "canal",
        "amazon-prime",
        "spotify",
        "apple-music",
        "deezer",
        "tidal",
        "xbox-game-pass",
        "nintendo",
        "nordvpn",
        "cyberghost",
        "envato",
        "google-one",
        "microsoft-365",
        "apple-one-family",
        "duolingo",
        "readly",
    ]) {
        const brand = serviceBrand(slug);
        assert.ok(brand, slug);
        assert.match(brand.accent, /^#[0-9a-f]{6}$/i);
        assert.deepEqual(Object.keys(brand).sort(), ["accent", "deep", "ink", "name"]);
    }
});

test("no third-party service logo is shipped or referenced by the interface", () => {
    // Cards show the service name only: no logo folder, component or path.
    assert.equal(existsSync(new URL("../public/Images/services", import.meta.url)), false);
    const sources = [new URL("../scripts/render-equitab-story.mjs", import.meta.url)];
    const root = new URL("../resources/js/", import.meta.url);
    for (const file of readdirSync(root, { recursive: true })) {
        if (/\.(vue|ts)$/.test(file)) sources.push(new URL(file, root));
    }
    assert.ok(sources.length > 20);
    for (const source of sources) {
        assert.doesNotMatch(
            readFileSync(source, "utf8"),
            /Images\/services|ServiceBrandMark|serviceLogoSource|logoFile/,
            String(source),
        );
    }
});

const preparedServices = [
    ["dropbox-family", "Dropbox Family", "dropbox", "cloud"],
    ["bitwarden-families", "Bitwarden Families", "bitwarden", "shield"],
    ["nordpass-family", "NordPass Family", "nordpass", "shield"],
];

for (const [slug, name, alias, scene] of preparedServices) {
    test(`${name} resolves its name and scene by canonical key, name and alias`, () => {
        const brand = serviceBrand(slug);
        assert.ok(brand);
        assert.equal(brand.name, name);
        for (const lookup of [slug, name, alias, `  ${name.toUpperCase().replaceAll(" ", "\t ")}  `]) {
            assert.strictEqual(serviceBrand(lookup), brand);
            assert.equal(servicePresentation(lookup, "Streaming").scene, scene);
        }
        for (const color of [brand.accent, brand.deep, brand.ink]) {
            assert.match(color, /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i);
        }
        // A visual identity must not promise a commercial offer or available seats.
        assert.deepEqual(Object.keys(brand).sort(), ["accent", "deep", "ink", "name"]);
        assert.deepEqual(Object.keys(servicePresentation(slug, "")).sort(),
            scene === "cloud" ? ["palette", "scene", "tagline"] : ["scene", "tagline"]);
    });
}

test("legacy aliases share the canonical scene without changing category fallback", () => {
    for (const [alias, canonical] of [
        ["disney-plus", "disney"], ["Disney+", "disney"],
        ["paramount-plus", "paramount"], ["paramount+", "paramount"],
        ["canal-plus", "canal"], ["canal+", "canal"],
        ["apple-one", "apple-one-family"], ["cyber-ghost", "cyberghost"],
        ["prime-video", "amazon-prime"], ["nord-vpn", "nordvpn"],
    ]) {
        assert.strictEqual(serviceBrand(alias), serviceBrand(canonical));
        assert.deepEqual(servicePresentation(alias, "Streaming"), servicePresentation(canonical, "Streaming"));
    }
    for (const slug of ["", "   ", "__proto__", "constructor", "toString", "dropbox-business", "bitwarden-teams", "nordpass-business"]) {
        assert.equal(serviceBrand(slug), null);
        assert.equal(servicePresentation(slug, "Autre").scene, "world");
    }
});
