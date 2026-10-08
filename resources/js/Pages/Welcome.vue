<script setup lang="ts">
import { computed, ref } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import {
    ArrowRight,
    ArrowUpRight,
    Search,
    Sparkles,
    Users,
    CircleDollarSign,
    Plus,
    X,
} from "lucide-vue-next";
import NavbarWithSearch from "@/Components/NavbarWithSearch.vue";
import Footer from "@/Components/Footer.vue";
import CollectionCard from "@/Components/Experience/CollectionCard.vue";
import ServiceArtwork from "@/Components/Experience/ServiceArtwork.vue";
import { servicePresentation } from "@/config/servicePresentation";
import { useExperienceMotion } from "@/composables/useExperienceMotion";
interface CatalogService {
    name: string;
    slug: string;
    pricePerMember: number;
    discountPercent: number;
}
interface OpenGroup {
    id: number;
    subscriptionName: string;
    subscriptionSlug: string;
    ownerName?: string;
    pricePerMember: number;
    currency: string;
    currentMembers: number;
    maxMembers: number;
}
const props = defineProps<{
    canLogin: boolean;
    canRegister: boolean;
    isAuthenticated: boolean;
    catalogServices: CatalogService[];
    openGroups: OpenGroup[];
}>();
const { motion } = useExperienceMotion();
const query = ref("");
const filter = ref("all");
const role = ref<"join" | "share">("join");
const normalize = (v: string) =>
    v
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .trim();
const cards = computed(() =>
    props.openGroups.length
        ? props.openGroups.map((g) => ({
              key: "group-" + g.id,
              name: g.subscriptionName,
              slug: g.subscriptionSlug,
              price: g.pricePerMember,
              currency: g.currency,
              members: g.currentMembers,
              capacity: g.maxMembers,
              owner: g.ownerName,
              href:
                  "/groups/service/" +
                  encodeURIComponent(g.subscriptionSlug) +
                  "#group-" +
                  g.id,
          }))
        : props.catalogServices.slice(0, 6).map((s) => ({
              key: s.slug,
              name: s.name,
              slug: s.slug,
              price: null,
              currency: undefined,
              members: undefined,
              capacity: undefined,
              owner: undefined,
              href: "/groups/service/" + encodeURIComponent(s.slug),
          })),
);
const filtered = computed(() =>
    cards.value.filter((card) => {
        const scene = servicePresentation(card.slug, "").scene;
        return (
            normalize(card.name).includes(normalize(query.value)) &&
            (filter.value === "all" ||
                (filter.value === "music"
                    ? scene === "music"
                    : scene === "cinema" ||
                      scene === "world" ||
                      scene === "play"))
        );
    }),
);
const category = (slug: string) => {
    const scene = servicePresentation(slug, "").scene;
    return scene === "music"
        ? "Musique"
        : scene === "cinema" || scene === "world" || scene === "play"
          ? "À découvrir ensemble"
          : "Le quotidien partagé";
};
const steps = computed(() =>
    role.value === "join"
        ? [
              {
                  title: "Trouvez votre univers.",
                  text: "Explorez les services et comparez les groupes proposés au partage.",
                  icon: Search,
              },
              {
                  title: "Choisissez votre groupe.",
                  text: "Consultez le propriétaire, les places et le détail des montants avant de vous engager.",
                  icon: Users,
              },
              {
                  title: "Gardez tout à portée.",
                  text: "Vos abonnements, vos messages et vos paiements, au même endroit.",
                  icon: CircleDollarSign,
              },
          ]
        : [
              {
                  title: "Préparez votre profil.",
                  text: "Complétez les vérifications nécessaires pour proposer votre abonnement.",
                  icon: Users,
              },
              {
                  title: "Faites de la place.",
                  text: "Choisissez le service, le prix, les places et la visibilité du groupe.",
                  icon: Plus,
              },
              {
                  title: "Partagez le quotidien.",
                  text: "Invitez vos membres et suivez les paiements depuis votre espace.",
                  icon: CircleDollarSign,
              },
          ],
);
const faqs = [
    [
        "Faut-il déjà avoir un abonnement ?",
        "Non. Vous pouvez rejoindre un groupe depuis le catalogue. Si vous possédez un abonnement compatible avec le partage, vous pouvez proposer vos places.",
    ],
    [
        "Comment est calculée ma part ?",
        "Votre part dépend du tarif et du nombre de membres du groupe. Les prix du catalogue sont indicatifs pour un groupe complet, hors frais éventuels. Les montants à régler et les échéances sont présentés avant le paiement.",
    ],
    [
        "Quels services peut-on partager ?",
        "Chaque service conserve ses conditions, notamment les règles de foyer et de partage. Vérifiez que votre utilisation les respecte avant de rejoindre ou de créer un groupe.",
    ],
];
</script>
<template>
    <Head title="Les bonnes choses se partagent" />
    <div class="eq-experience home-collection">
        <a href="#main-content" class="eq-skip eq-button">Aller au contenu</a>
        <NavbarWithSearch
            :can-login="canLogin"
            :can-register="canRegister"
            :is-authenticated="isAuthenticated"
        />
        <main id="main-content">
            <section class="eq-container home-hero">
                <div>
                    <p class="eq-eyebrow">
                        <span class="home-dot" /> LES BONNES CHOSES SE
                        PARTAGENT.
                    </p>
                    <h1>
                        Vos envies.<br />En meilleure<br class="hero-break" />
                        <em>compagnie.</em>
                    </h1>
                    <p class="hero-description">
                        Gardez ce que vous aimez. Partagez les frais.<br />Et
                        faites de la place à un peu plus de vie.
                    </p>
                    <div class="hero-actions">
                        <a href="#collection" class="eq-button"
                            >Trouver mon groupe <ArrowUpRight :size="17" /></a
                        ><Link href="/dashboard/groups/create" class="eq-link"
                            >Partager le mien <Plus :size="16"
                        /></Link>
                    </div>
                </div>
                <div class="hero-art" aria-hidden="true">
                    <div class="hero-art-caption">
                        LA VIE EST MIEUX<br />QUAND ELLE SE PARTAGE.
                    </div>
                    <div class="hero-art-first">
                        <ServiceArtwork
                            scene="music"
                            slug="spotify"
                            category="LA BANDE-SON"
                            tagline="À écouter ensemble."
                            :motion="false"
                        />
                    </div>
                    <div class="hero-art-second">
                        <ServiceArtwork
                            scene="cinema"
                            slug="netflix"
                            category="LE GRAND ÉCRAN"
                            tagline="Les bonnes histoires se partagent."
                            :motion="false"
                        />
                    </div>
                    <span class="hero-sticker">vous.<br /><i>+ nous.</i></span>
                    <p>Un abonnement. Chacun sa part.</p>
                </div>
            </section>
            <section id="collection" class="eq-container home-catalog">
                <div class="collection-top">
                    <div>
                        <p class="eq-eyebrow">LA COLLECTION / 001</p>
                        <h2>De quoi vous retrouver.</h2>
                    </div>
                </div>
                <div class="collection-controls">
                    <div
                        class="eq-pills"
                        role="group"
                        aria-label="Filtrer la collection"
                    >
                        <button
                            :aria-pressed="filter === 'all'"
                            @click="filter = 'all'"
                        >
                            Tout explorer</button
                        ><button
                            :aria-pressed="filter === 'screen'"
                            @click="filter = 'screen'"
                        >
                            Films & découvertes</button
                        ><button
                            :aria-pressed="filter === 'music'"
                            @click="filter = 'music'"
                        >
                            Musique
                        </button>
                    </div>
                    <div class="collection-search">
                        <Search :size="17" aria-hidden="true" /><label
                            for="home-collection-search"
                            class="sr-only"
                            >Rechercher dans la collection</label
                        ><input
                            id="home-collection-search"
                            v-model="query"
                            type="search"
                            placeholder="Un service en tête ?"
                        /><button
                            v-if="query"
                            aria-label="Effacer la recherche"
                            @click="query = ''"
                        >
                            <X :size="16" />
                        </button>
                    </div>
                </div>
                <div class="collection-count">
                    <p aria-live="polite">
                        <span class="home-dot" /> {{ filtered.length }}
                        {{ openGroups.length ? "groupe(s)" : "service(s)" }} à
                        découvrir
                    </p>
                    <span>Prix dans la devise de chaque groupe</span>
                </div>
                <div v-if="filtered.length" class="eq-collection-grid">
                    <CollectionCard
                        v-for="(card, index) in filtered"
                        v-bind="card"
                        :category="category(card.slug)"
                        :eyebrow="
                            openGroups.length
                                ? 'UN GROUPE À DÉCOUVRIR'
                                : 'LE CATALOGUE EQUITAB'
                        "
                        :motion="motion"
                        :index="index + 1"
                    />
                </div>
                <div v-else class="eq-panel home-empty">
                    <Sparkles :size="30" />
                    <h3>
                        {{
                            cards.length
                                ? "Pas encore de résultat."
                                : "La collection se prépare."
                        }}
                    </h3>
                    <p>
                        {{
                            cards.length
                                ? "Essayez un autre nom ou retrouvez toute la collection."
                                : "Revenez bientôt découvrir les services proposés au partage."
                        }}
                    </p>
                    <button
                        v-if="cards.length"
                        class="eq-button eq-button-secondary"
                        @click="
                            query = '';
                            filter = 'all';
                        "
                    >
                        Tout afficher
                    </button>
                </div>
                <div class="collection-footnote">
                    <p>
                        Les places et les parts peuvent évoluer. Consultez les
                        détails du groupe avant de vous engager.
                    </p>
                    <Link href="/services"
                        >Tout le catalogue <ArrowRight :size="15"
                    /></Link>
                </div>
            </section>
            <section class="eq-container home-principles">
                <div>
                    <p class="eq-eyebrow">LE PLAISIR DE PARTAGER</p>
                    <h2>
                        De belles découvertes.<br /><span
                            >Les idées claires.</span
                        >
                    </h2>
                    <p>
                        De la personnalité pour explorer.<br />De la simplicité
                        pour s’y retrouver.
                    </p>
                </div>
                <article
                    v-for="item in [
                        {
                            icon: Sparkles,
                            title: 'À chacun son univers.',
                            text: 'Musique, cinéma ou quotidien. Trouvez ce qui vous ressemble.',
                        },
                        {
                            icon: Users,
                            title: 'La place de chacun.',
                            text: 'Les membres et les places disponibles se comprennent au premier regard.',
                        },
                        {
                            icon: CircleDollarSign,
                            title: 'L’essentiel, au clair.',
                            text: 'Vos montants, vos échéances et vos échanges, au même endroit.',
                        },
                    ]"
                    :key="item.title"
                >
                    <component :is="item.icon" :size="22" />
                    <h3>{{ item.title }}</h3>
                    <p>{{ item.text }}</p>
                </article>
            </section>
            <section id="comment-ca-marche" class="home-how">
                <div class="eq-container">
                    <div class="collection-top">
                        <div>
                            <p class="eq-eyebrow">
                                UN PEU DE VOUS. UN PEU DES AUTRES.
                            </p>
                            <h2>Chacun sa place.<br />Chacun sa part.</h2>
                        </div>
                        <div
                            class="eq-pills"
                            role="group"
                            aria-label="Choisir un parcours"
                        >
                            <button
                                :aria-pressed="role === 'join'"
                                @click="role = 'join'"
                            >
                                Je rejoins</button
                            ><button
                                :aria-pressed="role === 'share'"
                                @click="role = 'share'"
                            >
                                Je partage
                            </button>
                        </div>
                    </div>
                    <div class="how-steps" aria-live="polite">
                        <article
                            v-for="(step, index) in steps"
                            :key="step.title"
                        >
                            <span>0{{ index + 1 }}</span
                            ><component :is="step.icon" :size="22" />
                            <h3>{{ step.title }}</h3>
                            <p>{{ step.text }}</p>
                        </article>
                    </div>
                    <Link
                        :href="
                            role === 'join'
                                ? '/services'
                                : '/dashboard/groups/create'
                        "
                        class="eq-button"
                        >On commence ? <ArrowUpRight :size="17"
                    /></Link>
                </div>
            </section>
            <section class="eq-container home-faq">
                <div>
                    <p class="eq-eyebrow">ON VOUS EXPLIQUE</p>
                    <h2>Les bonnes questions.<br />Tout simplement.</h2>
                </div>
                <div>
                    <details v-for="[question, answer] in faqs" :key="question">
                        <summary>
                            {{ question }}<Plus :size="18" aria-hidden="true" />
                        </summary>
                        <p>{{ answer }}</p>
                    </details>
                </div>
            </section>
            <section class="eq-container home-finale">
                <p class="eq-eyebrow">LA SUITE, C’EST ENSEMBLE.</p>
                <h2>On se retrouve<br /><em>dans un groupe ?</em></h2>
                <Link href="/services" class="eq-button"
                    >Explorer les services <ArrowUpRight :size="17" /></Link
                ><span aria-hidden="true">✳</span>
            </section>
        </main>
        <Footer />
    </div>
