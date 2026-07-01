<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

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
</script>

<template>
    <nav class="sticky top-0 z-10 flex items-center justify-between bg-equitab-navy px-6 py-4 lg:px-12">
        <Link href="/" class="text-lg font-semibold text-white">
            Equitab
        </Link>

        <div class="flex items-center gap-4">
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
            <Link href="/services" class="text-sm font-medium text-gray-600 hover:text-equitab-navy">
                Services
            </Link>
        </div>
    </nav>
</template>
