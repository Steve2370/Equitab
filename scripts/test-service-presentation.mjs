import { test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { inflateSync, crc32 } from "node:zlib";
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
test("each seeded service has a palette and a required local logo", () => {
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
        assert.ok(source, `Missing logo for ${slug}`);
        assert.ok(
            existsSync(new URL(`../public${source}`, import.meta.url)),
            source,
        );
    }
});
test("user-supplied images take priority without renaming or losing special characters", () => {
    for (const [slug, file] of [
        ["Crave", "crave.jpg"],
        ["Microsoft 365", "microsoft365.svg"],
        ["Readly", "readly.png"],
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

const preparedServices = [
    ["dropbox-family", "Dropbox Family", "dropbox", "dropbox.svg", "cloud"],
    ["bitwarden-families", "Bitwarden Families", "bitwarden", "bitwarden.svg", "shield"],
    ["nordpass-family", "NordPass Family", "nordpass", "nordpass.png", "shield"],
];

for (const [slug, name, alias, file, scene] of preparedServices) {
    test(`${name} resolves its supplied logo and scene by canonical key, name and alias`, () => {
        const brand = serviceBrand(slug);
        assert.ok(brand);
        assert.equal(brand.name, name);
        assert.equal(brand.logoWide, true);
        assert.equal(brand.logoScale ?? 1, 1, "Preserve the whole supplied mark without cropping");
        for (const lookup of [slug, name, alias, `  ${name.toUpperCase().replaceAll(" ", "\t ")}  `]) {
            assert.strictEqual(serviceBrand(lookup), brand);
            assert.equal(serviceLogoSource(serviceBrand(lookup)), `/Images/services/${file}`);
            assert.equal(servicePresentation(lookup, "Streaming").scene, scene);
        }
        assert.ok(existsSync(new URL(`../public/Images/services/${file}`, import.meta.url)));
        for (const color of [brand.accent, brand.deep, brand.ink]) {
            assert.match(color, /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i);
        }
        // A visual identity must not promise a commercial offer or available seats.
        assert.deepEqual(Object.keys(brand).sort(), ["accent", "deep", "ink", "logoFile", "logoWide", "name"].sort());
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
        assert.equal(serviceLogoSource(serviceBrand(slug)), null);
        assert.equal(servicePresentation(slug, "Autre").scene, "world");
    }
});

for (const file of ["dropbox.svg", "bitwarden.svg"]) {
    test(`${file} contains only passive, self-contained SVG artwork`, () => {
        const svg = readFileSync(new URL(`../public/Images/services/${file}`, import.meta.url), "utf8");
        assert.match(svg, /<svg\b[^>]*viewBox="0 0 [\d.]+ [\d.]+"/);
        assert.match(svg, /<path\b/);
        // Specific checks for these inspected assets, not a general SVG sanitizer.
        assert.doesNotMatch(svg, /<!DOCTYPE|<!ENTITY|<!\[CDATA\[|\son\w+\s*=|(?:href|src)\s*=|url\s*\(|@import|expression\s*\(|javascript:|<\?xml-stylesheet/i);
        const tags = [...svg.matchAll(/<\/?([a-z][\w:-]*)\b/gi)].map((match) => match[1]);
        for (const tag of tags) assert.ok(["svg", "style", "path", "g", "rect"].includes(tag), tag);
        assert.doesNotMatch(svg.replaceAll(/xmlns(?::xlink)?="[^"]+"/g, ""), /https?:|data:|file:|\/\//i);
    });
}

test("nordpass.png has intact PNG chunks and decodable RGBA scanlines", () => {
    const png = readFileSync(new URL("../public/Images/services/nordpass.png", import.meta.url));
    assert.equal(png.subarray(0, 8).toString("hex"), "89504e470d0a1a0a");
    const chunks = [];
    let offset = 8;
    while (offset < png.length) {
        assert.ok(offset + 12 <= png.length);
        const length = png.readUInt32BE(offset);
        const end = offset + 8 + length;
        assert.ok(end + 4 <= png.length);
        const type = png.toString("ascii", offset + 4, offset + 8);
        assert.equal(crc32(png.subarray(offset + 4, end)), png.readUInt32BE(end), `${type} CRC`);
        chunks.push({ type, data: png.subarray(offset + 8, end) });
        offset = end + 4;
    }
    assert.equal(chunks[0].type, "IHDR");
    assert.equal(chunks.at(-1).type, "IEND");
    const header = chunks[0].data;
    assert.equal(header.readUInt32BE(0), 2000);
    assert.equal(header.readUInt32BE(4), 442);
    assert.deepEqual([...header.subarray(8)], [8, 6, 0, 0, 0]);
    const pixels = inflateSync(Buffer.concat(chunks.filter(({ type }) => type === "IDAT").map(({ data }) => data)), { maxOutputLength: 4_000_000 });
    const stride = 2000 * 4 + 1;
    assert.equal(pixels.length, stride * 442);
    for (let row = 0; row < 442; row++) assert.ok(pixels[row * stride] <= 4, `Row ${row} filter`);
});
