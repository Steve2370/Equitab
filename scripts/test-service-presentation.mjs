import { test } from "node:test";
import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import {
    servicePresentation,
    indicativeShare,
    formatCad,
    serviceBrand,
    serviceLogoSource,
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
    assert.equal(serviceBrand("NordVPN").logo, "nordvpn");
    assert.equal(serviceBrand("Microsoft 365").name, "Microsoft 365");
    assert.equal(serviceBrand("nouveau-service"), null);
    assert.equal(serviceBrand("__proto__"), null);
});
test("each seeded service has a palette and every configured logo exists locally", () => {
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
        const source = serviceLogoSource(brand);
        if (source)
            assert.ok(
                existsSync(new URL(`../public${source}`, import.meta.url)),
                source,
            );
    }
});
test("user-supplied images take priority without renaming or losing special characters", () => {
    for (const [slug, file] of [
        ["Disney+", "Disney+.png"],
        ["canal-plus", "canal.png"],
        ["deezer", "deezer-logo.png"],
        ["cyberghost", "cyberghost.png"],
        [
            "xbox-game-pass",
            "Xbox_Game_Pass_2020_logo_-_colored_version.svg.webp",
        ],
    ]) {
        assert.equal(
            serviceLogoSource(serviceBrand(slug)),
            `/Images/services/${file}`,
        );
    }
    assert.equal(
        serviceLogoSource(serviceBrand("spotify")),
        "/Images/services/spotify.svg",
    );
    assert.equal(serviceLogoSource(serviceBrand("nouveau-service")), null);
});
