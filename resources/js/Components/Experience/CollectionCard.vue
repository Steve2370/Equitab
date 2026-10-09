<script setup lang="ts">
import { computed, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { ArrowUpRight, Check, Users } from "lucide-vue-next";
import ServiceArtwork from "./ServiceArtwork.vue";
import { servicePresentation } from "@/config/servicePresentation";
import { formatMoney } from "@/utils/money";
const props = withDefaults(
    defineProps<{
        name: string;
        slug: string;
        category?: string;
        eyebrow?: string;
        price?: number | null;
        currency?: string;
        priceLabel?: string;
        members?: number;
        capacity?: number;
        owner?: string;
        status?: string;
        href?: string;
        action?: string;
        motion?: boolean;
        index?: number;
        presentation?: "discovery" | "workspace";
    }>(),
    {
        category: "À partager",
        eyebrow: "LE PLAISIR EN COMMUN",
        price: null,
        priceLabel: "Part actuelle",
        action: "Découvrir",
        motion: false,
        presentation: "discovery",
    },
);
const art = computed(() => servicePresentation(props.slug, props.category));
const validPrice = computed(
    () =>
        props.price !== null &&
        Number.isFinite(props.price) &&
        props.price >= 0,
);
const validCapacity = computed(
    () =>
        Number.isInteger(props.capacity) &&
        props.capacity! > 0 &&
        Number.isInteger(props.members) &&
        props.members! >= 0 &&
        props.members! <= props.capacity!,
);
const available = computed(() =>
    validCapacity.value ? props.capacity! - props.members! : null,
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
        class="collection-card"
        :style="tilt"
        @pointermove="move"
        @pointerleave="reset"
    >
        <ServiceArtwork
            v-if="presentation === 'discovery'"
            :slug="slug"
            :scene="art.scene"
            :palette="art.palette"
            :category="category"
            :tagline="art.tagline"
            :motion="motion"
            :index="index ? String(index).padStart(2, '0') : ''"
        />
        <div v-else class="workspace-card-identity">
            <span>{{ category }}</span>
        </div>
        <div class="collection-card-content">
            <div class="collection-card-title">
                <div>
                    <p class="card-eyebrow">{{ eyebrow }}</p>
                    <h3>{{ name }}</h3>
                </div>
                <span
                    v-if="status || available !== null"
                    class="collection-card-status"
                    :class="{ 'is-full': available === 0 }"
                    >{{
                        status ||
                        (available === 0
                            ? "Complet"
                            : `${available} place${available! > 1 ? "s" : ""}`)
                    }}</span
                >
            </div>
            <div class="collection-card-value">
                <div v-if="validPrice">
                    <span>{{ priceLabel }}</span>
                    <p>{{ formatMoney(price!, currency ?? '') }}</p>
                    <small>/ mois</small>
                </div>
                <div v-else>
                    <span>Votre prochaine découverte</span>
                    <p class="collection-no-price">À partager.</p>
                    <small>Consultez les offres des groupes</small>
                </div>
                <div v-if="validCapacity" class="collection-members">
                    <div aria-hidden="true">
                        <i
                            v-for="n in Math.min(capacity!, 8)"
                            :key="n"
                            :class="{ occupied: n <= members! }"
                            ><Check v-if="n <= members!" :size="10"
                        /></i>
                    </div>
                    <small>{{ members }} / {{ capacity }} membres</small>
                </div>
            </div>
            <slot name="meta" />
            <div class="collection-card-action">
                <span class="collection-owner"
                    ><Users :size="15" aria-hidden="true" />{{
                        owner ? `Avec ${owner}` : "Le plaisir de partager"
                    }}</span
                >
                <slot name="action"
                    ><Link
                        v-if="href"
                        :href="href"
                        :aria-label="`${action} — ${name}`"
                        >{{ action
                        }}<span class="eq-round-arrow"
                            ><ArrowUpRight
                                :size="18"
                                aria-hidden="true" /></span></Link
                ></slot>
            </div>
            <slot />
        </div>
    </article>
</template>
<style scoped>
.workspace-card-identity {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 22px 0;
    color: var(--color-eq-muted);
    font-size: 9px;
    letter-spacing: 0.07em;
}
.workspace-card-identity > span:last-child {
    text-align: right;
}
.collection-card {
    min-width: 0;
    background: #fff;
    border: 1px solid #dededf;
    border-radius: 24px;
    box-shadow:
        0 4px 4px #15172102,
        0 15px 34px -30px #15172170;
    transition:
        border-color 0.2s,
        box-shadow 0.2s;
}
.collection-card:hover,
.collection-card:focus-within {
    border-color: #83858c;
    box-shadow: 0 20px 40px -26px #15172155;
}
.collection-card-content {
    padding: 23px 22px 16px;
}
.collection-card-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.card-eyebrow {
    font-size: 9px;
    letter-spacing: 0.075em;
    color: #73767d;
    margin-bottom: 7px;
}
h3 {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -0.045em;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.collection-card-status {
    flex: none;
    font-size: 10px;
    padding: 7px 9px;
    border-radius: 30px;
    background: #e7f3ed;
    color: #187a57;
}
.collection-card-status::before {
    content: "•";
    margin-right: 4px;
}
.collection-card-status.is-full {
    color: #686b70;
    background: #f0f0ef;
}
.collection-card-value {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: end;
    gap: 10px;
    margin: 28px 0 23px;
}
.collection-card-value > div:first-child {
    min-width: 0;
    max-width: 100%;
    overflow-wrap: anywhere;
}
.collection-card-value span,
small {
    color: #686b70;
    font-size: 10px;
}
.collection-card-value p {
    font-size: 30px;
    line-height: 1.2;
    letter-spacing: -0.05em;
    font-weight: 550;
    margin-top: 6px;
}
.collection-card-value .collection-no-price {
    font-size: 26px;
}
.collection-members {
    text-align: right;
    flex: none;
    margin-left: auto;
}
.collection-members > div {
    display: flex;
    gap: 3px;
    justify-content: end;
    margin-bottom: 9px;
}
.collection-members i {
    width: 16px;
    height: 16px;
    border: 1px dashed #cdd1c7;
    border-radius: 50%;
    display: grid;
    place-items: center;
}
.collection-members .occupied {
    background: #e8eedf;
    border: 0;
    color: #526547;
}
.collection-card-action {
    min-height: 63px;
    border-top: 1px solid #e8e8e7;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding-top: 14px;
}
.collection-owner {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 10px;
    color: #686b70;
    overflow-wrap: anywhere;
}
.collection-owner svg {
    flex: none;
}
.collection-card-action :deep(a),
.collection-card-action :deep(button) {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 11px;
    font-weight: 500;
    min-height: 44px;
    flex: none;
}
@media (max-width: 360px) {
    .collection-card-content {
        padding: 20px 16px 14px;
    }
    .collection-card-value {
        gap: 6px;
    }
    .collection-members i {
        width: 12px;
        height: 12px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .collection-card {
        transition: none;
    }
}
</style>
