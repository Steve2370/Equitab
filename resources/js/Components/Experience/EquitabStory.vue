<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Pause, Play, ArrowUpRight } from "lucide-vue-next";
import { Link } from "@inertiajs/vue3";
import { useExperienceMotion } from "@/composables/useExperienceMotion";
const { motion, reducedMotion } = useExperienceMotion();
const video = ref<HTMLVideoElement | null>(null);
const frame = ref<HTMLElement | null>(null);
const playing = ref(false);
const wanted = ref(false);
const visible = ref(false);
const pageVisible = ref(true);
const failed = ref(false);
const currentTime = ref(0);
let pendingSeek: number | null = null;
const currentStep = computed(() =>
    Math.min(2, Math.floor(currentTime.value / 6)),
);
const steps = [
    { title: "Choisissez.", text: "Trouvez un service qui vous ressemble." },
    { title: "Partagez.", text: "Rejoignez un groupe ou proposez le vôtre." },
    { title: "Profitez.", text: "Suivez votre part et vos paiements." },
];
let observer: IntersectionObserver | undefined;
async function syncPlayback() {
    const player = video.value;
    if (!player) return;
    if (
        !wanted.value ||
        !motion.value ||
        !visible.value ||
        !pageVisible.value ||
        failed.value
    ) {
        player.pause();
        return;
    }
    try {
        await player.play();
    } catch {
        playing.value = false;
    }
}
function toggle() {
    wanted.value = !playing.value;
    void syncPlayback();
}
function seekTo(index: number) {
    pendingSeek = index * 6 + 0.8;
    if (video.value && video.value.readyState >= 1) {
        video.value.currentTime = pendingSeek;
        currentTime.value = pendingSeek;
        pendingSeek = null;
    }
}
function metadataLoaded() {
    if (pendingSeek !== null && video.value) {
        video.value.currentTime = pendingSeek;
        currentTime.value = pendingSeek;
        pendingSeek = null;
    }
}
function visibilityChanged() {
    pageVisible.value = !document.hidden;
}
watch([wanted, motion, visible, pageVisible, failed], syncPlayback);
onMounted(() => {
    pageVisible.value = !document.hidden;
    // On mobile the login form comes first; playing the story is an explicit choice.
    wanted.value = window.matchMedia("(min-width: 1100px)").matches;
    observer = new IntersectionObserver(
        ([entry]) => {
            visible.value = entry.isIntersecting;
        },
        { threshold: 0.2 },
    );
    if (frame.value) observer.observe(frame.value);
    document.addEventListener("visibilitychange", visibilityChanged);
});
onBeforeUnmount(() => {
    observer?.disconnect();
    document.removeEventListener("visibilitychange", visibilityChanged);
    video.value?.pause();
});
</script>
<template>
    <figure class="equitable-story" aria-labelledby="story-caption">
        <div ref="frame" class="story-frame">
            <video
                ref="video"
                v-show="!failed"
                class="story-video"
                muted
                playsinline
                loop
                preload="metadata"
                poster="/media/equitab-story-poster.png"
                aria-label="Animation explicative Equitab, sans son"
                aria-describedby="story-transcript"
                @play="playing = true"
                @pause="playing = false"
                @timeupdate="currentTime = video?.currentTime ?? 0"
                @error="failed = true"
                @loadedmetadata="metadataLoaded"
            >
                <source
                    src="/media/equitab-story.mp4"
                    type="video/mp4"
                    @error="failed = true"
                /></video
            ><img
                v-if="failed"
                src="/media/equitab-story-poster.png"
                alt="Des cartes d’abonnements réunies pour être partagées."
                width="1200"
                height="720"
                class="story-poster"
            /><span class="story-edition">LE PLAISIR DE PARTAGER / 001</span>
            <div class="story-player-control">
                <span>{{
                    reducedMotion
                        ? "Version sans mouvement"
                        : failed
                          ? "Version illustrée"
                          : "Le concept en 18 secondes"
                }}</span
                ><button
                    type="button"
                    :disabled="reducedMotion || failed"
                    :aria-label="
                        playing
                            ? 'Mettre l’animation en pause'
                            : 'Lire l’animation Equitab'
                    "
                    @click="toggle"
                >
                    <Pause v-if="playing" :size="16" aria-hidden="true" /><Play
                        v-else
                        :size="16"
                        aria-hidden="true"
                    />
                </button>
            </div>
        </div>
        <figcaption id="story-caption">
            <ol id="story-transcript" class="story-steps">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    :class="{ 'current-step': index === currentStep }"
                >
                    <button
                        type="button"
                        class="story-step-button"
                        :disabled="failed"
                        :aria-label="
                            'Voir l’étape ' + (index + 1) + ' : ' + step.title
                        "
                        :aria-current="
                            index === currentStep ? 'step' : undefined
                        "
                        @click="seekTo(index)"
                    >
                        <span class="step-number">0{{ index + 1 }}</span>
                        <div>
                            <strong>{{ step.title }}</strong>
                            <p>{{ step.text }}</p>
                        </div>
                    </button>
                </li>
            </ol>
        </figcaption>
        <div class="story-bottom">
            <span>Vos abonnements. Votre groupe. Votre espace.</span
            ><Link href="/services"
                >Explorer les services
                <ArrowUpRight :size="15" aria-hidden="true"
            /></Link>
        </div>
    </figure>
