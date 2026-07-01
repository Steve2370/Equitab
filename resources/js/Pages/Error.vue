<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Home, ArrowLeft, AlertTriangle, ServerCrash, ShieldOff } from 'lucide-vue-next';

function goBack(): void {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '/';
    }
}

interface Props {
    status: number;
}

const props = defineProps<Props>();

const page = computed(() => {
    const pages: Record<number, {
        title: string;
        description: string;
        icon: any;
    }> = {
        403: {
            title: 'Accès refusé',
            description: 'Vous n\'avez pas les permissions nécessaires pour accéder à cette page.',
            icon: ShieldOff,
        },
        404: {
            title: 'Page introuvable',
            description: 'La page que vous cherchez n\'existe pas ou a été déplacée.',
            icon: AlertTriangle,
        },
        419: {
            title: 'Session expirée',
            description: 'Votre session a expiré. Veuillez rafraîchir la page et réessayer.',
            icon: AlertTriangle,
        },
        500: {
            title: 'Erreur serveur',
            description: 'Une erreur inattendue s\'est produite. Notre équipe en a été notifiée.',
            icon: ServerCrash,
        },
        503: {
            title: 'Service indisponible',
            description: 'Equitab est temporairement en maintenance. Revenez dans quelques minutes.',
            icon: ServerCrash,
        },
    };

    return pages[props.status] ?? {
        title: 'Une erreur est survenue',
        description: 'Quelque chose s\'est mal passé. Veuillez réessayer.',
        icon: AlertTriangle,
    };

});
</script>

<template>
    <Head :title="`${status} — ${page.title}`" />

    <div class="flex min-h-screen flex-col bg-gray-50">
        <div class="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">

            <div
                class="flex h-20 w-20 items-center justify-center rounded-2xl mb-6"
                :class="status >= 500 ? 'bg-red-50' : 'bg-equitab-navy/5'"
            >
                <component
                    :is="page.icon"
                    class="h-10 w-10"
                    :class="status >= 500 ? 'text-red-400' : 'text-equitab-navy'"
                />
            </div>

            <p
                class="text-7xl font-bold"
                :class="status >= 500 ? 'text-red-200' : 'text-equitab-navy/10'"
            >
                {{ status }}
            </p>

            <h1 class="mt-2 text-2xl font-semibold text-equitab-navy">
                {{ page.title }}
            </h1>

            <p class="mt-3 max-w-md text-gray-500">
                {{ page.description }}
            </p>

            <div class="mt-8 flex items-center gap-3">
                <button
                    @click="goBack"
                    class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Retour
                </button>
                <Link
                    href="/"
                    class="flex items-center gap-2 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light"
                >
                    <Home class="h-4 w-4" />
                    Accueil
                </Link>
            </div>

            <div class="mt-12">
                <Link href="/" class="text-lg font-semibold text-equitab-navy">
                    Equitab
                </Link>
            </div>
        </div>
    </div>
</template>
