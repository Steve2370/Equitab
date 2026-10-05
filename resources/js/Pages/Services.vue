<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import {
    Search,
    X,
    ArrowUpRight,
    ArrowRight,
    Info,
    SlidersHorizontal,
} from "lucide-vue-next";
import CollectionHeader from "@/Components/Experience/CollectionHeader.vue";
import CatalogServiceCard from "@/Components/Experience/CatalogServiceCard.vue";
import EquitabWordmark from "@/Components/Experience/EquitabWordmark.vue";
import { useExperienceMotion } from "@/composables/useExperienceMotion";
interface Subscription {
    id: number;
    name: string;
    slug: string;
    monthly_price: number | null;
    max_members: number | null;
}
interface Category {
    id: number;
    name: string;
    subscriptions: Subscription[];
}
const props = defineProps<{ categories: Category[] }>();
const page = usePage<{ auth: { user: { name: string } | null } }>();
const { motion } = useExperienceMotion();
const activeCategory = ref<number | null>(null);
const queryFromPage = () =>
    new URL(page.url, "http://equitab.local").searchParams.get("search") ?? "";
const search = ref(queryFromPage());
watch(
    () => page.url,
    () => {
        search.value = queryFromPage();
        activeCategory.value = null;
    },
);
const normalize = (value: string) =>
    value
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .trim()
        .toLocaleLowerCase("fr-CA");
