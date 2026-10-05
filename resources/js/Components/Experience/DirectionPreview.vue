<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import {
    ArrowDown,
    ArrowUpRight,
    ArrowRight,
    Heart,
    Search,
    X,
    SlidersHorizontal,
    Sparkles,
    Users,
    CircleDollarSign,
} from "lucide-vue-next";
import SubscriptionScene, { type SceneService } from "./SubscriptionScene.vue";

// Isolated design fixtures. Never supplied by a production route or used to pay.
const services: SceneService[] = [
    {
        id: "01",
        name: "Netflix",
        category: "films",
        categoryLabel: "Films & séries",
        scene: "cinema",
        tagline: "Les bonnes histoires se partagent.",
        priceCents: 600,
        members: 3,
        capacity: 4,
        owner: "Camille",
        description:
            "Une soirée cinéma commence par une bonne compagnie. Cette carte explore un univers chaleureux, inspiré des salles de cinéma et des billets à collectionner.",
    },
    {
        id: "02",
        name: "Spotify",
        category: "music",
        categoryLabel: "Musique",
        scene: "music",
        tagline: "Votre prochaine chanson préférée.",
        priceCents: 300,
        members: 4,
        capacity: 6,
        owner: "Alex",
        description:
            "Un disque, une pochette, des découvertes en commun. Cette carte propose une ambiance musicale tactile, avec une animation discrète du vinyle.",
    },
    {
        id: "03",
        name: "Disney+",
        category: "films",
        categoryLabel: "Films & séries",
        scene: "world",
        tagline: "Il reste tant à découvrir.",
        priceCents: 400,
        members: 4,
        capacity: 4,
        owner: "Sam",
        description:
            "Un petit monde à explorer ensemble. Cette carte teste un groupe complet : son état reste clair, sans créer une fausse possibilité de le rejoindre.",
    },
];
const filters = [
    { id: "all", label: "Tout explorer" },
    { id: "films", label: "Films & séries" },
    { id: "music", label: "Musique" },
    { id: "favorites", label: "Mes favoris" },
];
const category = ref("all");
const query = ref("");
const favorites = ref<string[]>([]);
const motionRequested = ref(true);
const systemReducedMotion = ref(false);
const motion = computed(
    () => motionRequested.value && !systemReducedMotion.value,
);
const normalize = (value: string) =>
    value
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLocaleLowerCase("fr-CA");
const filtered = computed(() =>
    services.filter(
        (service) =>
            (category.value === "all" ||
                category.value === service.category ||
                (category.value === "favorites" &&
                    favorites.value.includes(service.id))) &&
            normalize(service.name + " " + service.categoryLabel).includes(
                normalize(query.value.trim()),
            ),
    ),
);
const selected = ref<SceneService | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const backButton = ref<HTMLButtonElement | null>(null);
let dialogTrigger: HTMLButtonElement | null = null;
let media: MediaQueryList | undefined;
function updateSystemMotion(event: MediaQueryListEvent) {
    systemReducedMotion.value = event.matches;
}
onMounted(() => {
    media = window.matchMedia("(prefers-reduced-motion: reduce)");
    systemReducedMotion.value = media.matches;
    media.addEventListener("change", updateSystemMotion);
});
onBeforeUnmount(() => media?.removeEventListener("change", updateSystemMotion));
function toggleFavorite(id: string) {
    favorites.value = favorites.value.includes(id)
        ? favorites.value.filter((item) => item !== id)
        : [...favorites.value, id];
}
function resetFilters() {
    category.value = "all";
    query.value = "";
}
async function explore(service: SceneService, trigger: HTMLButtonElement) {
    selected.value = service;
    dialogTrigger = trigger;
    await nextTick();
    dialog.value?.showModal();
}
function closed() {
    dialogTrigger?.focus();
}
function trapFocus(event: KeyboardEvent) {
    if (event.key !== "Tab") return;
    if (event.shiftKey && document.activeElement === closeButton.value) {
        event.preventDefault();
        backButton.value?.focus();
    } else if (!event.shiftKey && document.activeElement === backButton.value) {
        event.preventDefault();
        closeButton.value?.focus();
    }
}
function showFavorites() {
    category.value = "favorites";
    query.value = "";
}
function price(cents: number) {
    return new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(cents / 100);
}
</script>

