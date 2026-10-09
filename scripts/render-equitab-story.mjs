// Original, silent motion graphics. Rebuild with Node, @napi-rs/canvas, sharp and ffmpeg.
// EQUITAB_RENDER_MODULES may point to an existing node_modules directory.
import { createRequire } from "node:module";
import { mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { serviceBrand } from "../resources/js/config/servicePresentation.ts";
const require = createRequire(import.meta.url);
const { createCanvas, loadImage } = require(
    process.env.EQUITAB_RENDER_MODULES
        ? resolve(process.env.EQUITAB_RENDER_MODULES, "@napi-rs/canvas")
        : "@napi-rs/canvas",
);
const sharp = require(
    process.env.EQUITAB_RENDER_MODULES
        ? resolve(process.env.EQUITAB_RENDER_MODULES, "sharp")
        : "sharp",
);
async function loadOriginalAsset(path) {
    // Preserve SVG stylesheet classes and gradients when compositing into video.
    // Canvas's direct SVG decoder does not render all supplied marks faithfully.
    if (path.endsWith(".svg")) {
        return loadImage(
            await sharp(path)
                .resize({ width: 1024, height: 1024, fit: "inside" })
                .png()
                .toBuffer(),
        );
    }
    return loadImage(path);
}
const root = fileURLToPath(new URL("../", import.meta.url));
const originalLogo = await loadOriginalAsset(
    resolve(root, "public/Images/EquitabLogo.svg"),
);
// Like the site's cards: service names only, never a third-party logo.
const serviceNames = Object.fromEntries(
    Object.entries({
        music: "spotify",
        cinema: "netflix",
        world: "disney",
    }).map(([kind, slug]) => {
        const brand = serviceBrand(slug);
        if (!brand) throw new Error(`Missing story service identity: ${slug}`);
        return [kind, brand.name];
    }),
);
const out = resolve(root, "public/media");
await mkdir(out, { recursive: true });
const W = 1200,
    H = 720,
    FPS = 24,
    DURATION = 18;
const canvas = createCanvas(W, H),
    c = canvas.getContext("2d");
const INK = "#29312e",
    PAPER = "#e7f3ed";
function round(x, y, w, h, r, fill, stroke) {
    c.beginPath();
    c.roundRect(x, y, w, h, r);
    if (fill) {
        c.fillStyle = fill;
        c.fill();
    }
    if (stroke) {
        c.strokeStyle = stroke;
        c.lineWidth = 1.5;
        c.stroke();
    }
}
function circle(x, y, r, fill, stroke) {
    c.beginPath();
    c.arc(x, y, r, 0, Math.PI * 2);
    if (fill) {
        c.fillStyle = fill;
        c.fill();
    }
    if (stroke) {
        c.strokeStyle = stroke;
        c.lineWidth = 1.5;
        c.stroke();
    }
}
function text(
    value,
    x,
    y,
    size = 18,
    color = INK,
    weight = 500,
    align = "left",
    family = "Helvetica Neue, Arial",
) {
    c.font = `${weight} ${size}px ${family}`;
    c.fillStyle = color;
    c.textAlign = align;
    c.fillText(value, x, y);
}
function gradient(x, y, r, colors) {
    const g = c.createRadialGradient(x - r * 0.2, y - r * 0.3, 2, x, y, r);
    colors.forEach((color, i) =>
        g.addColorStop(i / (colors.length - 1), color),
    );
    return g;
}
function shadow(on = true) {
    c.shadowColor = on ? "#23342525" : "transparent";
    c.shadowBlur = on ? 30 : 0;
    c.shadowOffsetY = on ? 18 : 0;
}
function check(x, y, size = 1, color = "#187a57") {
    c.save();
    c.translate(x, y);
    c.scale(size, size);
    c.beginPath();
    c.moveTo(-7, 0);
    c.lineTo(-1, 6);
    c.lineTo(10, -7);
    c.strokeStyle = color;
    c.lineWidth = 3;
    c.lineCap = "round";
    c.stroke();
    c.restore();
}
function arrow(x, y, size = 12, color = INK) {
    c.save();
    c.translate(x, y);
    c.strokeStyle = color;
    c.lineWidth = 1.8;
    c.lineCap = "round";
    c.lineJoin = "round";
    c.beginPath();
    c.moveTo(-size / 2, size / 2);
    c.lineTo(size / 2, -size / 2);
    c.moveTo(-size / 2, -size / 2);
    c.lineTo(size / 2, -size / 2);
    c.lineTo(size / 2, size / 2);
    c.stroke();
    c.restore();
}
function star(x, y, radius, color) {
    c.save();
    c.translate(x, y);
    c.beginPath();
    for (let n = 0; n < 8; n++) {
        const a = (n * Math.PI) / 4,
            r = n % 2 ? radius * 0.23 : radius;
        if (n === 0) c.moveTo(Math.cos(a) * r, Math.sin(a) * r);
        else c.lineTo(Math.cos(a) * r, Math.sin(a) * r);
    }
    c.closePath();
    c.fillStyle = color;
    c.fill();
    c.restore();
}
function art(kind, x, y, s = 1) {
    c.save();
    c.translate(x, y);
    c.scale(s, s);
    if (kind === "music") {
        c.save();
        c.rotate(-0.15);
        shadow();
        round(-95, -85, 134, 155, 5, "#1ed760");
        shadow(false);
        text("ON", -77, -48, 26, "#294a35", 700);
        text("REJOUE.", -77, -17, 26, "#294a35", 700);
        circle(-58, 30, 24, null, "#58734a");
        circle(-47, 30, 24, null, "#58734a");
        c.restore();
        shadow();
        circle(38, 9, 84, "#18251e");
        shadow(false);
        for (let r = 36; r < 83; r += 4)
            circle(38, 9, r, null, r % 8 ? "#334039" : "#24322a");
        // Neutral record label, like the site's "33" vinyl (no service logo).
        circle(38, 9, 31, "#fff");
        text("33", 38, 4, 17, "#18251e", 700, "center");
        circle(38, 20, 4, "#18251e");
    } else if (kind === "cinema") {
        for (const [x, y, s] of [
            [-32, -27, 0.85],
            [31, 10, 1],
        ]) {
            c.save();
            c.translate(x, y);
            c.scale(s, s);
            shadow();
            circle(
                0,
                0,
                74,
                gradient(-8, -15, 95, ["#fff3dd", "#d5bba8", "#9d8378"]),
                "#e9d7c5",
            );
            shadow(false);
            for (let n = 0; n < 5; n++) {
                const a = (n * Math.PI * 2) / 5;
                circle(Math.sin(a) * 45, Math.cos(a) * 45, 18, "#75080e");
            }
            circle(0, 0, 8, "#75080e");
            c.restore();
        }
        c.save();
        c.translate(-33, 60);
        c.rotate(-0.18);
        shadow();
        round(-75, -25, 156, 70, 5, "#fff0d2");
        shadow(false);
        text("LE GRAND ÉCRAN", -59, -5, 10, "#76453b");
        text("À partager.", -59, 23, 24, "#643b31", 500, "left", "Georgia");
        c.restore();
    } else {
        shadow();
        circle(
            0,
            0,
            75,
            gradient(-10, -18, 109, ["#e1fff7", "#65d8cc", "#005458"]),
        );
        shadow(false);
        c.save();
        c.rotate(-0.35);
        c.beginPath();
        c.ellipse(0, 0, 113, 32, 0, 0, Math.PI * 2);
        c.strokeStyle = "#dadffd";
        c.lineWidth = 2;
        c.stroke();
        c.restore();
        circle(
            -92,
            57,
            15,
            gradient(-96, 52, 27, ["#fce5ae", "#d7aa76", "#9c8b85"]),
        );
        star(94, -59, 13, "#eff2ff");
    }
    c.restore();
}
function card(kind, x, y, rotation, scale = 1, alpha = 1) {
    c.save();
    c.globalAlpha *= alpha;
    c.translate(x, y);
    c.rotate(rotation);
    c.scale(scale, scale);
    shadow();
    round(-139, -175, 278, 350, 23, "#fff", "#d8ded1");
    shadow(false);
    const color =
        kind === "music"
            ? "#087d38"
            : kind === "cinema"
              ? "#ae0710"
              : "#006c70";
    round(-131, -167, 262, 232, 17, color);
    text(serviceNames[kind].toUpperCase(), -111, -140, 10, "#fff", 600);
    art(kind, 0, -35, 0.94);
    text(
        kind === "music"
            ? "Votre prochaine écoute."
            : kind === "cinema"
              ? "Une histoire à partager."
              : "Un monde en commun.",
        -115,
        103,
        17,
        INK,
        600,
    );
    text("Un abonnement. Plusieurs envies.", -115, 131, 11, "#7b817a");
    circle(107, 133, 18, "#28372d");
    arrow(107, 133, 13, "#fff");
    c.restore();
}
function background(t) {
    c.fillStyle = PAPER;
    c.fillRect(0, 0, W, H);
    const glow = c.createRadialGradient(600, 290, 30, 600, 350, 570);
    glow.addColorStop(0, "#fafbf6");
    glow.addColorStop(1, PAPER);
    c.fillStyle = glow;
    c.fillRect(0, 0, W, H);
    c.drawImage(originalLogo, 36, 30, 142, 33);
    c.save();
    c.translate(600, 365);
    c.rotate(-0.1);
    c.strokeStyle = "#d8dfd080";
    c.lineWidth = 1.2;
    for (const r of [220, 310, 440]) {
        c.beginPath();
        c.ellipse(0, 0, r, r * 0.57, 0, 0, Math.PI * 2);
        c.stroke();
    }
    c.restore();
    circle(147, 200 + Math.sin(t) * 6, 4, "#a1d2b7");
    circle(1040, 470 + Math.cos(t) * 8, 5, "#b8d4c7");
    star(960, 131 + Math.sin(t * 0.7) * 8, 14, "#35af7f");
}
function choose(t) {
    card("cinema", 348, 343 + Math.sin(t) * 7, -0.17, 0.87);
    card("world", 852, 341 + Math.cos(t) * 7, 0.17, 0.87);
    card("music", 600, 327 + Math.sin(t + 0.5) * 10, 0.015 * Math.sin(t), 1.08);
    round(454, 544, 292, 43, 22, "#dff3e9", "#b8ddc9");
    circle(476, 565, 5, "#35af7f");
    text("Trouvez ce qui vous ressemble.", 493, 571, 15, "#187a57");
}
function avatar(x, y, label, color, scale = 1) {
    c.save();
    c.translate(x, y);
    c.scale(scale, scale);
    shadow();
    circle(0, 0, 37, color, "#fff");
    shadow(false);
    text(label, 0, 9, 24, "#35413c", 600, "center");
    circle(26, 25, 12, "#dff3e9", "#fff");
    check(26, 25, 0.65);
    c.restore();
}
function group(t) {
    const p = Math.min(1, t / 2),
        ease = 1 - Math.pow(1 - p, 3);
    for (const [x, y] of [
        [287, 304],
        [897, 266],
        [867, 491],
    ]) {
        c.beginPath();
        c.moveTo(600, 343);
        c.quadraticCurveTo(x, 343, x, y);
        c.strokeStyle = "#aed4bf";
        c.lineWidth = 2;
        c.setLineDash([5, 7]);
        c.lineDashOffset = -t * 10;
        c.stroke();
        c.setLineDash([]);
    }
    card("music", 584, 338, Math.sin(t) * 0.03, 0.88);
    avatar(287 + (1 - ease) * 180, 304, "A", "#e6d8f0", ease);
    avatar(897 - (1 - ease) * 180, 266, "B", "#f0c9ad", ease);
    avatar(867 - (1 - ease) * 130, 491, "C", "#b8e7cc", ease);
    round(166, 403, 228, 78, 17, "#fff", "#dce1d5");
    text("Un groupe, en commun.", 185, 434, 17, INK, 600);
    text("Des personnes réunies autour", 185, 456, 12, "#717b6d");
    text("d’un même abonnement.", 185, 474, 12, "#717b6d");
    round(487, 533, 226, 43, 22, "#dff3e9", "#b8ddc9");
    text(
        "Partagez les frais du groupe.",
        600,
        560,
        14,
        "#187a57",
        500,
        "center",
    );
}
function budget(t) {
    c.save();
    c.translate(592, 326 + Math.sin(t) * 5);
    c.rotate(-0.04);
    shadow();
    round(-204, -180, 408, 361, 24, "#fff", "#d9e0d1");
    shadow(false);
    text("VOTRE ESPACE EQUITAB", -177, -142, 11, "#7d8578", 600);
    text("Chacun sa part.", -177, -103, 32, INK, 600);
    const lines = [
        ["Mes groupes", "Ensemble, au même endroit."],
        ["Ma part", "Une contribution à suivre."],
        ["Mes paiements", "Les prochaines échéances."],
    ];
    for (let i = 0; i < 3; i++) {
        const y = -59 + i * 74;
        round(-181, y, 362, 67, 12, i === 1 ? "#dff3e9" : "#f6f7f1");
        circle(-153, y + 32, 15, i === 1 ? "#a0ddbb" : "#d1e8dc");
        check(-153, y + 32, 0.7);
        text(lines[i][0], -124, y + 28, 16, INK, 600);
        text(lines[i][1], -124, y + 48, 12, "#78816d");
        arrow(152, y + 32, 12, "#667754");
    }
    c.restore();
    avatar(318, 255, "A", "#e6d8f0");
    avatar(856, 244, "B", "#f0c9ad");
    avatar(875, 456, "C", "#b8e7cc");
    round(404, 547, 394, 44, 22, "#dff3e9", "#b8ddc9");
    text(
        "Les abonnements partagés, l’esprit plus léger.",
        600,
        575,
        15,
        "#187a57",
        500,
        "center",
    );
}
function render(t) {
    background(t);
    const phase = Math.min(2, Math.floor(t / 6)),
        local = t % 6;
    const fade = Math.min(1, local / 0.4, (6 - local) / 0.4);
    c.save();
    c.globalAlpha = Math.max(0, fade);
    [choose, group, budget][phase](local);
    c.restore();
    const titles = [
        "01  /  CHOISIR UN SERVICE",
        "02  /  REJOINDRE UN GROUPE",
        "03  /  SUIVRE SA PART",
    ];
    text(titles[phase], 600, 655, 13, "#6e7967", 600, "center");
    for (let i = 0; i < 3; i++) {
        round(552 + i * 35, 681, 26, 3, 2, "#d7dece");
        if (i === phase)
            round(552 + i * 35, 681, 26 * (local / 6), 3, 2, "#187a57");
    }
}
const ffmpeg = spawn(
    "ffmpeg",
    [
        "-hide_banner",
        "-loglevel",
        "error",
        "-f",
        "image2pipe",
        "-framerate",
        String(FPS),
        "-i",
        "pipe:0",
        "-an",
        "-c:v",
        "libx264",
        "-preset",
        "medium",
        "-crf",
        "23",
        "-pix_fmt",
        "yuv420p",
        "-movflags",
        "+faststart",
        "-y",
        resolve(out, "equitab-story.mp4"),
    ],
    { stdio: ["pipe", "inherit", "inherit"] },
);
const finished = once(ffmpeg, "close");
ffmpeg.stdin.on("error", (error) => {
    console.error(error.message);
    process.exitCode = 1;
});
for (let frame = 0; frame < FPS * DURATION; frame++) {
    render(frame / FPS);
    const png = canvas.toBuffer("image/png");
    if (frame === 72)
        await writeFile(resolve(out, "equitab-story-poster.png"), png);
    if (!ffmpeg.stdin.write(png)) await once(ffmpeg.stdin, "drain");
}
ffmpeg.stdin.end();
const [exitCode] = await finished;
if (exitCode !== 0) throw new Error("Video rendering failed: " + exitCode);
console.log(
    "Created an original 18-second Equitab explainer and poster in public/media.",
);