const filteredServices = computed(() =>
    props.categories
        .filter(
            (category) =>
                activeCategory.value === null ||
                category.id === activeCategory.value,
        )
        .flatMap((category) =>
            category.subscriptions.map((service) => ({
                ...service,
                category: category.name,
            })),
        )
        .filter((service) =>
            normalize(service.name + " " + service.category).includes(
                normalize(search.value),
            ),
        ),
);
const totalServices = computed(() =>
    props.categories.reduce(
        (total, category) => total + category.subscriptions.length,
        0,
    ),
);
function resetFilters() {
    search.value = "";
    activeCategory.value = null;
}
</script>
<template>
    <Head title="La collection — tous les services" />
    <div class="services-collection" :class="{ 'without-motion': !motion }">
        <a href="#catalog" class="catalog-skip">Aller aux services</a>
        <CollectionHeader />
        <main class="catalog-width">
            <section class="catalog-intro" aria-labelledby="catalog-title">
                <div>
                    <p class="catalog-eyebrow">
                        <span /> LES BONNES CHOSES SE PARTAGENT.
                    </p>
                    <h1 id="catalog-title">
                        Toutes vos envies.<br />Un peu plus
                        <em
                            >ensemble<svg
                                viewBox="0 0 420 18"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path
                                    d="M4 10C100 1 287 1 414 8M68 16C172 10 301 10 373 13"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                /></svg></em
                        >.
                    </h1>
                </div>
                <div class="catalog-intro-aside">
                    <div class="catalog-tokens" aria-hidden="true">
                        <span>e.</span><i>+</i><span>vous.</span>
                    </div>
                    <p>
                        Musique, films, outils du quotidien.<br />Trouvez votre
                        prochain coup de cœur,<br /><strong
                            >puis le groupe qui vous ressemble.</strong
                        >
                    </p>
                    <a href="#catalog"
                        >Découvrir la collection
                        <ArrowRight :size="16" aria-hidden="true"
                    /></a>
                </div>
            </section>
            <section
                id="catalog"
                class="catalog-section"
                aria-labelledby="collection-heading"
            >
                <div class="catalog-section-heading">
                    <div>
                        <p class="catalog-eyebrow">LA COLLECTION EQUITAB</p>
                        <h2 id="collection-heading">
                            À chaque envie, son univers.
                        </h2>
                    </div>
                </div>
                <div class="catalog-tools">
                    <div
                        class="catalog-filters"
                        aria-label="Filtrer les services par catégorie"
                    >
                        <button
                            type="button"
                            :aria-pressed="activeCategory === null"
                            @click="activeCategory = null"
                        >
                            Tout explorer<span>{{
                                totalServices
                            }}</span></button
                        ><button
                            v-for="category in categories"
                            :key="category.id"
                            type="button"
                            :aria-pressed="activeCategory === category.id"
                            @click="activeCategory = category.id"
                        >
                            {{ category.name
                            }}<span>{{ category.subscriptions.length }}</span>
                        </button>
                    </div>
                    <div class="catalog-search">
                        <Search :size="17" aria-hidden="true" /><label
                            class="catalog-sr"
                            for="collection-service-search"
                            >Rechercher un service</label
                        ><input
                            id="collection-service-search"
                            v-model="search"
                            type="search"
                            placeholder="Un service en tête ?"
                        /><button
                            v-if="search"
                            type="button"
                            aria-label="Effacer la recherche"
                            @click="search = ''"
                        >
                            <X :size="15" aria-hidden="true" />
                        </button>
                    </div>
                </div>
                <div class="catalog-context">
                    <p role="status" aria-live="polite">
                        <span />{{ filteredServices.length }} service{{
                            filteredServices.length > 1 ? "s" : ""
                        }}{{
                            search ? " pour « " + search + " »" : " à découvrir"
                        }}
                    </p>
                    <span>Parts indicatives · CAD / mois</span>
                </div>
                <div v-if="filteredServices.length" class="services-grid">
                    <CatalogServiceCard
                        v-for="service in filteredServices"
                        :key="service.id"
                        :name="service.name"
                        :slug="service.slug"
                        :category="service.category"
                        :monthly-price="service.monthly_price"
                        :max-members="service.max_members"
                        :motion="motion"
                    />
                </div>
                <div v-else class="catalog-empty">
                    <Search :size="30" aria-hidden="true" />
                    <h3>
                        {{
                            totalServices
                                ? "Pas encore de correspondance."
                                : "La collection se prépare."
                        }}
                    </h3>
                    <p>
                        {{
                            totalServices
                                ? "Essayez un autre nom ou explorez toutes les catégories."
                                : "Les services seront affichés ici dès qu’ils seront disponibles."
                        }}
                    </p>
                    <button
                        v-if="search || activeCategory !== null"
                        type="button"
                        @click="resetFilters"
                    >
                        Voir tous les services
                        <ArrowRight :size="16" aria-hidden="true" /></button
                    ><Link v-else href="/"
                        >Retour à l’accueil
                        <ArrowRight :size="16" aria-hidden="true"
                    /></Link>
                </div>
                <div class="catalog-price-note">
                    <Info :size="17" aria-hidden="true" />
                    <p>
                        Les parts indiquées sont des estimations pour un groupe
                        complet, hors frais éventuels. La capacité d’un
                        abonnement ne représente pas des places disponibles. Le
                        montant réel et les conditions de partage sont à
                        consulter dans chaque groupe avant de payer.
                    </p>
                </div>
            </section>
            <section class="share-invitation">
                <div>
                    <p class="catalog-eyebrow">
                        <SlidersHorizontal :size="13" aria-hidden="true" /> ET
                        SI VOUS PARTAGIEZ LE VÔTRE ?
                    </p>
                    <h2>
                        Déjà un abonnement.<br /><em
                            >Bientôt de la compagnie.</em
                        >
                    </h2>
                </div>
                <div>
                    <p>
                        Créez un groupe pour votre abonnement.<br />Les bonnes
                        habitudes commencent à plusieurs.
                    </p>
                    <Link href="/dashboard/groups/create"
                        >Partager un abonnement
                        <ArrowUpRight :size="18" aria-hidden="true"
                    /></Link>
                </div>
            </section>
        </main>
        <footer class="catalog-footer">
            <div class="catalog-width">
                <div class="catalog-footer-top">
                    <Link href="/" aria-label="Equitab — accueil"
                        ><EquitabWordmark light
                    /></Link>
                    <p>La suite se partage.</p>
                    <Link
                        :href="
                            page.props.auth?.user ? '/dashboard' : '/register'
                        "
                        :aria-label="
                            page.props.auth?.user
                                ? 'Ouvrir mon espace Equitab'
                                : 'Créer un compte Equitab'
                        "
                        ><ArrowUpRight :size="27" aria-hidden="true"
                    /></Link>
                </div>
                <div class="catalog-footer-bottom">
                    <span>Vos abonnements. Le plaisir de les partager.</span>
                    <nav aria-label="Informations légales">
                        <Link href="/charte">Charte de confiance</Link
                        ><Link href="/conditions">Conditions</Link
                        ><Link href="/confidentialite">Confidentialité</Link>
                    </nav>
                    <a href="mailto:support@equitab.ca">On est là pour vous.</a>
                </div>
            </div>
        </footer>
    </div>