</template>
<style scoped>
.equitable-story {
    margin: 0;
}
.story-frame {
    position: relative;
    min-width: 0;
    aspect-ratio: 5/3;
    overflow: hidden;
    background: #eef0e9;
    border-radius: 22px;
}
.story-video,
.story-poster {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.story-edition {
    position: absolute;
    left: 16px;
    top: 16px;
    font-size: 8px;
    color: #747e6c;
    letter-spacing: 0.12em;
}
.story-player-control {
    position: absolute;
    right: 14px;
    bottom: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 9px;
    color: #5d6857;
}
.story-player-control button {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border: 1px solid #cdd5c4;
    background: #f7f9f1e0;
    border-radius: 50%;
    cursor: pointer;
}
.story-player-control button:hover {
    background: white;
}
.story-player-control button:disabled {
    opacity: 0.55;
    cursor: default;
}
.story-player-control button:focus-visible,
.story-bottom a:focus-visible {
    outline: 3px solid #6b58c8;
    outline-offset: 4px;
}
.story-steps {
    list-style: none;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
    padding: 22px 0 0;
    margin: 0;
    border-top: 1px solid #d2d8cb;
}
.story-step-button {
    display: flex;
    align-items: start;
    gap: 12px;
    min-height: 60px;
    text-align: left;
    border-radius: 6px;
    cursor: pointer;
}
.story-step-button:focus-visible {
    outline: 3px solid #6b58c8;
    outline-offset: 4px;
}
.story-step-button:disabled {
    cursor: default;
}
.step-number {
    font-size: 10px;
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 1px solid #c8d1bd;
    flex: none;
    color: #617150;
}
.current-step .step-number {
    background: #334833;
    color: #f3f5ed;
    border-color: #334833;
}
.story-steps strong {
    font-size: 12px;
    font-weight: 600;
}
.story-steps p {
    margin-top: 6px;
    font-size: 11px;
    line-height: 1.65;
    color: #707b69;
}
.story-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 28px;
    font-size: 9px;
    color: #717c6a;
}
.story-bottom a {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 44px;
    color: #33432e;
}
@media (max-width: 1300px) and (min-width: 1100px) {
    .story-steps {
        gap: 16px;
    }
    .story-step-button {
        gap: 9px;
    }
    .story-steps p {
        font-size: 10px;
    }
}
@media (max-width: 600px) {
    .story-edition {
        top: 10px;
        left: 10px;
        font-size: 6px;
    }
    .story-player-control {
        bottom: 5px;
        right: 5px;
    }
    .story-player-control > span {
        display: none;
    }
    .story-player-control button {
        width: 40px;
        height: 40px;
    }
    .story-steps {
        grid-template-columns: 1fr;
        gap: 18px;
        padding-top: 24px;
    }
    .story-step-button {
        align-items: center;
    }
    .story-steps p {
        margin-top: 3px;
    }
    .story-bottom {
        flex-direction: column;
        align-items: start;
        gap: 6px;
        margin-top: 22px;
    }
}
</style>
