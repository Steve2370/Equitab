<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';

interface Props {
    canLogin?: boolean;
    canRegister?: boolean;
    isAuthenticated?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    canLogin: true,
    canRegister: true,
    isAuthenticated: false,
});

const searchQuery = ref('');

function handleSearch(): void {
    if (!searchQuery.value.trim()) return;
    router.get('/services', { search: searchQuery.value }, { preserveState: false });
}
</script>

<template>
    <nav class="sticky top-0 z-10 flex items-center justify-between bg-equitab-navy px-6 py-4 lg:px-12">
        <Link href="/" class="text-lg font-semibold text-white">
            Equitab
        </Link>

        <form @submit.prevent="handleSearch" class="relative hidden md:flex items-center">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-white/40" />
            <input
                v-model="searchQuery"
                type="text"
                placeholder="Rechercher un service..."
                class="rounded-xl border border-white/10 bg-white/10 py-2 pl-9 pr-4 text-sm text-white placeholder-white/40 focus:border-equitab-emerald focus:outline-none w-56"
            />
        </form>

        <div class="flex items-center gap-4">
            <Link href="/services" class="text-sm font-medium text-white/70 hover:text-white">
                Services
            </Link>
            <Link
                v-if="isAuthenticated"
                href="/dashboard"
                class="text-sm font-medium text-white/90 hover:text-white"
            >
                Tableau de bord
            </Link>
            <template v-else>
                <Link
                    v-if="canLogin"
                    href="/login"
                    class="text-sm font-medium text-white/90 hover:text-white"
                >
                    Connexion
                </Link>
                <Link
                    v-if="canRegister"
                    href="/register"
                    class="rounded-md bg-equitab-emerald px-4 py-2 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
                >
                    S'inscrire
                </Link>
            </template>
        </div>
    </nav>
</template>