<template>
    <Head title="Collection — direction créative" />
    <div
        class="eq-direction"
        :class="{ 'motion-on': motion, 'motion-off': !motion }"
    >
        <a href="#collection" class="skip-link">Aller aux cartes</a>
        <header class="direction-header page-width">
            <a
                href="/direction"
                class="direction-logo"
                aria-label="EquitAb — aperçu de la nouvelle direction"
                ><span class="logo-symbol" aria-hidden="true"
                    ><i /><i /><i /><i /></span
                >equit<span>ab</span></a
            >
            <nav aria-label="Navigation de l’aperçu">
                <a href="#collection" class="nav-selected">Explorer</a
                ><a href="#ensemble">L’esprit EquitAb</a>
            </nav>
            <a
                href="#collection"
                class="header-favorites"
                @click="showFavorites"
                ><Heart :size="16" aria-hidden="true" /><span>Mes favoris</span
                ><b>{{ favorites.length }}</b></a
            >
        </header>

        <main>
            <section
                class="direction-hero page-width"
                aria-labelledby="direction-title"
            >
                <div>
                    <p class="eyebrow">
                        <span /> MOINS CHER. ENCORE MIEUX ENSEMBLE.
                    </p>
                    <h1 id="direction-title">
                        Vos envies.<br />La bonne
                        <span
                            >compagnie<svg
                                viewBox="0 0 430 20"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path
                                    d="M3 13C106 1 284 1 426 9M63 18C175 10 289 10 375 14"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                /></svg></span
                        >.
                    </h1>
                </div>
                <div class="hero-aside">
                    <div class="together-symbol" aria-hidden="true">
                        <span>e.</span><i>+</i><span>vous.</span>
                    </div>
                    <p>
                        Un abonnement qui vous plaît.<br />Des gens avec qui le
                        partager.<br /><strong
                            >Et un peu plus pour vous.</strong
                        >
                    </p>
                    <a href="#collection"
                        >Trouver votre prochain coup de cœur
                        <ArrowDown :size="16" aria-hidden="true"
                    /></a>
                </div>
            </section>

            <section
                id="collection"
                class="collection-section page-width"
                aria-labelledby="collection-title"
            >
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">LA COLLECTION / 001</p>
                        <h2 id="collection-title">De quoi vous retrouver.</h2>
                    </div>
                    <div class="motion-control">
                        <span id="motion-label">Animations</span
                        ><button
                            type="button"
                            role="switch"
                            :aria-checked="motion"
                            aria-labelledby="motion-label"
                            :aria-describedby="
                                systemReducedMotion
                                    ? 'system-motion-note'
                                    : undefined
                            "
                            :disabled="systemReducedMotion"
                            class="motion-switch"
                            @click="motionRequested = !motionRequested"
                        >
                            <span />
                        </button>
                    </div>
                </div>
                <p
                    v-if="systemReducedMotion"
                    id="system-motion-note"
                    class="system-motion-note"
                >
                    Animations réduites selon les préférences de votre appareil.
                </p>
                <div class="collection-toolbar">
                    <div class="filter-list" aria-label="Catégories">
                        <button
                            v-for="filter in filters"
                            :key="filter.id"
                            type="button"
                            :aria-pressed="category === filter.id"
                            :class="{ active: category === filter.id }"
                            @click="category = filter.id"
                        >
                            <Heart
                                v-if="filter.id === 'favorites'"
                                :size="13"
                                aria-hidden="true"
                            />{{ filter.label
                            }}<span v-if="filter.id === 'favorites'">{{
                                favorites.length
                            }}</span>
                        </button>
                    </div>
                    <label class="collection-search"
                        ><Search :size="16" aria-hidden="true" /><span
                            class="visually-hidden"
                            >Chercher dans la collection</span
                        ><input
                            v-model="query"
                            type="search"
                            placeholder="Un service en tête ?"
                    /></label>
                </div>
                <div class="collection-context">
                    <p>
                        <span class="context-dot" />{{ filtered.length }}
                        {{
                            filtered.length > 1
                                ? "groupes à découvrir"
                                : "groupe à découvrir"
                        }}<span class="context-demo"> · Démonstration</span>
                    </p>
                    <span>En dollars canadiens</span>
                </div>
                <p class="visually-hidden" role="status" aria-live="polite">
                    {{ filtered.length }} résultat{{
                        filtered.length > 1 ? "s" : ""
                    }}. {{ favorites.length }} favori{{
                        favorites.length > 1 ? "s" : ""
                    }}.
                </p>
                <div v-if="filtered.length" class="collection-grid">
                    <SubscriptionScene
                        v-for="(service, index) in filtered"
                        :key="service.id"
                        :service="service"
                        :favorite="favorites.includes(service.id)"
                        :motion="motion"
                        :style="{ '--reveal-delay': index * 70 + 'ms' }"
                        @favorite="toggleFavorite"
                        @explore="explore"
                    />
                </div>
                <div v-else class="empty-collection">
                    <Search :size="28" aria-hidden="true" />
                    <h3>
                        {{
                            category === "favorites" && !query
                                ? "Vos coups de cœur commencent ici."
                                : "Aucun groupe pour cette recherche."
                        }}
                    </h3>
                    <p>
                        {{
                            category === "favorites" && !query
                                ? "Touchez le cœur d’une carte pour la retrouver dans cette sélection."
                                : "Essayez un autre service ou explorez toute la collection."
                        }}
                    </p>
                    <button type="button" @click="resetFilters">
                        Explorer la collection
                        <ArrowRight :size="16" aria-hidden="true" />
                    </button>
                </div>
                <div class="collection-footnote">
                    <p>
                        <SlidersHorizontal :size="14" aria-hidden="true" />Prix,
                        personnes et groupes fictifs. Favoris conservés
                        seulement dans cet aperçu.
                    </p>
                    <span>01 — 03 / ÉDITION EXPLORATOIRE</span>
                </div>
            </section>

            <section
                id="ensemble"
                class="together-section page-width"
                aria-labelledby="together-title"
            >
                <div class="together-intro">
                    <p class="eyebrow">LE PLAISIR DE PARTAGER</p>
                    <h2 id="together-title">
                        De belles découvertes.<br /><span
                            >Les idées claires.</span
                        >
                    </h2>
                    <p>
                        De la personnalité pour explorer.<br />De la simplicité
                        pour s’y retrouver.
                    </p>
                </div>
                <div class="principle">
                    <span class="principle-icon"
                        ><Sparkles :size="22" aria-hidden="true"
                    /></span>
                    <h3>À chacun son univers.</h3>
                    <p>
                        Une scène, des matières, une couleur. Chaque service a
                        sa propre personnalité.
                    </p>
                </div>
                <div class="principle">
                    <span class="principle-icon"
                        ><Users :size="22" aria-hidden="true"
                    /></span>
                    <h3>La place de chacun.</h3>
                    <p>
                        Les membres et les places disponibles se comprennent au
                        premier regard.
                    </p>
                </div>
                <div class="principle">
                    <span class="principle-icon"
                        ><CircleDollarSign :size="22" aria-hidden="true"
                    /></span>
                    <h3>L’essentiel, au clair.</h3>
                    <p>
                        Un montant toujours lisible. Aucun détail important
                        caché derrière un effet.
                    </p>
                </div>
            </section>
        </main>

        <footer class="direction-footer">
            <div class="page-width">
                <div class="footer-top">
                    <p>La suite se partage.</p>
                    <a href="#collection" aria-label="Revenir à la collection"
                        ><ArrowUpRight :size="30" aria-hidden="true"
                    /></a>
                </div>
                <div class="footer-bottom">
                    <span>EquitAb · Direction créative 01</span
                    ><span>Fait pour être ensemble. Pensé pour vous.</span
                    ><span>Aperçu interactif · Aucun paiement</span>
                </div>
            </div>
        </footer>

        <dialog
            ref="dialog"
            class="service-dialog"
            aria-labelledby="detail-title"
            aria-describedby="detail-description"
            @close="closed"
            @keydown="trapFocus"
            @click="
                (event) => {
                    if (event.target === event.currentTarget) dialog?.close();
                }
            "
        >
            <div
                v-if="selected"
                class="detail-content"
                :class="'detail-' + selected.scene"
            >
                <div class="detail-top">
                    <span>{{ selected.categoryLabel }} / APERÇU</span
                    ><button
                        type="button"
                        class="close-detail"
                        ref="closeButton"
                        aria-label="Fermer les détails"
                        autofocus
                        @click="dialog?.close()"
                    >
                        <X :size="21" aria-hidden="true" />
                    </button>
                </div>
                <p class="detail-eyebrow">LE PLAISIR, EN COMMUN.</p>
                <h2 id="detail-title">{{ selected.name }}<span>.</span></h2>
                <p id="detail-description" class="detail-description">
                    {{ selected.description }}
                </p>
                <div class="detail-facts">
                    <div>
                        <span>Part mensuelle estimée</span
                        ><strong
                            >{{ price(selected.priceCents) }}
                            <small>CAD</small></strong
                        >
                    </div>
                    <div>
                        <span>Le groupe</span
                        ><strong
                            >{{ selected.members }}
                            <small
                                >/ {{ selected.capacity }} membres</small
                            ></strong
                        >
                    </div>
                </div>
                <p class="detail-availability">
                    {{
                        selected.members === selected.capacity
                            ? "Ce groupe de démonstration est complet."
                            : selected.capacity -
                              selected.members +
                              " place(s) disponible(s) dans ce groupe de démonstration."
                    }}
                </p>
                <div class="detail-disclaimer">
                    <strong>On explore une idée, pas une offre.</strong>
                    <p>
                        Ces montants sont fictifs. Les frais, l’admissibilité au
                        partage et les conditions du service seraient à
                        confirmer avant toute inscription. Aucun accès ni
                        paiement ne peut être demandé ici.
                    </p>
                </div>
                <div class="detail-actions">
                    <button
                        type="button"
                        class="detail-favorite"
                        :aria-pressed="favorites.includes(selected.id)"
                        @click="toggleFavorite(selected.id)"
                    >
                        <Heart
                            :size="17"
                            :fill="
                                favorites.includes(selected.id)
                                    ? 'currentColor'
                                    : 'none'
                            "
                            aria-hidden="true"
                        />{{
                            favorites.includes(selected.id)
                                ? "Enregistré dans mes favoris"
                                : "Garder ce coup de cœur"
                        }}</button
                    ><button
                        type="button"
                        class="detail-back"
                        ref="backButton"
                        @click="dialog?.close()"
                    >
                        Retour à la collection
                        <ArrowRight :size="16" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </dialog>
    </div>
