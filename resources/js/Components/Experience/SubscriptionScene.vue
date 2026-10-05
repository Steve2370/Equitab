<script setup lang="ts">
import { computed, ref } from "vue";
import ServiceArtwork from "./ServiceArtwork.vue";
import { ArrowUpRight, Heart, Users, Check } from "lucide-vue-next";
export interface SceneService {
    id: string;
    name: string;
    category: string;
    categoryLabel: string;
    scene: "cinema" | "music" | "world";
    tagline: string;
    priceCents: number;
    members: number;
    capacity: number;
    owner: string;
    description: string;
}
const props = defineProps<{
    service: SceneService;
    favorite: boolean;
    motion: boolean;
}>();
const emit = defineEmits<{
    explore: [service: SceneService, trigger: HTMLButtonElement];
    favorite: [id: string];
}>();
const shift = ref({ x: 0, y: 0 });
const sceneStyle = computed(() => ({
    "--scene-x": shift.value.x + "deg",
    "--scene-y": shift.value.y + "deg",
}));
const formattedPrice = computed(() =>
    new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(props.service.priceCents / 100),
);
const spots = computed(() =>
    Math.max(0, props.service.capacity - props.service.members),
);
function move(event: PointerEvent) {
    if (!props.motion || event.pointerType !== "mouse") return;
    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();
    shift.value = {
        x: (0.5 - (event.clientY - bounds.top) / bounds.height) * 8,
        y: ((event.clientX - bounds.left) / bounds.width - 0.5) * 10,
    };
}
function reset() {
    shift.value = { x: 0, y: 0 };
}
</script>

<template>
    <article
        class="collection-card"
        :class="['theme-' + service.scene, { 'motion-enabled': motion }]"
        :style="sceneStyle"
        @pointermove="move"
        @pointerleave="reset"
    >
        <ServiceArtwork
            :scene="service.scene"
            :category="service.categoryLabel"
            :tagline="service.tagline"
            :index="service.id"
            :motion="motion"
        >
            <button
                type="button"
                class="save-service"
                :class="{ saved: favorite }"
                :aria-pressed="favorite"
                :aria-label="
                    (favorite ? 'Retirer ' : 'Ajouter ') +
                    service.name +
                    (favorite ? ' des favoris' : ' aux favoris')
                "
                @click="emit('favorite', service.id)"
            >
                <Heart
                    :size="17"
                    :fill="favorite ? 'currentColor' : 'none'"
                    aria-hidden="true"
                />
            </button>
        </ServiceArtwork>
        <div class="card-information">
            <div class="card-heading">
                <div>
                    <p class="card-overline">GROUPE DE DÉMONSTRATION</p>
                    <h3>{{ service.name }}</h3>
                </div>
                <span class="availability" :class="{ full: spots === 0 }"
                    ><span aria-hidden="true" />{{
                        spots
                            ? spots + (spots > 1 ? " places" : " place")
                            : "Complet"
                    }}</span
                >
            </div>
            <div class="card-price">
                <div>
                    <span class="price-label">Part estimée</span>
                    <p>{{ formattedPrice }} <small>CAD / mois</small></p>
                </div>
                <span
                    class="group-members"
                    :aria-label="
                        service.members + ' membres sur ' + service.capacity
                    "
                    ><span class="member-dots" aria-hidden="true"
                        ><i
                            v-for="n in service.capacity"
                            :key="n"
                            :class="{ filled: n <= service.members }"
                            ><Check
                                v-if="n <= service.members"
                                :size="10" /></i></span
                    ><small
                        >{{ service.members }} /
                        {{ service.capacity }} membres</small
                    ></span
                >
            </div>
            <div class="card-bottom">
                <p>
                    <Users :size="15" aria-hidden="true" /><span
                        >Avec {{ service.owner }}</span
                    >
                </p>
                <button
                    type="button"
                    class="open-service"
                    :aria-label="'Découvrir le groupe ' + service.name"
                    @click="
                        emit(
                            'explore',
                            service,
                            $event.currentTarget as HTMLButtonElement,
                        )
                    "
                >
                    <span>{{ spots ? "Découvrir" : "Voir le groupe" }}</span
                    ><span class="open-arrow"
                        ><ArrowUpRight :size="18" aria-hidden="true"
                    /></span>
                </button>
            </div>
        </div>
    </article>
