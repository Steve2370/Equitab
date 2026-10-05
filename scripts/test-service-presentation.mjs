import { test } from "node:test";
import assert from "node:assert/strict";
import {
    servicePresentation,
    indicativeShare,
    formatCad,
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
