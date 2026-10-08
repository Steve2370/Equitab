<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirmer votre courriel" />

        <h1 class="mb-4 text-2xl font-semibold text-equitab-navy">Confirmez votre courriel.</h1>
        <p class="mb-4 text-sm leading-relaxed text-gray-600">
            Ouvrez le courriel EquitAb et cliquez sur « Confirmer mon courriel ».
            Vous retrouverez ensuite votre espace ou votre invitation.
        </p>
        <p class="mb-6 text-sm leading-relaxed text-gray-600">
            Vous ne le trouvez pas ? Vérifiez vos courriels indésirables, puis
            demandez un nouveau lien si nécessaire.
        </p>

        <div
            class="mb-4 text-sm font-medium text-green-600"
            v-if="status === 'verification-link-sent'"
            role="status"
        >
            Un nouveau lien de confirmation a été envoyé à votre adresse courriel.
        </div>
        <p v-if="status === 'verification-link-invalid'" role="alert" class="mb-4 text-sm text-gray-700">
            Ce lien a expiré ou n’est pas valide. Votre courriel n’a pas été confirmé.
            Utilisez le bouton ci-dessous pour demander un nouveau lien.
        </p>
        <p v-if="form.errors.verification" role="alert" class="mb-4 text-sm text-red-600">
            {{ form.errors.verification }}
        </p>

        <form @submit.prevent="submit">
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <button
                    type="submit"
                    class="rounded-xl bg-equitab-emerald px-5 py-3 text-sm font-semibold text-white hover:bg-equitab-emerald-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-equitab-emerald disabled:opacity-50"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                >
                    {{ form.processing ? 'Envoi en cours…' : 'Renvoyer le courriel' }}
                </button>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-equitab-emerald"
                    >Se déconnecter</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
