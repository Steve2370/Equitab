<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import EquitabWordmark from "@/Components/Experience/EquitabWordmark.vue";
import {
    LayoutDashboard,
    Users,
    CreditCard,
    FolderOpen,
    AlertTriangle,
    Mail,
    LogOut,
    Menu,
    X,
    ArrowUpRight,
    ShieldCheck,
} from "lucide-vue-next";
const page = usePage<{ flash?: { success?: string; error?: string } }>();
const opened = ref(false);
const menuButton = ref<HTMLButtonElement>();
const path = computed(() => page.url.split("?")[0]);
const navItems = [
    { href: "/admin", label: "Vue d’ensemble", icon: LayoutDashboard },
    { href: "/admin/users", label: "Utilisateurs", icon: Users },
    { href: "/admin/groups", label: "Groupes", icon: FolderOpen },
    { href: "/admin/payments", label: "Paiements", icon: CreditCard },
    { href: "/admin/disputes", label: "Litiges", icon: AlertTriangle },
    { href: "/admin/messages", label: "Messagerie", icon: Mail },
];
const currentLabel = computed(
    () =>
        navItems.find((item) => path.value === item.href)?.label ??
        "Administration",
);
watch(
    () => page.url,
    () => {
        opened.value = false;
    },
);
function closeMenu() {
    if (!opened.value) return;
    opened.value = false;
    menuButton.value?.focus();
}
</script>
<template>
    <div
        class="eq-experience eq-workspace admin-shell"
        @keydown.esc="closeMenu"
    >
        <a href="#admin-content" class="eq-skip eq-button">Aller au contenu</a>
        <header class="admin-topbar">
            <button
                ref="menuButton"
                type="button"
                class="admin-menu-button"
                :aria-expanded="opened"
                aria-controls="admin-navigation"
                :aria-label="
                    opened ? 'Fermer le menu Admin' : 'Ouvrir le menu Admin'
                "
                @click="opened = !opened"
            >
                <X v-if="opened" :size="20" /><Menu v-else :size="20" />
            </button>
            <p>
                <span>Administration <i>/</i></span
                >{{ currentLabel }}
            </p>
            <Link href="/dashboard" class="eq-link"
                ><span>Mon espace</span><ArrowUpRight :size="17"
            /></Link>
        </header>
        <aside
            id="admin-navigation"
            class="admin-sidebar"
            :class="{ 'is-open': opened }"
        >
            <Link href="/" aria-label="EquitAb — accueil" class="admin-brand"
                ><EquitabWordmark
            /></Link>
            <div class="admin-space-label">
                <ShieldCheck :size="14" /> ESPACE ADMINISTRATEUR
            </div>
            <nav aria-label="Navigation de l’administration">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="path === item.href ? 'page' : undefined"
                    @click="opened = false"
                >
                    <component
                        :is="item.icon"
                        :size="18"
                        aria-hidden="true"
                    />{{ item.label }}
                </Link>
            </nav>
            <div class="admin-sidebar-bottom">
                <p>Le partage, en confiance.</p>
                <span
                    >Les membres et leurs échanges,<br />au centre de votre
                    suivi.</span
                >
                <Link href="/logout" method="post" as="button"
                    ><LogOut :size="16" />Déconnexion</Link
                >
            </div>
        </aside>
        <main id="admin-content" class="admin-content">
            <div class="admin-content-inner">
                <p
                    v-if="page.props.flash?.success"
                    role="status"
                    class="admin-flash"
                >
                    {{ page.props.flash.success }}
                </p>
                <p
                    v-if="page.props.flash?.error"
                    role="alert"
                    class="admin-flash admin-flash-error"
                >
                    {{ page.props.flash.error }}
                </p>
                <slot />
            </div>
        </main>
    </div>
</template>
<style scoped>
.admin-shell {
    min-height: 100dvh;
}
.admin-topbar {
    min-height: 80px;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 36px;
    border-bottom: 1px solid var(--color-eq-line);
    margin-left: 248px;
}
.admin-topbar > p {
    flex: 1;
    min-width: 0;
    font-size: 12px;
    font-weight: 600;
}
.admin-topbar > p > span {
    color: var(--color-eq-muted);
    font-weight: 400;
}
.admin-topbar i {
    font-style: normal;
    margin-inline: 14px;
    color: #a5b5aa;
}
.admin-menu-button {
    display: none;
    width: 44px;
    height: 44px;
    place-items: center;
    flex: none;
    border: 1px solid var(--color-eq-line);
    border-radius: 13px;
}
.admin-sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: 248px;
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--color-eq-line);
    background: #edf4ef;
    overflow-y: auto;
    z-index: 30;
}
.admin-brand {
    min-height: 80px;
    padding: 24px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid var(--color-eq-line);
}
.admin-space-label {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    letter-spacing: 0.08em;
    color: var(--color-eq-green);
    padding: 32px 24px 18px;
}
.admin-sidebar nav {
    display: grid;
    gap: 6px;
    padding: 0 14px;
}
.admin-sidebar nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 49px;
    padding: 13px 16px;
    border-radius: 14px;
    color: #596e61;
    font-size: 12px;
    font-weight: 500;
}
.admin-sidebar nav a:hover {
    background: #e0ece4;
}
.admin-sidebar nav a[aria-current] {
    background: #187a57;
    color: white;
    box-shadow: 0 6px 14px -10px #187a57;
}
.admin-sidebar-bottom {
    margin-top: auto;
    padding: 28px 24px;
}
.admin-sidebar-bottom p {
    font-size: 12px;
    font-weight: 600;
}
.admin-sidebar-bottom > span {
    display: block;
    font-size: 10px;
    line-height: 1.9;
    color: #65756d;
    margin-top: 8px;
}
.admin-sidebar-bottom button {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 24px;
    font-size: 12px;
    border-top: 1px solid var(--color-eq-line);
    width: 100%;
    padding-top: 16px;
}
.admin-content {
    margin-left: 248px;
    padding: 42px 36px 60px;
    min-width: 0;
}
.admin-content-inner {
    max-width: 1280px;
    margin: auto;
    min-width: 0;
}
.admin-flash {
    padding: 16px;
    border: 1px solid #a1d9bb;
    background: #e7f3ed;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 13px;
}
.admin-flash-error {
    color: #a12e2e;
    background: #fff0ef;
    border-color: #e7b8b3;
}
@media (max-width: 1023px) {
    .admin-topbar {
        margin: 0;
        padding: 16px;
        gap: 12px;
    }
    .admin-topbar > p > span {
        display: none;
    }
    .admin-topbar .eq-link {
        font-size: 11px;
    }
    .admin-menu-button {
        display: grid;
    }
    .admin-sidebar {
        position: static;
        display: none;
        width: auto;
        border-right: 0;
        border-bottom: 1px solid var(--color-eq-line);
    }
    .admin-sidebar.is-open {
        display: flex;
    }
    .admin-brand {
        padding: 20px 24px;
        min-height: 64px;
    }
    .admin-space-label {
        padding-top: 20px;
    }
    .admin-sidebar nav {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .admin-sidebar nav a {
        padding: 12px 10px;
        gap: 8px;
        font-size: 11px;
    }
    .admin-sidebar-bottom {
        padding: 10px 24px 20px;
    }
    .admin-sidebar-bottom > p,
    .admin-sidebar-bottom > span {
        display: none;
    }
    .admin-sidebar-bottom button {
        margin-top: 8px;
    }
    .admin-content {
        margin: 0;
        padding: 30px 16px 48px;
    }
}
</style>
