<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    LayoutDashboard, Users, CreditCard, FolderOpen,
    AlertTriangle, Mail, LogOut
} from 'lucide-vue-next';

const navItems = [
    { href: '/admin', label: 'Vue d\'ensemble', icon: LayoutDashboard },
    { href: '/admin/users', label: 'Utilisateurs', icon: Users },
    { href: '/admin/groups', label: 'Groupes', icon: FolderOpen },
    { href: '/admin/payments', label: 'Paiements', icon: CreditCard },
    { href: '/admin/disputes', label: 'Disputes', icon: AlertTriangle },
    { href: '/admin/messages', label: 'Messagerie', icon: Mail },
];
</script>

<template>
    <div class="flex min-h-screen bg-gray-50">
        <aside class="w-56 shrink-0 bg-equitab-navy">
            <div class="p-6">
                <Link href="/" class="block">
                    <img src="/Images/EquitabLogoblanc.svg" alt="Equitab" class="h-7 w-auto" />
                    <p class="mt-1 text-xs text-white/40">Panel Admin</p>
                </Link>
            </div>

            <nav class="px-3 space-y-1">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                    :class="$page.url.startsWith(item.href) && item.href !== '/admin' || $page.url === item.href
                        ? 'bg-white/10 text-white'
                        : 'text-white/60 hover:bg-white/5 hover:text-white'"
                >
                    <component :is="item.icon" class="h-4 w-4 shrink-0" />
                    {{ item.label }}
                </Link>

                <div class="px-3 mb-4 pt-2">
                    <Link
                        href="/dashboard"
                        class="flex items-center gap-2 text-xs text-white/40 hover:text-white"
                    >
                        <LayoutDashboard class="h-3.5 w-3.5" />
                        Mon dashboard
                    </Link>
                </div>
            </nav>

            <div class="absolute bottom-0 w-56 p-4">
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="flex items-center gap-2 text-xs text-white/40 hover:text-white"
                >
                    <LogOut class="h-4 w-4" />
                    Déconnexion
                </Link>
            </div>
        </aside>

        <main class="flex-1 p-8 overflow-auto">
            <slot />
        </main>
    </div>
</template>
