<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { ArrowUpRight, Menu, X } from "lucide-vue-next";
import EquitabWordmark from "./EquitabWordmark.vue";
const props = withDefaults(
    defineProps<{
        canLogin?: boolean;
        canRegister?: boolean;
        isAuthenticated?: boolean;
    }>(),
    { canLogin: true, canRegister: true, isAuthenticated: false },
);
const page = usePage<{ auth: { user: { name: string } | null } }>();
const signedIn = computed(
    () => props.isAuthenticated || !!page.props.auth?.user,
);
const inCatalog = computed(
    () =>
        page.url.startsWith("/services") ||
        page.url.startsWith("/groups/service/"),
);
const menu = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
watch(
    () => page.url,
    () => {
        menu.value = false;
    },
);
function close() {
    menu.value = false;
    trigger.value?.focus();
}
</script>
<template>
    <header class="collection-header" @keydown.esc="close">
        <div class="collection-nav">
            <Link href="/" aria-label="Equitab — accueil"
                ><EquitabWordmark
            /></Link>
            <nav aria-label="Navigation principale" class="desktop-navigation">
                <Link
                    href="/services"
                    :aria-current="inCatalog ? 'page' : undefined"
                    >Explorer</Link
                ><Link href="/#comment-ca-marche">Comment ça marche</Link
                ><Link href="/dashboard/groups/create"
                    >Partager un abonnement</Link
                >
            </nav>
            <div class="navigation-actions">
                <Link
                    v-if="!signedIn && canLogin"
                    href="/login"
                    class="connection-link"
                    >Connexion</Link
                ><Link
                    v-if="signedIn || canRegister"
                    :href="signedIn ? '/dashboard' : '/register'"
                    class="navigation-cta"
                    >{{ signedIn ? "Mon espace" : "C’est parti"
                    }}<ArrowUpRight :size="16" aria-hidden="true" /></Link
                ><button
                    ref="trigger"
                    type="button"
                    class="menu-trigger"
                    :aria-expanded="menu"
                    aria-controls="collection-mobile-menu"
                    :aria-label="menu ? 'Fermer le menu' : 'Ouvrir le menu'"
                    @click="menu = !menu"
                >
                    <X v-if="menu" :size="20" aria-hidden="true" /><Menu
                        v-else
                        :size="20"
                        aria-hidden="true"
                    />
                </button>
            </div>
        </div>
        <nav
            v-if="menu"
            id="collection-mobile-menu"
            class="mobile-navigation"
            aria-label="Navigation mobile"
        >
            <Link href="/services" @click="menu = false"
                >Explorer les services</Link
            ><Link href="/#comment-ca-marche" @click="menu = false"
                >Comment ça marche</Link
            ><Link href="/dashboard/groups/create" @click="menu = false"
                >Partager un abonnement</Link
            ><Link
                v-if="!signedIn && canLogin"
                href="/login"
                @click="menu = false"
                >Connexion</Link
            >
        </nav>
    </header>
</template>
<style scoped>
.collection-header {
    background: #f5f4f0ed;
    backdrop-filter: blur(16px);
    color: #252732;
}
.collection-nav {
    width: min(1180px, calc(100% - 80px));
    margin: auto;
    min-height: 96px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-bottom: 1px solid #dededb;
}
.desktop-navigation {
    display: flex;
    gap: 26px;
    font-size: 12px;
}
.desktop-navigation a {
    display: flex;
    align-items: center;
    min-height: 44px;
    position: relative;
}
.desktop-navigation a[aria-current]::after {
    content: "";
    position: absolute;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: currentColor;
    bottom: 1px;
    left: 50%;
}
.navigation-actions {
    display: flex;
    align-items: center;
    gap: 23px;
}
.connection-link {
    display: flex;
    align-items: center;
    min-height: 44px;
    font-size: 12px;
}
.navigation-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    min-height: 44px;
    padding: 10px 18px;
    border-radius: 25px;
    background: #282c30;
    color: #fff;
    font-size: 11px;
}
.navigation-cta:hover {
    background: #465438;
}
.menu-trigger {
    display: none;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid #d7d9d1;
    border-radius: 50%;
    cursor: pointer;
}
.mobile-navigation {
    padding: 12px 24px 20px;
    border-bottom: 1px solid #d7d9d1;
}
.mobile-navigation a {
    display: flex;
    align-items: center;
    min-height: 48px;
    font-size: 13px;
}
.collection-header :where(a, button):focus-visible {
    outline: 3px solid #6b58c8;
    outline-offset: 4px;
}
@media (max-width: 1000px) {
    .collection-nav {
        width: calc(100% - 48px);
    }
    .desktop-navigation {
        display: none;
    }
    .menu-trigger {
        display: grid;
    }
}
@media (max-width: 600px) {
    .collection-nav {
        width: calc(100% - 32px);
        min-height: 78px;
        gap: 10px;
    }
    .connection-link {
        display: none;
    }
    .navigation-actions {
        gap: 7px;
    }
    .navigation-cta {
        font-size: 10px;
        padding: 10px 13px;
        gap: 5px;
    }
    .navigation-cta svg {
        display: none;
    }
    .collection-nav :deep(.collection-wordmark) {
        font-size: 27px;
    }
    .collection-nav :deep(.wordmark-symbol) {
        width: 21px;
        height: 21px;
        margin-right: 7px;
    }
}
</style>
