<script setup lang="ts">
import type { ArtworkScene } from "@/config/servicePresentation";
import { serviceBrand } from "@/config/servicePresentation";
import { computed } from "vue";
import ServiceBrandMark from "./ServiceBrandMark.vue";
const props = withDefaults(
    defineProps<{
        scene: ArtworkScene;
        category: string;
        tagline: string;
        index?: string;
        palette?: string;
        motion?: boolean;
        slug?: string;
    }>(),
    { index: "", palette: "", motion: true, slug: "" },
);
const brand = computed(() => serviceBrand(props.slug));
const brandStyle = computed(() =>
    brand.value
        ? {
              "--service-accent": brand.value.accent,
              "--service-deep": brand.value.deep,
              "--service-ink": brand.value.ink,
          }
        : undefined,
);
</script>
<template>
    <div
        class="artwork-host"
        :style="brandStyle"
        :data-service="slug || undefined"
        :class="[
            'theme-' + scene,
            'palette-' + palette,
            { 'motion-enabled': motion, 'service-branded': !!brand },
        ]"
    >
        <div class="card-scene">
            <div class="scene-topline">
                <span>{{ category }}</span
                ><ServiceBrandMark v-if="brand" :slug="slug" />
                <span v-else class="edition-label"
                    >COLLECTION / {{ index }}</span
                >
            </div>
            <slot />
            <div class="scene-object" aria-hidden="true">
                <template v-if="scene === 'music'"
                    ><div class="record-sleeve">
                        <span>ON<br />REJOUE.</span><i />
                    </div>
                    <div class="vinyl">
                        <div class="vinyl-label"><span>33</span><i /></div>
                    </div>
                    <div class="sound-bars">
                        <i
                            v-for="n in 9"
                            :key="n"
                            :style="{ '--bar': n }"
                        /></div
                ></template>
                <template v-else-if="scene === 'cinema'"
                    ><div class="film-reel reel-back">
                        <i
                            v-for="n in 5"
                            :key="n"
                            :style="{ '--hole': n }"
                        /><b />
                    </div>
                    <div class="film-reel reel-front">
                        <i
                            v-for="n in 5"
                            :key="n"
                            :style="{ '--hole': n }"
                        /><b />
                    </div>
                    <div class="cinema-ticket">
                        <span>LE GRAND ÉCRAN</span><strong>À partager.</strong
                        ><i /><small>ENTRÉE / ENSEMBLE</small>
                    </div></template
                >
                <template v-else-if="scene === 'world'"
                    ><div class="orbit orbit-one" />
                    <div class="orbit orbit-two" />
                    <div class="planet"><i /></div>
                    <div class="moon" />
                    <span class="star star-one">✦</span
                    ><span class="star star-two">✧</span>
                    <div class="world-label">
                        UN MONDE<br />EN COMMUN.
                    </div></template
                >
                <template v-else-if="scene === 'cloud'"
                    ><div class="cloud-folder folder-back" />
                    <div class="cloud-folder folder-front">
                        <span>TOUT<br />À SA PLACE.</span>
                        <div class="cloud-bubble"><i /><i /><i /></div>
                    </div>
                    <div class="storage-chip">∞</div></template
                >
                <template v-else-if="scene === 'play'"
                    ><div class="player-back" />
                    <div class="player-window">
                        <div class="player-triangle" />
                        <div class="player-timeline"><i /></div>
                        <span>ENCORE UN ÉPISODE.</span>
                    </div>
                    <div class="play-disc"
                /></template>
                <template v-else-if="scene === 'arcade'"
                    ><div class="game-orbit" />
                    <div class="gamepad">
                        <div class="game-cross" />
                        <div class="game-buttons"><i /><i /><i /><i /></div>
                        <span>À VOUS DE JOUER.</span>
                    </div></template
                >
                <template v-else-if="scene === 'shield'"
                    ><div class="shield-orbit" />
                    <svg
                        class="shield-object"
                        viewBox="0 0 160 180"
                        fill="none"
                    >
                        <path
                            d="M80 6 147 31v56c0 47-40 75-67 88C53 162 13 134 13 87V31Z"
                            fill="#c4d9ef"
                            stroke="#edf7ff"
                            stroke-width="3"
                        />
                        <path
                            d="M80 22 130 42v43c0 36-29 58-50 71"
                            fill="#8baecb"
                        />
                        <rect
                            x="56"
                            y="76"
                            width="48"
                            height="41"
                            rx="10"
                            fill="#283f57"
                        />
                        <path
                            d="M65 76V65a15 15 0 0 1 30 0v11"
                            stroke="#283f57"
                            stroke-width="7"
                        />
                        <circle cx="80" cy="94" r="4" fill="#e4edcd" /></svg
                ></template>
                <template v-else
                    ><div class="book-back" />
                    <div class="book-cover">
                        <span>UN PEU<br />PLUS<br /><em>loin.</em></span
                        ><i />
                    </div>
                    <div class="book-sticker">✦</div></template
                >
            </div>
            <div class="scene-caption">
                <span>{{ tagline }}</span
                ><span v-if="index" class="scene-index">{{ index }}</span>
            </div>
        </div>
    </div>