</template>
<style scoped>
.services-collection {
    min-height: 100vh;
    background: #f6f8f6;
    color: #303b37;
    font-family: "Montserrat", sans-serif;
    -webkit-font-smoothing: antialiased;
}
.services-collection :where(a, button, input):focus-visible {
    outline: 3px solid #187a57;
    outline-offset: 4px;
}
.catalog-width {
    width: min(1180px, calc(100% - 80px));
    margin-inline: auto;
}
.catalog-skip {
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 50;
    transform: translateY(-180%);
    padding: 14px 20px;
    background: white;
    border: 2px solid #303b37;
    border-radius: 10px;
}
.catalog-skip:focus {
    transform: none;
}
.catalog-sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
.catalog-intro {
    display: grid;
    grid-template-columns: 1.85fr 1fr;
    gap: 32px;
    align-items: center;
    padding-block: 53px 56px;
}
.catalog-eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.12em;
}
.catalog-eyebrow > span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #35af7f;
}
h1 {
    font-size: clamp(40px, 4.5vw, 62px);
    font-weight: 500;
    letter-spacing: -0.065em;
    line-height: 1.09;
    margin-top: 18px;
}
h1 em {
    position: relative;
    font-family: Georgia, serif;
    font-weight: 400;
}
h1 svg {
    position: absolute;
    left: 0;
    bottom: -9px;
    width: 100%;
    color: #35af7f;
}
.catalog-intro-aside {
    justify-self: end;
}
.catalog-intro-aside p {
    font-size: 12px;
    line-height: 1.9;
    margin-top: 15px;
    color: #70747a;
}
.catalog-intro-aside strong {
    font-weight: 500;
    color: #3d4345;
}
.catalog-intro-aside > a {
    display: flex;
    align-items: center;
    gap: 24px;
    min-height: 44px;
    font-size: 11px;
    margin-top: 4px;
    font-weight: 500;
}
.catalog-tokens {
    display: flex;
    align-items: center;
    gap: 6px;
}
.catalog-tokens > span {
    display: grid;
    place-items: center;
    width: 43px;
    height: 43px;
    border-radius: 50%;
    background: #dff3e9;
    font-family: Georgia, serif;
    font-style: italic;
    font-size: 29px;
    transform: rotate(-8deg);
}
.catalog-tokens > span:last-child {
    width: 52px;
    background: #e7f3ed;
    font-family: inherit;
    font-style: normal;
    font-size: 12px;
    transform: rotate(9deg);
}
.catalog-tokens i {
    font-size: 13px;
    font-style: normal;
}
.catalog-section {
    scroll-margin-top: 24px;
    border-top: 1px solid #dce5df;
    padding-top: 28px;
}
.catalog-section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 24px;
}
.catalog-section-heading h2 {
    font-size: 26px;
    font-weight: 500;
    letter-spacing: -0.05em;
    margin-top: 9px;
    line-height: 1.3;
}
.catalog-tools {
    display: flex;
    align-items: start;
    justify-content: space-between;
    gap: 24px;
    margin-block: 26px 20px;
}
.catalog-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}
.catalog-filters button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 44px;
    border: 1px solid #ddddd9;
    border-radius: 26px;
    padding: 0 17px;
    font-size: 10px;
    cursor: pointer;
    transition:
        background 0.2s,
        color 0.2s;
}
.catalog-filters button[aria-pressed="true"] {
    background: #303b37;
    color: white;
    border-color: #303b37;
}
.catalog-filters button:hover:not([aria-pressed="true"]) {
    background: #e8e8e0;
}
.catalog-filters button span {
    font-size: 9px;
    opacity: 0.7;
}
.catalog-search {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 230px;
    min-height: 44px;
    border-bottom: 1px solid #cbcec6;
    padding: 0 4px;
    color: #737975;
}
.catalog-search input {
    width: 100%;
    min-width: 0;
    font-size: 11px;
    border: 0;
    background: none;
    box-shadow: none;
    padding: 10px 0;
    color: #303b37;
}
.catalog-search input::placeholder {
    color: #747b70;
}
.catalog-search > svg {
    flex: none;
}
.catalog-search button {
    flex: none;
    width: 32px;
    min-height: 44px;
    display: grid;
    place-items: center;
    cursor: pointer;
}
.catalog-context {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 16px;
    margin-bottom: 15px;
    color: #71757a;
    font-size: 10px;
}
.catalog-context p {
    overflow-wrap: anywhere;
}
.catalog-context p > span {
    display: inline-block;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #35af7f;
    margin: 0 6px 2px 0;
}
.catalog-context > span {
    flex: none;
}
.services-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px 20px;
}
.catalog-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 350px;
    border: 1px dashed #c8cdc0;
    border-radius: 24px;
    padding: 32px;
    text-align: center;
}
.catalog-empty h3 {
    font-size: 23px;
    font-weight: 500;
    letter-spacing: -0.04em;
    margin-top: 22px;
}
.catalog-empty p {
    font-size: 12px;
    line-height: 1.8;
    color: #71776c;
    margin-top: 10px;
}
.catalog-empty button,
.catalog-empty > a {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 46px;
    padding: 12px 20px;
    border-radius: 25px;
    background: #2b302c;
    color: white;
    font-size: 11px;
    margin-top: 24px;
    cursor: pointer;
}
.catalog-price-note {
    display: flex;
    align-items: start;
    gap: 11px;
    margin-top: 24px;
    color: #6c7468;
    font-size: 10px;
    line-height: 1.85;
    padding: 18px 20px;
    background: #edf4ef;
    border: 1px solid #dce5df;
    border-radius: 13px;
}
.catalog-price-note svg {
    flex: none;
    margin-top: 1px;
}
.share-invitation {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 40px;
    padding-block: 48px;
    margin-top: 44px;
    border-top: 1px solid #d9ddd1;
}
.share-invitation h2 {
    font-size: 35px;
    line-height: 1.15;
    font-weight: 500;
    letter-spacing: -0.05em;
    margin-top: 18px;
}
.share-invitation em {
    font-family: Georgia, serif;
    font-weight: 400;
}
.share-invitation > div:last-child p {
    font-size: 12px;
    color: #737c6b;
    line-height: 1.85;
}
.share-invitation a {
    display: inline-flex;
    align-items: center;
    gap: 23px;
    min-height: 48px;
    padding: 12px 20px;
    border: 1px solid #b6d7c6;
    border-radius: 30px;
    background: #dff3e9;
    margin-top: 20px;
    font-size: 11px;
}
.catalog-footer {
    background: #292d33;
    color: #f6f6ec;
    padding-block: 36px 22px;
}
.catalog-footer-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}
.catalog-footer-top p {
    font-size: 42px;
    letter-spacing: -0.06em;
}
.catalog-footer-top > a:last-child {
    display: grid;
    place-items: center;
    width: 54px;
    height: 54px;
    border: 1px solid #626966;
    border-radius: 50%;
    color: #b3e8cd;
}
.catalog-footer-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 22px;
    border-top: 1px solid #494e52;
    padding-top: 21px;
    margin-top: 32px;
    color: #c2c7bf;
    font-size: 9px;
    line-height: 1.8;
}
.catalog-footer-bottom nav {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
.catalog-footer-bottom a {
    display: inline-flex;
    align-items: center;
    min-height: 36px;
}
.catalog-footer-bottom a:hover {
    text-decoration: underline;
}
.without-motion :deep(*),
.without-motion :deep(*::before),
.without-motion :deep(*::after) {
    transition: none !important;
    animation: none !important;
}
@media (max-width: 1050px) {
    .catalog-width {
        width: calc(100% - 48px);
    }
    .services-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .catalog-tools {
        flex-wrap: wrap;
        gap: 14px;
    }
    .catalog-search {
        flex-basis: 260px;
    }
    .catalog-intro {
        grid-template-columns: 1.6fr 1fr;
        gap: 24px;
    }
    .catalog-intro-aside p {
        font-size: 11px;
    }
    .catalog-footer-top p {
        font-size: 32px;
    }
    .catalog-footer-bottom {
        align-items: start;
        flex-wrap: wrap;
    }
    .catalog-footer-bottom nav {
        order: 2;
        width: 100%;
    }
}
@media (max-width: 679px) {
    .catalog-width {
        width: calc(100% - 32px);
    }
    .catalog-intro {
        grid-template-columns: 1fr;
        gap: 28px;
        padding-block: 34px;
    }
    h1 {
        font-size: clamp(38px, 8.8vw, 54px);
    }
    .catalog-eyebrow {
        font-size: 8px;
    }
    .catalog-intro-aside {
        justify-self: stretch;
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 0 18px;
        align-items: center;
    }
    .catalog-intro-aside p {
        margin-top: 0;
        font-size: 10px;
    }
    .catalog-intro-aside > a {
        grid-column: 1 / -1;
        justify-content: space-between;
        margin-top: 12px;
        font-size: 10px;
    }
    .catalog-tokens > span {
        width: 34px;
        height: 34px;
        font-size: 25px;
    }
    .catalog-tokens > span:last-child {
        width: 42px;
        height: 34px;
        font-size: 10px;
    }
    .catalog-tokens {
        gap: 4px;
    }
    .catalog-section {
        padding-top: 24px;
    }
    .catalog-section-heading {
        align-items: center;
        gap: 15px;
    }
    .catalog-section-heading h2 {
        font-size: 24px;
        max-width: 225px;
    }
    .catalog-tools {
        gap: 14px;
        margin-block: 21px 18px;
    }
    .catalog-filters {
        display: flex;
        gap: 7px;
    }
    .catalog-filters button {
        padding: 0 13px;
        font-size: 10px;
    }
    .catalog-search {
        flex-basis: 100%;
    }
    .catalog-search input {
        font-size: 16px;
    }
    .catalog-context {
        font-size: 9px;
    }
    .catalog-context > span {
        font-size: 8px;
        max-width: 100px;
        text-align: right;
    }
    .services-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 21px;
    }
    .catalog-price-note {
        font-size: 10px;
        padding: 15px;
    }
    .share-invitation {
        flex-direction: column;
        align-items: start;
        gap: 24px;
        padding-block: 32px 38px;
        margin-top: 32px;
    }
    .share-invitation h2 {
        font-size: 32px;
    }
    .share-invitation > div:last-child p {
        font-size: 11px;
    }
    .catalog-footer-top {
        flex-wrap: wrap;
        gap: 25px;
    }
    .catalog-footer-top > a:first-child {
        width: 100%;
    }
    .catalog-footer-top p {
        font-size: 32px;
    }
    .catalog-footer-top > a:last-child {
        width: 46px;
        height: 46px;
    }
    .catalog-footer-bottom {
        flex-direction: column;
        gap: 9px;
        align-items: start;
        margin-top: 25px;
    }
    .catalog-footer-bottom nav {
        gap: 13px;
        order: 0;
    }
    .catalog-footer-bottom > span {
        display: none;
    }
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
