<script setup lang="ts">
import { computed, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { ArrowUpRight, Users } from "lucide-vue-next";
import ServiceArtwork from "./ServiceArtwork.vue";
import {
    formatCad,
    indicativeShare,
    servicePresentation,
} from "@/config/servicePresentation";
const props = defineProps<{
    name: string;
    slug: string;
    category: string;
    monthlyPrice: number | null;
    maxMembers: number | null;
    motion: boolean;
}>();
const presentation = computed(() =>
    servicePresentation(props.slug, props.category),
);
const share = computed(() =>
    indicativeShare(props.monthlyPrice, props.maxMembers),
);
const capacity = computed(() =>
    props.maxMembers !== null &&
    Number.isInteger(props.maxMembers) &&
    props.maxMembers > 0
        ? props.maxMembers
        : null,
);
const tilt = ref({ "--scene-x": "0deg", "--scene-y": "0deg" });
function move(event: PointerEvent) {
    if (!props.motion || event.pointerType !== "mouse") return;
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    tilt.value = {
        "--scene-x":
            (0.5 - (event.clientY - rect.top) / rect.height) * 8 + "deg",
        "--scene-y":
            ((event.clientX - rect.left) / rect.width - 0.5) * 10 + "deg",
    };
}
function reset() {
    tilt.value = { "--scene-x": "0deg", "--scene-y": "0deg" };
}
</script>
<template>
    <article
        class="catalog-card"
        :class="{ 'card-motion-off': !motion }"
        :style="tilt"
        @pointermove="move"
        @pointerleave="reset"
    >
        <ServiceArtwork
            :slug="slug"
            :scene="presentation.scene"
            :palette="presentation.palette"
            :category="category"
            :tagline="presentation.tagline"
            :motion="motion"
        />
        <div class="catalog-card-body">
            <div class="catalog-card-heading">
                <div>
                    <p>À PARTAGER ENSEMBLE</p>
                    <h3>{{ name }}</h3>
                </div>
            </div>
            <div class="catalog-value">
                <div>
                    <span class="value-label"
                        >Part indicative · groupe complet</span
                    >
                    <p v-if="share !== null">
                        {{ formatCad(share)
                        }}<small>CAD / mois · hors frais éventuels</small>
                    </p>
                    <p v-else class="unknown-price">
                        À confirmer<small
                            >Consultez les détails du groupe</small
                        >
                    </p>
                </div>
                <span class="catalog-capacity"
                    ><Users :size="16" aria-hidden="true" /><span
                        v-if="capacity"
                        >Jusqu’à {{ capacity }}<br />membres</span
                    ><span v-else>Capacité<br />à confirmer</span></span
                >
            </div>
            <Link
                :href="`/groups/service/${encodeURIComponent(slug)}`"
                class="catalog-card-link"
                :aria-label="'Voir les groupes pour ' + name"
                ><span>Voir les groupes</span
                ><span class="catalog-arrow"
                    ><ArrowUpRight :size="19" aria-hidden="true" /></span
            ></Link>
        </div>
    </article>
</template>
<style scoped>
.catalog-card {
    min-width: 0;
    background: #fff;
    border: 1px solid #dfdfdf;
    border-radius: 24px;
    box-shadow:
        0 4px 4px #15172102,
        0 15px 34px -30px #15172170;
    transition:
        border-color 0.25s,
        box-shadow 0.25s;
}
.catalog-card:hover,
.catalog-card:focus-within {
    border-color: #83858c;
    box-shadow: 0 20px 40px -26px #15172155;
}
.catalog-card-body {
    padding: 20px 22px 17px;
}
.catalog-card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}
.catalog-card-heading p {
    font-size: 9px;
    letter-spacing: 0.08em;
    color: #73767d;
    margin-bottom: 7px;
}
.catalog-card-heading h3 {
    font-size: 24px;
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: -0.045em;
    overflow-wrap: anywhere;
}
.category-mark {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    flex: none;
    border: 1px solid #e3e4df;
    border-radius: 12px;
    font-family: Georgia, serif;
    font-style: italic;
    font-size: 20px;
    color: #585e55;
    background: #f4f5ef;
}
.catalog-value {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 10px;
    margin-block: 25px 24px;
}
.value-label {
    display: block;
    font-size: 10px;
    color: #6b6d77;
}
.catalog-value p {
    font-size: 30px;
    font-weight: 550;
    letter-spacing: -0.05em;
    margin-top: 7px;
    line-height: 1.15;
}
.catalog-value small {
    display: block;
    font-size: 10px;
    font-weight: 400;
    letter-spacing: 0;
    color: #696d77;
    line-height: 1.5;
    margin-top: 6px;
}
.catalog-value .unknown-price {
    font-size: 23px;
}
.catalog-capacity {
    display: flex;
    flex-direction: column;
    align-items: end;
    gap: 5px;
    flex: none;
    text-align: right;
    color: #636b64;
    font-size: 10px;
    line-height: 1.5;
}
.catalog-card-link {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    min-height: 62px;
    border-top: 1px solid #e9eaed;
    padding-top: 14px;
    font-size: 12px;
    font-weight: 500;
}
.catalog-arrow {
    width: 37px;
    height: 37px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    color: #fff;
    background: #252a30;
    transition:
        transform 0.2s,
        background 0.2s;
}
.catalog-card-link:hover .catalog-arrow {
    background: #187a57;
    transform: rotate(8deg);
}
.catalog-card-link:focus-visible {
    outline: 3px solid #187a57;
    outline-offset: 4px;
    border-radius: 6px;
}
.card-motion-off,
.card-motion-off :deep(*) {
    transition: none !important;
    animation: none !important;
}
@media (max-width: 360px) {
    .catalog-card-body {
        padding: 18px 17px 14px;
    }
    .catalog-value {
        gap: 7px;
    }
    .value-label {
        font-size: 9px;
    }
    .catalog-capacity {
        font-size: 9px;
    }
    .catalog-value small {
        font-size: 9px;
    }
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        transition: none !important;
        animation: none !important;
    }
}
</style>