</template>
<style scoped>
.card-scene {
    height: 250px;
    border-radius: 23px 23px 16px 16px;
    margin: 5px;
    position: relative;
    overflow: hidden;
    isolation: isolate;
    perspective: 800px;
}
.theme-cinema .card-scene {
    background: radial-gradient(
        ellipse at 55% 10%,
        #fc9877,
        #d8513c 65%,
        #b93533
    );
    color: #4a1518;
}
.theme-music .card-scene {
    background: radial-gradient(
        ellipse at 20% 10%,
        #488153,
        #183f2d 60%,
        #102d23
    );
    color: #e4f8bd;
}
.theme-world .card-scene {
    background: radial-gradient(
        ellipse at 60% 15%,
        #6a70d3,
        #343988 60%,
        #1e265b
    );
    color: #ececff;
}
.artwork-host.service-branded .card-scene {
    background: radial-gradient(
        ellipse at 18% 0%,
        var(--service-accent),
        var(--service-deep) 85%
    );
    color: #fff;
}
.artwork-host.service-branded .scene-topline {
    justify-content: space-between;
    top: 14px;
}
.artwork-host.service-branded .scene-topline > span:first-child {
    color: var(--service-ink);
}
.artwork-host.service-branded .scene-caption {
    text-shadow: 0 1px 8px #000;
}
.artwork-host.service-branded .scene-object {
    top: 45px;
}
.artwork-host.service-branded .record-sleeve,
.artwork-host.service-branded .vinyl-label {
    background: var(--service-accent);
    color: var(--service-ink);
}
.artwork-host.service-branded .record-sleeve i {
    border-color: currentColor;
    box-shadow:
        8px 0 0 -1px var(--service-accent),
        9px 0 0 currentColor;
}
.artwork-host.service-branded .cinema-ticket {
    color: var(--service-deep);
    background: #fff5ec;
}
.artwork-host.service-branded .film-reel i,
.artwork-host.service-branded .film-reel b {
    background: var(--service-deep);
}
.artwork-host.service-branded .planet {
    background: radial-gradient(
        circle at 28% 22%,
        #fff,
        var(--service-accent) 34%,
        var(--service-deep) 78%
    );
}
.artwork-host.service-branded .cloud-folder {
    background: color-mix(in srgb, var(--service-accent) 20%, white);
    color: var(--service-deep);
}
.artwork-host.service-branded .book-cover {
    background: var(--service-accent);
    color: var(--service-ink);
}
.artwork-host.service-branded .game-buttons i {
    background: var(--service-accent);
}
.scene-topline {
    position: absolute;
    z-index: 2;
    left: 20px;
    right: 20px;
    top: 20px;
    display: flex;
    gap: 12px;
    align-items: center;
}
.scene-topline > span:first-child {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}
.edition-label {
    display: none;
}
.scene-object {
    position: absolute;
    inset: 32px 0 46px;
    transform-style: preserve-3d;
    transform: rotateX(var(--scene-x)) rotateY(var(--scene-y));
    transition: transform 0.25s ease-out;
    pointer-events: none;
}
.scene-caption {
    position: absolute;
    bottom: 17px;
    left: 20px;
    right: 20px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: end;
    font-size: 11px;
    font-weight: 500;
}
.scene-index {
    font-size: 22px;
    font-weight: 600;
    opacity: 0.65;
    letter-spacing: -0.08em;
}
.record-sleeve {
    position: absolute;
    top: 16px;
    left: calc(50% - 108px);
    width: 145px;
    height: 155px;
    padding: 19px;
    background: #dcf790;
    color: #153c29;
    border-radius: 4px;
    transform: rotate(-12deg);
    box-shadow: 0 18px 22px -12px #0009;
}
.record-sleeve span {
    font-size: 22px;
    font-weight: 800;
    line-height: 0.95;
    letter-spacing: -0.07em;
}
.record-sleeve i {
    display: block;
    width: 50px;
    height: 50px;
    margin-top: 14px;
    border: 1px solid #153c29;
    border-radius: 50%;
    box-shadow:
        8px 0 0 -1px #dcf790,
        9px 0 0 #153c29,
        16px 0 0 -1px #dcf790,
        17px 0 0 #153c29;
}
.vinyl {
    position: absolute;
    width: 166px;
    height: 166px;
    left: calc(50% - 28px);
    top: 21px;
    border-radius: 50%;
    background: repeating-radial-gradient(
        circle,
        #101514 0 1px,
        #2a302c 2px 3px,
        #151b18 4px 6px
    );
    border: 3px solid #0f1815;
    box-shadow: 8px 17px 20px -8px #000a;
    transform: rotate(12deg);
}
.vinyl::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: conic-gradient(
        from 12deg,
        transparent 30deg,
        #ffffff35 55deg,
        transparent 95deg,
        transparent 195deg,
        #ffffff25 230deg,
        transparent 270deg
    );
}
.vinyl-label {
    position: absolute;
    inset: 48px;
    display: grid;
    place-items: center;
    background: #ccea97;
    border-radius: 50%;
    color: #264530;
    border: 1px solid #fafde5;
}
.vinyl-label span {
    font-family: Georgia, serif;
    font-size: 42px;
    font-style: italic;
    margin-top: -7px;
}
.vinyl-label i {
    position: absolute;
    width: 7px;
    height: 7px;
    background: #141918;
    border-radius: 50%;
}
.sound-bars {
    position: absolute;
    bottom: 0;
    left: 30px;
    height: 18px;
    display: flex;
    align-items: center;
    gap: 3px;
}
.sound-bars i {
    width: 3px;
    height: calc(6px + mod(var(--bar), 4) * 3px);
    min-height: 8px;
    background: #cbea93;
    border-radius: 3px;
}
.film-reel {
    position: absolute;
    width: 154px;
    height: 154px;
    border: 5px solid #e1d7c9;
    border-radius: 50%;
    background: radial-gradient(
        circle at 35% 25%,
        #fffbef,
        #cbb5a8 55%,
        #9c7f77
    );
    box-shadow:
        inset 0 0 0 3px #927f7645,
        4px 16px 20px -9px #521817a8;
}
.film-reel i {
    position: absolute;
    width: 40px;
    height: 40px;
    left: 52px;
    top: 52px;
    border-radius: 50%;
    background: #993831;
    box-shadow: inset 2px 3px 4px #511411;
    transform: rotate(calc(var(--hole) * 72deg)) translateY(-43px);
}
.film-reel b {
    position: absolute;
    inset: 63px;
    background: #8f3931;
    border-radius: 50%;
    box-shadow: inset 1px 2px 2px #55271f;
}
.reel-back {
    left: calc(50% - 99px);
    top: 0;
    transform: scale(0.88) rotate(-18deg);
    filter: brightness(0.82);
}
.reel-front {
    left: calc(50% - 29px);
    top: 28px;
    transform: rotate(18deg);
}
.cinema-ticket {
    position: absolute;
    left: calc(50% - 115px);
    top: 78px;
    width: 166px;
    padding: 13px 17px 10px;
    background: #fff3d6;
    border-radius: 5px;
    transform: rotate(-12deg);
    box-shadow: 2px 12px 20px -8px #672524aa;
}
.cinema-ticket span {
    display: block;
    font-size: 8px;
    letter-spacing: 0.13em;
}
.cinema-ticket strong {
    display: block;
    font-family: Georgia, serif;
    font-size: 24px;
    font-style: italic;
    font-weight: 400;
    line-height: 1.3;
}
.cinema-ticket i {
    display: block;
    margin-block: 7px;
    border-top: 1px dashed #ac7869;
}
.cinema-ticket small {
    display: block;
    font-size: 7px;
    letter-spacing: 0.2em;
}
.planet {
    position: absolute;
    width: 142px;
    height: 142px;
    left: calc(50% - 60px);
    top: 22px;
    border-radius: 50%;
    background: radial-gradient(
        circle at 28% 22%,
        #e2e7ff,
        #afb5f8 18%,
        #7375cd 40%,
        #414879 65%,
        #252e59
    );
    box-shadow:
        inset -9px -11px 19px #141d4244,
        inset 2px 1px 2px #f0f1ffb0,
        20px 25px 36px -12px #0c174fcc;
}
.planet i {
    position: absolute;
    inset: 12px 23px 22px 15px;
    border-radius: 50%;
    border-top: 2px solid #ffffff25;
    transform: rotate(-30deg);
}
.orbit {
    position: absolute;
    width: 240px;
    height: 65px;
    left: calc(50% - 112px);
    top: 63px;
    border: 1px solid #c8c8ff88;
    border-bottom: 3px solid #c8c8ff;
    border-radius: 50%;
    transform: rotate(-27deg);
}
.orbit-one {
    z-index: 2;
    border-top-color: transparent;
}
.orbit-two {
    width: 180px;
    height: 205px;
    left: calc(50% - 80px);
    top: -10px;
    border: 1px solid #b8c2ff44;
    transform: rotate(35deg);
}
.moon {
    position: absolute;
    width: 29px;
    height: 29px;
    left: calc(50% - 100px);
    top: 120px;
    border-radius: 50%;
    background: radial-gradient(
        circle at 25% 25%,
        #fdf1c2,
        #e1b37a 50%,
        #6c6282
    );
    box-shadow: 5px 8px 12px #19255888;
    z-index: 3;
}
.star {
    position: absolute;
    color: #eef1ff;
}
.star-one {
    right: 25px;
    top: 45px;
    font-size: 25px;
}
.star-two {
    left: 35px;
    top: 5px;
    font-size: 17px;
}
.world-label {
    position: absolute;
    left: 22px;
    bottom: 0;
    font-size: 9px;
    letter-spacing: 0.16em;
    line-height: 1.4;
}