</template>

<style scoped>
.collection-card {
    --scene-x: 0deg;
    --scene-y: 0deg;
    position: relative;
    min-width: 0;
    border: 1px solid #dfdfdf;
    border-radius: 24px;
    background: #fff;
    box-shadow:
        0 4px 4px #15172102,
        0 15px 34px -30px #15172170;
    transition:
        border-color 0.25s,
        box-shadow 0.25s;
}
.collection-card:hover,
.collection-card:focus-within {
    border-color: #83858c;
    box-shadow: 0 20px 40px -26px #15172155;
}
.save-service {
    position: absolute;
    z-index: 3;
    right: 12px;
    top: 10px;
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #ffffffb8;
    color: #222636;
    cursor: pointer;
    border: 1px solid #ffffff50;
    backdrop-filter: blur(10px);
    transition:
        background 0.2s,
        transform 0.2s;
}
.save-service:hover {
    background: white;
}
.save-service:active {
    transform: scale(0.91);
}
.save-service.saved {
    background: #dbfca7;
    color: #183925;
}
.card-information {
    padding: 20px 22px 18px;
    color: #202330;
}
.card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.card-overline {
    font-size: 8px;
    letter-spacing: 0.09em;
    color: #72747d;
    margin-bottom: 6px;
}
h3 {
    font-size: 23px;
    line-height: 1.1;
    font-weight: 650;
    letter-spacing: -0.045em;
}
.availability {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #eef5e5;
    color: #345328;
    font-size: 10px;
    border-radius: 20px;
    padding: 6px 8px;
    white-space: nowrap;
}
.availability > span {
    width: 5px;
    height: 5px;
    background: currentColor;
    border-radius: 50%;
}
.availability.full {
    background: #f0f0f2;
    color: #696b74;
}
.card-price {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 12px;
    padding-block: 22px;
}
.price-label {
    font-size: 10px;
    color: #6b6d77;
}
.card-price p {
    font-size: 30px;
    font-weight: 600;
    letter-spacing: -0.05em;
    margin-top: 4px;
    line-height: 1.15;
}
.card-price p small {
    font-size: 10px;
    font-weight: 400;
    color: #6b6d77;
    letter-spacing: 0;
    display: block;
    margin-top: 5px;
}
.group-members {
    text-align: right;
}
.member-dots {
    display: flex;
    justify-content: end;
    gap: 3px;
    margin-bottom: 8px;
}
.member-dots i {
    display: grid;
    place-items: center;
    width: 17px;
    height: 17px;
    border: 1px dashed #b8bdc1;
    border-radius: 50%;
    color: #49662d;
}
.member-dots .filled {
    background: #e5edda;
    border: 1px solid #d3e0c3;
}
.group-members small {
    color: #666a73;
    font-size: 9px;
}
.card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid #e9eaed;
    padding-top: 15px;
}
.card-bottom p {
    display: flex;
    gap: 7px;
    align-items: center;
    font-size: 11px;
    color: #676b74;
}
.open-service {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 0 0 0 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
}
.open-arrow {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    background: #232631;
    color: white;
    border-radius: 50%;
    transition:
        transform 0.2s,
        background 0.2s;
}
.open-service:hover .open-arrow {
    background: #485c34;
    transform: rotate(8deg);
}
.open-service:active .open-arrow {
    transform: scale(0.9);
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        transition: none !important;
        animation: none !important;
    }
    .scene-object {
        transform: none !important;
    }
}
.collection-card:not(.motion-enabled) .scene-object {
    transform: none;
}
@media (max-width: 360px) {
    .card-information {
        padding: 18px;
    }
    .card-scene {
        height: 250px;
    }
    .card-heading {
        align-items: start;
    }
    .card-overline {
        font-size: 7px;
    }
    .availability {
        padding: 5px 6px;
    }
}
</style>
