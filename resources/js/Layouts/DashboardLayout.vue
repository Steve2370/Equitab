<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Toast from '@/Components/Toast.vue';
import { useToast } from '@/composables/useToast';
import {
    LayoutDashboard, CreditCard, MessageSquare,
    Wallet, ShieldCheck, Settings, Menu, X, ShieldAlert,
    LogOut
} from 'lucide-vue-next';

const sidebarOpen = ref(false);

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

const toastComponent = ref();
const { setRef } = useToast();

const isAdmin = computed(() => page.props.isAdmin);

const user = computed(() => page.props.auth?.user);

const navItems = [
    { label: 'Tableau de bord', href: '/dashboard', icon: LayoutDashboard },
    { label: 'Abonnements', href: '/dashboard/subscriptions', icon: CreditCard },
    { label: 'Chat', href: '/dashboard/chat', icon: MessageSquare },
    { label: 'Paiements', href: '/dashboard/payments', icon: Wallet },
    { label: 'Identité', href: '/dashboard/profile', icon: ShieldCheck },
    { label: 'Préférences', href: '/dashboard/preferences', icon: Settings },
];

onMounted(() => {
    if (toastComponent.value) setRef(toastComponent.value);
});

const currentPath = computed(() => page.url);

function isActive(href: string): boolean {
    if (href === '/dashboard') return currentPath.value === '/dashboard';
    return currentPath.value.startsWith(href);
}
</script>

<template>
    <div class="flex min-h-screen bg-gray-50">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-20 bg-black/40 lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-equitab-navy transition-transform duration-300 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center justify-between px-6">
                <Link href="/" class="text-xl font-semibold text-white">
                    Equitab
                </Link>
                <button @click="sidebarOpen = false" class="text-white/60 hover:text-white lg:hidden">
                    <X class="h-5 w-5" />
                </button>
            </div>

            <div class="border-t border-white/10 px-4 py-4">
                <p class="text-xs font-medium uppercase tracking-wider text-white/40">Menu</p>
                <nav class="mt-2 space-y-1">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="isActive(item.href)
                            ? 'bg-equitab-emerald text-white'
                            : 'text-white/70 hover:bg-white/10 hover:text-white'"
                        @click="sidebarOpen = false"
                    >
                        <component :is="item.icon" class="h-4 w-4 shrink-0" />
                        {{ item.label }}
                    </Link>

                    <div v-if="isAdmin" class="mx-4 my-2 border-t border-white/10" />

                    <Link
                        v-if="isAdmin"
                        href="/admin"
                        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="$page.url.startsWith('/admin')
                            ? 'bg-red-500/20 text-red-300'
                            : 'text-white/60 hover:bg-white/5 hover:text-white'"
                    >
                        <ShieldAlert class="h-5 w-5 shrink-0" />
                        <span>Panel admin</span>
                    </Link>
                </nav>
            </div>

            <div class="mt-auto border-t border-white/10 p-4">
                <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                    <div class="flex h-8 w-8 overflow-hidden rounded-full bg-equitab-emerald">
                        <img
                            v-if="user?.avatar"
                            :src="user.avatar"
                            class="h-full w-full object-cover"
                            alt="Avatar"
                        />
                        <span v-else class="flex h-full w-full items-center justify-center text-sm font-semibold text-white">
                            {{ user?.name?.charAt(0).toUpperCase() }}
                        </span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="truncate text-sm font-medium text-white">{{ user?.name }}</p>
                        <p class="truncate text-xs text-white/50">{{ user?.email }}</p>
                    </div>
                </div>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="mt-2 flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-white/60 hover:bg-white/10 hover:text-white"
                >
                    <LogOut class="h-4 w-4" />
                    Déconnexion
                </Link>
            </div>
        </aside>

        <div class="flex flex-1 flex-col lg:pl-64">
            <header class="sticky top-0 z-10 flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-6">
                <button
                    @click="sidebarOpen = true"
                    class="text-gray-500 hover:text-gray-900 lg:hidden"
                >
                    <Menu class="h-5 w-5" />
                </button>
                <div class="flex-1" />
                <span
                    v-if="user?.identity_status !== 'verified'"
                    class="rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700"
                >
                    Identité non vérifiée
                </span>
            </header>

            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
        <Toast ref="toastComponent" />
    </div>
</template>