</template>
<style scoped>
.home-hero {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    align-items: center;
    gap: 50px;
    padding-block: 70px 80px;
}
.home-hero > div {
    min-width: 0;
}
.home-hero .eq-eyebrow {
    font-size: 9px;
    letter-spacing: 0.1em;
}
.home-dot {
    display: inline-block;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #35af7f;
    margin-right: 5px;
    vertical-align: middle;
}
h1 {
    font-size: clamp(44px, 5.7vw, 80px);
    font-weight: 500;
    line-height: 1.01;
    letter-spacing: -0.07em;
    margin: 28px 0;
}
h1 em,
.home-finale em {
    font-family: Georgia, serif;
    font-weight: 400;
    letter-spacing: -0.06em;
}
.hero-description {
    font-size: 13px;
    line-height: 1.9;
    color: #686b70;
}
.hero-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 25px;
    margin-top: 28px;
}
.hero-art {
    height: 420px;
    position: relative;
    background: #e7f3ed;
    border-radius: 50% 50% 24px 24px;
    margin: 0 20px;
}
.hero-art-caption {
    position: absolute;
    top: 33px;
    left: 30%;
    font-size: 9px;
    letter-spacing: 0.11em;
    line-height: 1.7;
}
.hero-art-first,
.hero-art-second {
    position: absolute;
    width: 66%;
    box-shadow: 0 24px 35px -28px #222b2ccc;
    border-radius: 22px;
}
.hero-art-first {
    top: 78px;
    left: -18px;
    transform: rotate(-13deg);
}
.hero-art-second {
    top: 147px;
    right: -17px;
    transform: rotate(10deg);
}
.hero-sticker {
    position: absolute;
    background: #dff3e9;
    width: 90px;
    height: 90px;
    border-radius: 50%;
    display: grid;
    align-content: center;
    text-align: center;
    font:
        italic 22px/1.1 Georgia,
        serif;
    bottom: 43px;
    left: 21px;
    transform: rotate(-12deg);
    border: 1px solid #acd9bf;
}
.hero-art > p {
    position: absolute;
    bottom: 25px;
    right: 25px;
    font-size: 10px;
}
.home-catalog {
    border-top: 1px solid #dce5df;
    padding-top: 32px;
    scroll-margin-top: 24px;
}
.collection-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}
.eq-eyebrow {
    font-size: 9px;
    letter-spacing: 0.13em;
    font-weight: 500;
}
h2 {
    font-size: clamp(25px, 2.8vw, 36px);
    line-height: 1.2;
    font-weight: 500;
    letter-spacing: -0.055em;
    margin-top: 14px;
}
.collection-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    margin-top: 26px;
}
.collection-search {
    display: flex;
    align-items: center;
    border-bottom: 1px solid #d8dad3;
    width: 220px;
    flex: none;
    color: #686b70;
}
.collection-search input {
    width: 100%;
    min-width: 0;
    font-size: 11px;
    background: transparent;
    padding: 14px 10px;
}
.collection-search button {
    min-width: 36px;
    min-height: 44px;
    display: grid;
    place-items: center;
}
.collection-count,
.collection-footnote {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    font-size: 9px;
    color: #686b70;
    line-height: 1.8;
}
.collection-count {
    margin: 18px 0 14px;
}
.collection-footnote {
    margin-top: 18px;
}
.collection-footnote a {
    display: flex;
    align-items: center;
    gap: 9px;
    flex: none;
    min-height: 44px;
    color: #303b37;
    font-size: 11px;
}
.home-principles {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1fr 1fr;
    gap: 44px;
    border-top: 1px solid #dce5df;
    margin-top: 38px;
    padding-block: 40px 60px;
}
.home-principles h2 {
    font-size: 25px;
}
.home-principles h2 span {
    color: #85877d;
}
.home-principles h3 {
    font-size: 12px;
    font-weight: 550;
    margin-top: 21px;
}
.home-principles p:not(.eq-eyebrow) {
    font-size: 11px;
    color: #686b70;
    line-height: 1.9;
    margin-top: 10px;
}
.home-principles article > svg {
    box-sizing: content-box;
    background: #edf4ef;
    border: 1px solid #dce5df;
    padding: 10px;
    border-radius: 14px;
}
.home-how {
    background: #e7f3ed;
    padding: 65px 0;
    scroll-margin-top: 30px;
}
.home-how h2 {
    font-size: 40px;
}
.how-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 50px;
    margin: 44px 0 32px;
}
.how-steps article {
    border-top: 1px solid #c9cebf;
    padding-top: 22px;
}
.how-steps span {
    font-size: 11px;
}
.how-steps svg {
    float: right;
}
.how-steps h3 {
    font-size: 17px;
    font-weight: 550;
    margin: 24px 0 12px;
}
.how-steps p {
    font-size: 12px;
    line-height: 1.9;
    color: #65756d;
}
.home-faq {
    display: grid;
    grid-template-columns: 1fr 1.3fr;
    gap: 70px;
    padding-block: 75px;
}
.home-faq details {
    border-bottom: 1px solid #dce5df;
}
.home-faq summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    cursor: pointer;
    font-size: 13px;
    padding: 22px 0;
}
.home-faq summary svg {
    flex: none;
}
.home-faq details[open] summary svg {
    transform: rotate(45deg);
}
.home-faq details p {
    font-size: 12px;
    color: #686b70;
    line-height: 1.9;
    padding-bottom: 24px;
}
.home-finale {
    background: #e7f3ed;
    padding: 44px 50px;
    border-radius: 28px;
    margin-bottom: 60px;
    position: relative;
}
.home-finale h2 {
    font-size: clamp(34px, 5vw, 64px);
    margin: 23px 0;
}
.home-finale > span {
    position: absolute;
    right: 70px;
    top: 40px;
    font-size: 140px;
    color: #187a57;
}
.home-empty {
    text-align: center;
    padding: 45px 24px;
}
.home-empty svg {
    margin: auto;
}
.home-empty h3 {
    margin-top: 16px;
    font-size: 20px;
}
.home-empty p {
    font-size: 12px;
    color: #686b70;
    margin: 12px 0 22px;
}
@media (max-width: 1000px) {
    .home-hero {
        gap: 24px;
    }
    .hero-art {
        height: 350px;
        margin-inline: 8px;
    }
    .hero-art > p {
        right: 15px;
        font-size: 8px;
    }
    .home-principles {
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }
    .hero-sticker {
        width: 70px;
        height: 70px;
        font-size: 18px;
        bottom: 24px;
    }
    .how-steps {
        gap: 25px;
    }
}
@media (max-width: 700px) {
    .home-hero {
        grid-template-columns: 1fr;
        padding-block: 38px;
        gap: 38px;
    }
    .hero-break {
        display: initial;
    }
    h1 {
        font-size: clamp(42px, 9vw, 64px);
    }
    .hero-art {
        max-width: 400px;
        width: calc(100% - 32px);
        height: 360px;
        margin: auto;
    }
    .hero-art-first {
        top: 65px;
        left: 0;
        width: 62%;
    }
    .hero-art-second {
        top: 135px;
        right: 0;
        width: 62%;
    }
    .collection-controls {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }
    .collection-search {
        width: 100%;
    }
    .collection-top {
        align-items: start;
    }
    .eq-pills {
        gap: 6px;
    }
    .eq-pills button {
        font-size: 10px;
        padding: 10px 13px;
    }
    .collection-footnote {
        flex-direction: column;
        gap: 5px;
    }
    .home-principles {
        grid-template-columns: 1fr;
        gap: 28px;
    }
    .home-principles article {
        display: grid;
        grid-template-columns: 42px 1fr;
        gap: 0 18px;
    }
    .home-principles article > svg {
        grid-row: span 2;
    }
    .home-principles h3 {
        margin-top: 0;
    }
    .home-principles article p {
        margin-top: 5px;
    }
    .home-how {
        padding-block: 40px;
    }
    .home-how .collection-top {
        flex-direction: column;
    }
    .home-how h2 {
        font-size: 32px;
    }
    .how-steps {
        grid-template-columns: 1fr;
        gap: 30px;
    }
    .home-faq {
        grid-template-columns: 1fr;
        gap: 24px;
        padding-block: 45px;
    }
    .home-finale {
        padding: 30px 24px;
    }
    .home-finale > span {
        font-size: 50px;
        top: 25px;
        right: 20px;
    }
    .home-finale > .eq-eyebrow {
        max-width: 150px;
        line-height: 1.8;
    }
}
</style>
