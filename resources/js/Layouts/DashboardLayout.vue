<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import EquitabWordmark from "@/Components/Experience/EquitabWordmark.vue";
import {
    LayoutDashboard,
    CreditCard,
    MessageSquare,
    Wallet,
    ShieldCheck,
    Settings,
    Menu,
    X,
    ShieldAlert,
    LogOut,
    Plus,
    ArrowUpRight,
} from "lucide-vue-next";
const sidebarOpen = ref(false);
const menuButton = ref<HTMLButtonElement>();
const page = usePage<{
    auth: {
        user: {
            name: string;
            email: string;
            identity_status: string;
            avatar: string | null;
        } | null;
    };
    isAdmin: boolean;
}>();
const user = computed(() => page.props.auth?.user);
const navItems = [
    { label: "Vue d’ensemble", href: "/dashboard", icon: LayoutDashboard },
    {
        label: "Mes abonnements",
        href: "/dashboard/subscriptions",
        icon: CreditCard,
    },
    { label: "Messages", href: "/dashboard/chat", icon: MessageSquare },
    { label: "Paiements", href: "/dashboard/payments", icon: Wallet },
    { label: "Mon profil", href: "/dashboard/profile", icon: ShieldCheck },
    { label: "Préférences", href: "/dashboard/preferences", icon: Settings },
];
const currentPath = computed(() => page.url.split("?")[0]);
function isActive(href: string) {
    return href === "/dashboard"
        ? currentPath.value === href
        : currentPath.value.startsWith(href);
}
const currentLabel = computed(() =>
    currentPath.value === "/dashboard/groups/create"
        ? "Créer un groupe"
        : (navItems.find((item) => isActive(item.href))?.label ?? "Mon espace"),
);
watch(
    () => page.url,
    () => {
        sidebarOpen.value = false;
    },
);
function closeMenu() {
    if (!sidebarOpen.value) return;
    sidebarOpen.value = false;
    menuButton.value?.focus();
}
</script>

