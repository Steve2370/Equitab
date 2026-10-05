<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
</script>
<template>
    <Head title="Suite de la refonte" />
    <main
        class="eq-experience flex min-h-screen flex-col items-center justify-center gap-5 px-6 text-center"
    >
        <p class="eq-eyebrow">Aperçu local · lecture seule</p>
        <h1 class="eq-title text-4xl">La suite se construit ensemble.</h1>
        <p class="max-w-lg text-sm leading-7 text-eq-muted">
            Cet écran n’est pas inclus dans ce premier aperçu. Les actions de
            compte et de paiement sont désactivées. Les fonctions de
            l’application existante n’ont pas été supprimées.
        </p>
        <div class="flex flex-wrap justify-center gap-3">
            <Link href="/" class="eq-button">Retour à l’accueil</Link
            ><Link href="/dashboard" class="eq-button eq-button-secondary"
                >Voir mon espace</Link
            >
        </div>
    </main>
</template>