.artwork-host {
    position: relative;
    min-width: 0;
}
.card-scene {
    color: #28313a;
}
.theme-cloud .card-scene {
    background: radial-gradient(ellipse at 25% 0%, #eddcc4, #c3a087);
    color: #443426;
}
.theme-play .card-scene {
    background: radial-gradient(ellipse at 30% 0%, #f9967e, #c13e39);
    color: #481d1b;
}
.theme-arcade .card-scene {
    background: radial-gradient(ellipse at 30% 0%, #cedd9d, #758c4c);
    color: #23391f;
}
.theme-shield .card-scene {
    background: radial-gradient(ellipse at 30% 0%, #638798, #293f55);
    color: #edf5ff;
}
.theme-book .card-scene {
    background: radial-gradient(ellipse at 30% 0%, #e5c985, #b5904d);
    color: #483717;
}
.palette-rose .card-scene {
    background: radial-gradient(ellipse at 25% 0%, #e99bad, #a64763);
    color: #351a29;
}
.palette-rose .record-sleeve,
.palette-rose .vinyl-label {
    background: #f8dfdf;
}
.palette-ice .card-scene {
    background: radial-gradient(ellipse at 25% 0%, #b7d2e0, #628aa8);
    color: #203344;
}
.palette-violet .card-scene {
    background: radial-gradient(ellipse at 25% 0%, #b7a3d1, #726382);
    color: #251d3b;
}
.cloud-folder {
    position: absolute;
    width: 180px;
    height: 134px;
    left: calc(50% - 90px);
    top: 45px;
    border-radius: 9px 18px 18px 18px;
    background: #ebddd0;
    box-shadow: 8px 18px 23px -12px #4b312b66;
}
.cloud-folder::before {
    content: "";
    position: absolute;
    width: 70px;
    height: 16px;
    left: 0;
    top: -10px;
    border-radius: 8px 8px 0 0;
    background: inherit;
}
.folder-back {
    transform: rotate(12deg) translate(15px, -17px);
    background: #9185b2;
}
.folder-front {
    transform: rotate(-10deg);
    border: 1px solid #fff7;
}
.folder-front > span {
    position: absolute;
    left: 18px;
    top: 18px;
    font-size: 13px;
    font-weight: 650;
    line-height: 1.2;
    letter-spacing: -0.04em;
}
.cloud-bubble {
    position: absolute;
    bottom: 25px;
    right: 23px;
    width: 82px;
    height: 28px;
    border-radius: 30px;
    background: #fff9ed;
    filter: drop-shadow(1px 5px 5px #59453125);
}
.cloud-bubble i {
    position: absolute;
    bottom: 8px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #fff9ed;
}
.cloud-bubble i:nth-child(2) {
    width: 47px;
    height: 47px;
    left: 23px;
}
.cloud-bubble i:nth-child(3) {
    left: 47px;
    bottom: 0;
}
.storage-chip {
    position: absolute;
    bottom: 0;
    left: calc(50% - 100px);
    width: 51px;
    height: 51px;
    background: #d9eea4;
    display: grid;
    place-items: center;
    border-radius: 15px;
    font-size: 38px;
    transform: rotate(-12deg);
    box-shadow: 0 6px 12px #55452b33;
}
.player-back,
.player-window {
    position: absolute;
    left: calc(50% - 93px);
    top: 35px;
    width: 194px;
    height: 138px;
    border-radius: 13px;
    background: #f6e7d9;
    box-shadow: 0 13px 25px #50181144;
    transform: rotate(-10deg);
}
.player-back {
    background: #822e31;
    transform: rotate(12deg) translate(8px, -12px);
}
.player-window {
    border: 1px solid #fffa;
}
.player-triangle {
    position: absolute;
    left: 79px;
    top: 25px;
    width: 0;
    height: 0;
    border-left: 38px solid #bc443e;
    border-top: 23px solid transparent;
    border-bottom: 23px solid transparent;
}
.player-timeline {
    position: absolute;
    left: 20px;
    right: 20px;
    bottom: 42px;
    height: 3px;
    border-radius: 4px;
    background: #d9c6bb;
}
.player-timeline i {
    display: block;
    width: 60%;
    height: 100%;
    background: #b9463a;
}
.player-window span {
    position: absolute;
    left: 20px;
    bottom: 18px;
    font-size: 8px;
    letter-spacing: 0.12em;
}
.play-disc {
    position: absolute;
    left: calc(50% + 58px);
    top: 138px;
    width: 40px;
    height: 40px;
    background: radial-gradient(
        circle,
        #5e3833 10%,
        #f9dab4 12%,
        #e5b890 65%,
        #faf3dc 70%
    );
    border-radius: 50%;
    box-shadow: 0 8px 12px #6a242844;
}
.game-orbit,
.shield-orbit {
    position: absolute;
    width: 215px;
    height: 165px;
    left: calc(50% - 107px);
    top: 10px;
    border: 1px solid #faffce66;
    border-radius: 50%;
    transform: rotate(-25deg);
}
.gamepad {
    position: absolute;
    left: calc(50% - 100px);
    top: 40px;
    width: 200px;
    height: 125px;
    background: linear-gradient(150deg, #faf6e9, #c6cdb7);
    border: 2px solid #fffffa;
    border-radius: 42px 42px 25px 25px;
    box-shadow:
        7px 17px 20px #344a3855,
        inset -4px -5px 2px #798a7266;
    transform: rotate(-12deg);
}
.game-cross {
    position: absolute;
    top: 34px;
    left: 30px;
    width: 45px;
    height: 15px;
    background: #445542;
    border-radius: 4px;
}
.game-cross::after {
    content: "";
    position: absolute;
    width: 15px;
    height: 45px;
    left: 15px;
    top: -15px;
    background: inherit;
    border-radius: 4px;
}
.game-buttons {
    position: absolute;
    top: 21px;
    right: 31px;
    display: grid;
    grid-template-columns: 12px 12px;
    gap: 7px;
    transform: rotate(-45deg);
}
.game-buttons i {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #819967;
}
.game-buttons i:nth-child(2) {
    background: #d79d80;
}
.gamepad span {
    position: absolute;
    bottom: 24px;
    width: 100%;
    text-align: center;
    font-size: 8px;
    letter-spacing: 0.13em;
}
.shield-object {
    position: absolute;
    left: calc(50% - 73px);
    top: 5px;
    height: 180px;
    width: 160px;
    transform: rotate(-10deg);
    filter: drop-shadow(9px 16px 9px #152e4280);
}
.book-cover,
.book-back {
    position: absolute;
    width: 128px;
    height: 171px;
    left: calc(50% - 65px);
    top: 6px;
    border-radius: 4px 9px 9px 4px;
    background: linear-gradient(
        90deg,
        #476254 0 7px,
        #315848 8px 11px,
        #416951 12px
    );
    color: #f3e7b4;
    border-right: 5px solid #ede4cf;
    padding: 20px 23px;
    transform: rotate(-13deg);
    box-shadow: 10px 17px 19px #70512355;
}
.book-cover span {
    font-size: 21px;
    font-weight: 500;
    line-height: 1.05;
    letter-spacing: -0.05em;
}
.book-cover em {
    font-family: Georgia, serif;
    font-size: 41px;
}
.book-back {
    background: #dfb5a0;
    transform: rotate(9deg) translate(15px, -7px);
}
.book-sticker {
    position: absolute;
    left: calc(50% + 45px);
    top: 134px;
    width: 49px;
    height: 49px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #f1da80;
    color: #494d35;
    font-size: 35px;
    box-shadow: 1px 5px 10px #64512e33;
}
@media (prefers-reduced-motion: no-preference) and (hover: hover) {
    .motion-enabled:hover .vinyl {
        transform: rotate(32deg) translateX(5px);
    }
    .motion-enabled:hover .cinema-ticket {
        transform: rotate(-6deg) translateY(-7px);
    }
    .motion-enabled:hover .moon {
        transform: translate(9px, -6px);
    }
    .motion-enabled:hover .folder-front {
        transform: rotate(-6deg) translateY(-5px);
    }
    .motion-enabled:hover .player-window {
        transform: rotate(-4deg) translateY(-4px);
    }
    .motion-enabled:hover .gamepad,
    .motion-enabled:hover .book-cover {
        transform: rotate(-7deg) translateY(-5px);
    }
    .vinyl,
    .cinema-ticket,
    .moon,
    .folder-front,
    .player-window,
    .gamepad,
    .book-cover {
        transition: transform 0.6s cubic-bezier(0.22, 0.61, 0.36, 1);
    }
}
.artwork-host:not(.motion-enabled) .scene-object {
    transform: none;
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
    .scene-object {
        transform: none !important;
    }
}
</style>