<template>
    <div
        class="eq-experience eq-workspace eq-member-space min-h-screen"
        @keydown.esc="closeMenu"
    >
        <a href="#dashboard-content" class="eq-skip eq-button"
            >Aller au contenu</a
        >
        <header
            class="sticky top-0 z-30 flex min-h-20 items-center justify-between gap-3 border-b border-eq-line bg-eq-paper/95 px-4 backdrop-blur-md sm:px-8 lg:ml-64"
        >
            <div class="flex min-w-0 items-center gap-3">
                <button
                    ref="menuButton"
                    type="button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-eq-line lg:hidden"
                    :aria-expanded="sidebarOpen"
                    aria-controls="dashboard-navigation"
                    :aria-label="
                        sidebarOpen ? 'Fermer le menu' : 'Ouvrir le menu'
                    "
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <X v-if="sidebarOpen" :size="20" aria-hidden="true" /><Menu
                        v-else
                        :size="20"
                        aria-hidden="true"
                    />
                </button>
                <p class="truncate text-sm font-medium">
                    <span class="hidden text-eq-muted sm:inline"
                        >Mon espace
                        <span class="mx-3 text-eq-line">/</span></span
                    >{{ currentLabel }}
                </p>
            </div>
            <Link href="/services" class="eq-link shrink-0 !text-xs"
                >Explorer <ArrowUpRight :size="17" aria-hidden="true"
            /></Link>
        </header>
        <aside
            id="dashboard-navigation"
            class="workspace-sidebar border-b border-eq-line lg:fixed lg:inset-y-0 lg:left-0 lg:z-40 lg:flex lg:w-64 lg:flex-col lg:overflow-y-auto lg:border-r lg:border-b-0 [&>*]:shrink-0"
            :class="sidebarOpen ? 'block' : 'hidden'"
        >
            <Link
                href="/"
                aria-label="Equitab — accueil"
                class="hidden h-20 items-center border-b border-eq-line px-7 lg:flex"
                ><EquitabWordmark
            /></Link>
            <nav
                aria-label="Navigation de mon espace"
                class="space-y-1 p-4 lg:pt-8"
            >
                <p class="eq-eyebrow mb-4 px-3 text-eq-muted">Mon quotidien</p>
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    class="flex min-h-12 items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition-colors"
                    :class="
                        isActive(item.href)
                            ? 'bg-eq-ink text-white'
                            : 'text-eq-muted hover:bg-eq-paper'
                    "
                    @click="sidebarOpen = false"
                    ><component
                        :is="item.icon"
                        :size="18"
                        aria-hidden="true"
                    />{{ item.label }}</Link
                >
                <Link
                    v-if="page.props.isAdmin"
                    href="/admin"
                    class="flex min-h-12 items-center gap-3 rounded-lg px-3 py-3 text-sm text-eq-muted"
                    ><ShieldAlert :size="18" aria-hidden="true" />
                    Administration</Link
                >
            </nav>
            <div class="workspace-share mx-4 mb-5 rounded-2xl p-5">
                <p class="text-sm font-semibold">On partage davantage ?</p>
                <p class="mt-2 text-xs leading-5 text-eq-muted">
                    Proposez un abonnement et invitez votre groupe.
                </p>
                <Link
                    href="/dashboard/groups/create"
                    class="eq-button mt-4 !min-h-10 w-full !px-3 !py-2 !text-xs"
                    ><Plus :size="15" aria-hidden="true" /> Créer un
                    groupe</Link
                >
            </div>
            <div class="mt-auto border-t border-eq-line p-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-eq-line bg-white text-sm font-semibold"
                    >
                        <img
                            v-if="user?.avatar"
                            :src="user.avatar"
                            alt=""
                            class="h-full w-full object-cover"
                        /><span v-else>{{
                            user?.name?.charAt(0).toUpperCase()
                        }}</span>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ user?.name }}
                        </p>
                        <p class="truncate text-xs text-eq-muted">
                            {{ user?.email }}
                        </p>
                    </div>
                </div>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="mt-3 flex min-h-11 w-full items-center gap-3 rounded-lg px-2 text-xs text-eq-muted hover:bg-eq-paper"
                    ><LogOut :size="16" aria-hidden="true" /> Déconnexion</Link
                >
            </div>
        </aside>
        <main
            id="dashboard-content"
            class="min-w-0 p-4 sm:p-8 lg:ml-64 lg:p-10"
        >
            <div class="mx-auto max-w-6xl">
                <Link
                    v-if="user && user.identity_status !== 'verified'"
                    href="/dashboard/profile"
                    class="mb-7 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900"
                    ><ShieldCheck
                        :size="18"
                        class="shrink-0"
                        aria-hidden="true" /><span
                        >Votre identité reste à vérifier.
                        <span class="font-semibold underline underline-offset-2"
                            >Compléter mon profil</span
                        ></span
                    ><ArrowUpRight
                        :size="16"
                        class="ml-auto shrink-0"
                        aria-hidden="true"
                /></Link>
                <slot />
            </div>
        </main>
    </div>
</template>
<style scoped>
.workspace-sidebar {
    background: #fff;
}
.workspace-sidebar :deep(.collection-wordmark) {
    font-size: 32px;
}
.workspace-sidebar :deep(.wordmark-symbol) {
    width: 25px;
    height: 25px;
}
.workspace-share {
    background: #fff;
    border: 1px solid var(--color-eq-line);
}
.workspace-sidebar nav a {
    border-radius: 15px;
    font-size: 12px;
}
.workspace-sidebar nav a[aria-current] {
    box-shadow: 0 5px 12px -8px #303b3777;
}
@media (max-width: 1023px) {
    .workspace-sidebar nav {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 5px;
    }
    .workspace-sidebar nav > p {
        grid-column: 1/-1;
        margin-bottom: 3px;
    }
    .workspace-share {
        display: none;
    }
}
</style>
