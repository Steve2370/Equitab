<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Toast from '@/Components/Toast.vue';
import { useToast } from '@/composables/useToast';

// Monté une seule fois, au niveau racine de l'app (voir app.ts), pour être
// présent sur TOUTES les pages — y compris celles sans AdminLayout ni
// DashboardLayout (Welcome, Login, pages légales...). Avant ce fichier,
// rien ne reliait jamais les messages flash partagés par Laravel
// (HandleInertiaRequests::share() -> 'flash') au composant Toast : celui-ci
// existait, était bien monté dans les layouts, mais personne ne l'appelait
// pour les messages venant du backend (connexion, déconnexion, actions
// admin...) — d'où l'absence totale de toasts malgré un système en place.
const toastComponent = ref();
const { setRef, success, error } = useToast();
const page = usePage<{
    flash?: {
        success?: string | null;
        error?: string | null;
    };
}>();

onMounted(() => {
    if (toastComponent.value) setRef(toastComponent.value);
});

watch(
    () => [page.props.flash?.success, page.props.flash?.error],
    ([newSuccess, newError]) => {
        if (newSuccess) success(newSuccess);
        if (newError) error(newError);
    },
);
</script>

<template>
    <Toast ref="toastComponent" />
</template>