</template>

<style scoped>
:global(body:has(.service-dialog[open])) {
    overflow: hidden;
}
:global(html:has(.eq-direction.motion-off)) {
    scroll-behavior: auto;
}
.eq-direction {
    --ink: #252732;
    --muted: #6b6e76;
    --paper: #f5f4f0;
    color: var(--ink);
    background: var(--paper);
    min-height: 100vh;
    font-family: "Montserrat", sans-serif;
    -webkit-font-smoothing: antialiased;
}
.eq-direction :deep(*) {
    box-sizing: border-box;
}
.eq-direction :deep(button),
.eq-direction a,
.eq-direction input {
    -webkit-tap-highlight-color: transparent;
}
.eq-direction :deep(:focus-visible) {
    outline: 3px solid #6b58c8;
    outline-offset: 4px;
}
.page-width {
    width: min(1180px, calc(100% - 80px));
    margin-inline: auto;
}
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.skip-link {
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 30;
    padding: 14px 20px;
    background: white;
    border: 2px solid var(--ink);
    border-radius: 10px;
    transform: translateY(-180%);
}
.skip-link:focus {
    transform: none;
}
.direction-header {
    height: 98px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-bottom: 1px solid #dededb;
}
.direction-logo {
    display: flex;
    align-items: center;
    font-size: 31px;
    letter-spacing: -0.075em;
    font-weight: 700;
    text-decoration: none;
}
.direction-logo > span:not(.logo-symbol) {
    font-weight: 400;
}
.logo-symbol {
    width: 24px;
    height: 24px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2px;
    margin-right: 10px;
    transform: rotate(-8deg);
}
.logo-symbol i {
    background: var(--ink);
    border-radius: 50% 50% 2px 50%;
}
.logo-symbol i:nth-child(2) {
    transform: rotate(90deg);
}
.logo-symbol i:nth-child(3) {
    transform: rotate(-90deg);
}
.logo-symbol i:nth-child(4) {
    transform: rotate(180deg);
}
nav {
    display: flex;
    gap: 28px;
    font-size: 12px;
}
nav a {
    min-height: 44px;
    display: flex;
    align-items: center;
    position: relative;
}
nav .nav-selected::after {
    content: "";
    position: absolute;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: currentColor;
    bottom: 2px;
    left: calc(50% - 2px);
}
.header-favorites {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    font-size: 11px;
    border: 1px solid #d3d4d2;
    border-radius: 30px;
    padding: 0 14px;
    background: #ffffff60;
}
.header-favorites b {
    display: grid;
    place-items: center;
    width: 20px;
    height: 20px;
    font-size: 10px;
    border-radius: 50%;
    background: #e8e8e1;
}
.direction-hero {
    display: grid;
    grid-template-columns: 1.8fr 1fr;
    align-items: center;
    gap: 32px;
    padding-block: 50px 54px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.13em;
}
.eyebrow > span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #6d8a3f;
}
h1 {
    font-size: clamp(38px, 4.7vw, 64px);
    line-height: 1.08;
    font-weight: 500;
    letter-spacing: -0.065em;
    margin-top: 18px;
}
h1 > span {
    position: relative;
    font-family: Georgia, serif;
    font-style: italic;
    letter-spacing: -0.065em;
    font-weight: 400;
}
h1 svg {
    position: absolute;
    bottom: -8px;
    left: -2px;
    width: 100%;
    color: #8daa63;
}
.hero-aside {
    justify-self: end;
    padding-top: 12px;
}
.hero-aside p {
    margin-top: 15px;
    font-size: 13px;
    color: #646770;
    line-height: 1.85;
}
.hero-aside strong {
    font-weight: 500;
    color: var(--ink);
}
.hero-aside > a {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    min-height: 44px;
    margin-top: 5px;
    font-size: 10px;
    font-weight: 600;
}
.together-symbol {
    display: flex;
    align-items: center;
}
.together-symbol span {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #d9e2cd;
    font-family: Georgia, serif;
    font-size: 29px;
    font-style: italic;
    transform: rotate(-10deg);
}
.together-symbol span:last-child {
    width: 50px;
    height: 42px;
    background: #e0dcf0;
    font-family: inherit;
    font-size: 12px;
    font-style: normal;
    font-weight: 500;
    transform: rotate(8deg);
}
.together-symbol i {
    margin: 0 6px;
    font-size: 13px;
    font-style: normal;
}
.collection-section {
    scroll-margin-top: 24px;
}
.section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 20px;
    padding-top: 24px;
    border-top: 1px solid #dededb;
}
h2 {
    font-size: 26px;
    font-weight: 500;
    letter-spacing: -0.055em;
    line-height: 1.3;
}
.section-heading h2 {
    margin-top: 8px;
}
.motion-control {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 10px;
}
.motion-switch {
    min-width: 48px;
    min-height: 44px;
    position: relative;
    display: flex;
    align-items: center;
    padding: 10px 3px;
    cursor: pointer;
    border-radius: 30px;
}
.motion-switch::before {
    content: "";
    position: absolute;
    inset: 10px 0;
    border-radius: 20px;
    background: #bdbfb9;
    transition: background 0.2s;
}
.motion-switch[aria-checked="true"]::before {
    background: #d0ed9b;
}
.motion-switch > span {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #2b3426;
    position: relative;
    z-index: 1;
    transform: translateX(0);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.motion-switch[aria-checked="true"] > span {
    transform: translateX(24px);
}
.motion-switch:disabled {
    cursor: default;
    opacity: 0.7;
}
.system-motion-note {
    font-size: 10px;
    color: var(--muted);
    text-align: right;
    margin-top: 6px;
}
.collection-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-block: 24px 18px;
}
.filter-list {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.filter-list button {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 44px;
    border-radius: 30px;
    padding: 0 17px;
    font-size: 10px;
    cursor: pointer;
    background: transparent;
    border: 1px solid #ddddd9;
    transition:
        background 0.2s,
        color 0.2s;
}
.filter-list button.active {
    color: #fff;
    background: var(--ink);
    border-color: var(--ink);
}
.filter-list button:not(.active):hover {
    background: #e6e6df;
}
.filter-list button span {
    font-size: 9px;
    opacity: 0.7;
}
.collection-search {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    min-height: 44px;
    color: #686c74;
    border-bottom: 1px solid #cbcfc8;
    padding: 0 4px;
}
.collection-search input {
    width: 174px;
    min-width: 0;
    font-size: 11px;
    color: var(--ink);
    border: 0;
    background: none;
    padding: 10px 0;
    box-shadow: none;
}
.collection-search input::placeholder {
    color: #727680;
}
.collection-search:focus-within {
    border-bottom-color: #6b58c8;
}
.collection-context {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 14px;
    color: #70727a;
    font-size: 9px;
}
.collection-context p {
    display: flex;
    align-items: center;
}
.context-dot {
    width: 5px;
    height: 5px;
    background: #748b58;
    border-radius: 50%;
    margin-right: 6px;
}
.context-demo {
    margin-left: 3px;
}
.collection-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}
.motion-on .collection-grid > :deep(article) {
    animation: card-appear 0.55s both cubic-bezier(0.22, 0.61, 0.36, 1);
    animation-delay: var(--reveal-delay);
}
.collection-footnote {
    display: flex;
    align-items: start;
    justify-content: space-between;
    gap: 24px;
    font-size: 9px;
    color: #72747d;
    padding-block: 18px 20px;
}
.collection-footnote p {
    display: flex;
    align-items: start;
    gap: 7px;
    line-height: 1.6;
}
.collection-footnote svg {
    flex: none;
}
.collection-footnote > span {
    flex: none;
    font-size: 8px;
    letter-spacing: 0.08em;
    padding-top: 2px;
}
.empty-collection {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    min-height: 360px;
    padding: 32px;
    border: 1px dashed #c5c8c1;
    border-radius: 24px;
    text-align: center;
}
.empty-collection h3 {
    font-size: 20px;
    font-weight: 500;
    margin-top: 20px;
    letter-spacing: -0.04em;
}
.empty-collection p {
    font-size: 12px;
    line-height: 1.7;
    color: var(--muted);
    margin-top: 8px;
}
.empty-collection button {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 12px;
    min-height: 44px;
    padding: 10px 18px;
    margin-top: 24px;
    border-radius: 24px;
    background: var(--ink);
    color: white;
    cursor: pointer;
}
.together-section {
    display: grid;
    grid-template-columns: 1.45fr 1fr 1fr 1fr;
    gap: 32px;
    padding-block: 38px 55px;
    border-top: 1px solid #dededb;
    margin-top: 25px;
    scroll-margin-top: 24px;
}
.together-intro h2 {
    margin-top: 15px;
    font-size: 25px;
}
.together-intro h2 span {
    color: #7f8279;
}
.together-intro > p:last-child {
    margin-top: 13px;
    font-size: 11px;
    color: #71747b;
    line-height: 1.8;
}
.principle {
    padding-top: 3px;
}
.principle-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 15px;
    border: 1px solid #d7d9d3;
    background: #eeefe9;
}
.principle h3 {
    font-size: 12px;
    font-weight: 600;
    margin-top: 20px;
}
.principle p {
    font-size: 11px;
    color: #71747b;
    line-height: 1.85;
    margin-top: 10px;
}
.direction-footer {
    background: #272a32;
    color: #f5f4ed;
    padding-block: 36px 24px;
}
.footer-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}
.footer-top p {
    font-size: clamp(32px, 5vw, 64px);
    font-weight: 400;
    letter-spacing: -0.07em;
}
.footer-top a {
    display: grid;
    place-items: center;
    width: 58px;
    height: 58px;
    flex: none;
    border: 1px solid #666a6d;
    border-radius: 50%;
    color: #d8edb0;
}
.footer-bottom {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    padding-top: 25px;
    margin-top: 28px;
    border-top: 1px solid #484c53;
    color: #b8bcbe;
    font-size: 9px;
    line-height: 1.6;
}
.service-dialog {
    width: min(560px, calc(100% - 32px));
    max-height: calc(100dvh - 32px);
    margin: auto;
    padding: 0;
    background: #f8f8f4;
    color: var(--ink);
    border: none;
    border-radius: 25px;
    overscroll-behavior: contain;
    box-shadow: 0 25px 100px #0004;
}
.service-dialog::backdrop {
    background: #1b1f2b88;
    backdrop-filter: blur(8px);
}
.detail-content {
    padding: 26px 30px 30px;
}
.detail-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-size: 9px;
    letter-spacing: 0.06em;
}
.close-detail {
    width: 44px;
    height: 44px;
    display: grid;
    place-items: center;
    border: 1px solid #d1d3ce;
    border-radius: 50%;
    cursor: pointer;
}
.detail-eyebrow {
    margin-top: 22px;
    font-size: 9px;
    color: var(--muted);
    letter-spacing: 0.12em;
}
.detail-content h2 {
    font-size: 60px;
    letter-spacing: -0.07em;
    font-weight: 600;
    margin-top: 8px;
}
.detail-content h2 span {
    color: #72984b;
}
.detail-cinema h2 span {
    color: #d95b44;
}
.detail-world h2 span {
    color: #7773c7;
}
.detail-description {
    font-size: 12px;
    line-height: 1.85;
    color: #666b75;
    margin-top: 15px;
}
.detail-facts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 25px;
    padding-block: 24px;
    border-block: 1px solid #dddfd7;
}
.detail-facts > div > span {
    display: block;
    font-size: 10px;
    color: var(--muted);
    margin-bottom: 10px;
}
.detail-facts strong {
    display: block;
    font-size: 28px;
    font-weight: 500;
    letter-spacing: -0.04em;
}
.detail-facts small {
    font-size: 10px;
    font-weight: 400;
    letter-spacing: 0;
}
.detail-availability {
    margin-top: 15px;
    font-size: 11px;
}
.detail-disclaimer {
    background: #ebede5;
    padding: 16px;
    border-radius: 12px;
    margin-top: 24px;
    font-size: 11px;
    line-height: 1.8;
}
.detail-disclaimer strong {
    font-weight: 600;
}
.detail-disclaimer p {
    font-size: 10px;
    margin-top: 6px;
    color: #626953;
}
.detail-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 24px;
}
.detail-actions button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    min-height: 48px;
    border-radius: 25px;
    font-size: 11px;
    cursor: pointer;
}
.detail-favorite {
    background: #282e28;
    color: white;
}
.detail-back {
    border: 1px solid #d3d8c9;
}
.detail-actions button:active {
    transform: scale(0.98);
}
@keyframes card-appear {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
@media (min-width: 1100px) {
    .motion-on .service-dialog[open] .detail-content {
        animation: card-appear 0.25s ease-out;
    }
}
@media (max-width: 1000px) {
    .page-width {
        width: calc(100% - 48px);
    }
    .direction-hero {
        grid-template-columns: 1.6fr 1fr;
        gap: 24px;
    }
    h1 {
        font-size: 48px;
    }
    .hero-aside p {
        font-size: 11px;
    }
    .hero-aside > a {
        font-size: 9px;
        gap: 8px;
    }
    .collection-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .collection-toolbar {
        flex-wrap: wrap;
    }
    .collection-search {
        flex: 1;
        max-width: 260px;
    }
    .collection-search input {
        width: 100%;
    }
    .together-section {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 24px;
    }
    .together-intro {
        grid-column: 1 / -1;
    }
    .together-intro h2 br {
        display: none;
    }
    .together-intro > p:last-child {
        display: none;
    }
}
@media (max-width: 680px) {
    .page-width {
        width: calc(100% - 32px);
    }
    .direction-header {
        height: 78px;
        gap: 12px;
    }
    .direction-logo {
        font-size: 28px;
    }
    .direction-header nav {
        display: none;
    }
    .header-favorites {
        padding: 0 11px;
        gap: 6px;
        font-size: 10px;
    }
    .direction-hero {
        grid-template-columns: 1fr;
        gap: 24px;
        padding-block: 32px;
    }
    h1 {
        font-size: clamp(37px, 8.7vw, 55px);
    }
    .eyebrow {
        font-size: 8px;
        letter-spacing: 0.1em;
    }
    .hero-aside {
        display: grid;
        grid-template-columns: auto 1fr;
        justify-self: stretch;
        align-items: center;
        gap: 0 18px;
        padding-top: 0;
    }
    .hero-aside p {
        margin-top: 0;
        font-size: 11px;
        line-height: 1.7;
    }
    .hero-aside > a {
        grid-column: 1 / -1;
        margin-top: 9px;
        font-size: 10px;
        justify-content: space-between;
        border-bottom: 1px solid #dededb;
    }
    .together-symbol span {
        width: 35px;
        height: 35px;
        font-size: 26px;
    }
    .together-symbol span:last-child {
        width: 42px;
        height: 35px;
        font-size: 10px;
    }
    .together-symbol i {
        margin: 0 3px;
    }
    .section-heading {
        padding-top: 0;
        border-top: 0;
        align-items: center;
        gap: 12px;
    }
    .section-heading h2 {
        font-size: 22px;
        max-width: 200px;
    }
    .motion-control {
        flex-direction: column;
        gap: 0;
        font-size: 9px;
    }
    .collection-toolbar {
        margin-block: 18px 16px;
        gap: 12px;
    }
    .filter-list {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 7px;
        width: 100%;
    }
    .filter-list button {
        justify-content: center;
        padding: 0 12px;
        font-size: 10px;
    }
    .collection-search {
        flex-basis: 100%;
        max-width: none;
    }
    .collection-context {
        font-size: 8px;
        gap: 8px;
    }
    .context-demo {
        display: none;
    }
    .collection-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
    }
    .collection-footnote > span {
        display: none;
    }
    .collection-footnote {
        font-size: 9px;
    }
    .together-section {
        margin-top: 12px;
        padding-block: 28px 32px;
        grid-template-columns: 1fr;
        gap: 26px;
    }
    .together-intro h2 {
        font-size: 27px;
    }
    .together-intro h2 br {
        display: block;
    }
    .principle {
        display: grid;
        grid-template-columns: 42px 1fr;
        gap: 0 16px;
    }
    .principle-icon {
        grid-row: 1 / 3;
    }
    .principle h3 {
        margin-top: 0;
    }
    .principle p {
        margin-top: 5px;
    }
    .direction-footer {
        padding-block: 28px 22px;
    }
    .footer-top p {
        max-width: 250px;
        font-size: 39px;
        line-height: 1.12;
    }
    .footer-top a {
        width: 46px;
        height: 46px;
    }
    .footer-bottom {
        flex-direction: column;
        gap: 8px;
        margin-top: 25px;
        padding-top: 20px;
    }
    .detail-content {
        padding: 18px 20px 24px;
    }
    .detail-content h2 {
        font-size: 48px;
    }
    .detail-eyebrow {
        margin-top: 16px;
    }
    .detail-description {
        font-size: 11px;
    }
    .detail-facts {
        gap: 12px;
        margin-top: 20px;
        padding-block: 20px;
    }
    .detail-facts strong {
        font-size: 26px;
    }
    .detail-facts small {
        display: block;
        margin-top: 4px;
    }
    .detail-facts > div > span {
        font-size: 9px;
    }
    .detail-disclaimer {
        margin-top: 20px;
        padding: 14px;
    }
}
.motion-off :deep(*),
.motion-off :deep(*::before),
.motion-off :deep(*::after) {
    animation: none !important;
    transition: none !important;
}
@media (prefers-reduced-motion: reduce) {
    :global(html:has(.eq-direction)) {
        scroll-behavior: auto;
    }
    .eq-direction :deep(*),
    .eq-direction :deep(*::before),
    .eq-direction :deep(*::after) {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}
</style>
