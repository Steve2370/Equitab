<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { Eye, EyeOff, Mail, Lock, User } from "lucide-vue-next";
import { ref } from "vue";
import AuthExperienceLayout from "@/Layouts/AuthExperienceLayout.vue";
declare function route(name: string, params?: any): string;

const showPassword = ref(false);
const showConfirm = ref(false);

const form = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
});

function submit(): void {
    form.post(route("register"), {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
}
</script>

<template>
    <Head title="Inscription" />

    <AuthExperienceLayout>
        <p class="eq-eyebrow text-eq-muted">Bienvenue dans le partage</p>
        <h1 class="eq-title mt-3 text-4xl">On commence ensemble ?</h1>
        <p class="mt-1 text-sm text-gray-500">
            Déjà un compte ?
            <Link
                href="/login"
                class="font-medium text-equitab-emerald hover:underline"
            >
                Se connecter
            </Link>
        </p>

        <a
            :href="route('auth.social.redirect', { provider: 'google' })"
            class="mt-6 flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24">
                <path
                    fill="#4285F4"
                    d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                />
                <path
                    fill="#34A853"
                    d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                />
                <path
                    fill="#FBBC05"
                    d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                />
                <path
                    fill="#EA4335"
                    d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                />
            </svg>
            S'inscrire avec Google
        </a>

        <div class="mt-6 flex items-center gap-3">
            <div class="h-px flex-1 bg-gray-200" />
            <span class="text-xs text-gray-400">ou continuer avec</span>
            <div class="h-px flex-1 bg-gray-200" />
        </div>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <div>
                <label
                    for="register-name"
                    class="text-sm font-medium text-gray-700"
                    >Nom complet</label
                >
                <div class="relative mt-1">
                    <User
                        class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                    />
                    <input
                        id="register-name"
                        v-model="form.name"
                        :aria-invalid="!!form.errors.name"
                        :aria-describedby="
                            form.errors.name ? 'register-name-error' : undefined
                        "
                        type="text"
                        autocomplete="name"
                        required
                        placeholder="Jean Tremblay"
                        class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-4 text-sm focus:border-equitab-emerald focus:outline-none"
                        :class="{ 'border-red-400': form.errors.name }"
                    />
                </div>
                <p
                    id="register-name-error"
                    v-if="form.errors.name"
                    role="alert"
                    class="mt-1 text-xs text-red-500"
                >
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label
                    for="register-email"
                    class="text-sm font-medium text-gray-700"
                    >Adresse courriel</label
                >
                <div class="relative mt-1">
                    <Mail
                        class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                    />
                    <input
                        id="register-email"
                        v-model="form.email"
                        :aria-invalid="!!form.errors.email"
                        :aria-describedby="
                            form.errors.email
                                ? 'register-email-error'
                                : undefined
                        "
                        type="email"
                        autocomplete="email"
                        required
                        placeholder="vous@exemple.com"
                        class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-4 text-sm focus:border-equitab-emerald focus:outline-none"
                        :class="{ 'border-red-400': form.errors.email }"
                    />
                </div>
                <p
                    id="register-email-error"
                    v-if="form.errors.email"
                    role="alert"
                    class="mt-1 text-xs text-red-500"
                >
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label
                    for="register-password"
                    class="text-sm font-medium text-gray-700"
                    >Mot de passe</label
                >
                <div class="relative mt-1">
                    <Lock
                        class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                    />
                    <input
                        id="register-password"
                        v-model="form.password"
                        :aria-invalid="!!form.errors.password"
                        :aria-describedby="
                            form.errors.password
                                ? 'register-password-error'
                                : undefined
                        "
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        placeholder="Min. 8 caractères"
                        class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-10 text-sm focus:border-equitab-emerald focus:outline-none"
                        :class="{ 'border-red-400': form.errors.password }"
                    />
                    <button
                        type="button"
                        :aria-label="
                            showPassword
                                ? 'Masquer le mot de passe'
                                : 'Afficher le mot de passe'
                        "
                        :aria-pressed="showPassword"
                        @click="showPassword = !showPassword"
                        class="absolute right-0 top-1/2 flex h-11 w-11 items-center justify-center -translate-y-1/2 text-gray-400"
                    >
                        <EyeOff v-if="showPassword" class="h-4 w-4" />
                        <Eye v-else class="h-4 w-4" />
                    </button>
                </div>
                <p
                    id="register-password-error"
                    v-if="form.errors.password"
                    role="alert"
                    class="mt-1 text-xs text-red-500"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <div>
                <label
                    for="register-password_confirmation"
                    class="text-sm font-medium text-gray-700"
                    >Confirmer le mot de passe</label
                >
                <div class="relative mt-1">
                    <Lock
                        class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                    />
                    <input
                        id="register-password_confirmation"
                        v-model="form.password_confirmation"
                        :aria-invalid="!!form.errors.password_confirmation"
                        :aria-describedby="
                            form.errors.password_confirmation
                                ? 'register-password_confirmation-error'
                                : undefined
                        "
                        :type="showConfirm ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        placeholder="••••••••"
                        class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-10 text-sm focus:border-equitab-emerald focus:outline-none"
                    />
                    <button
                        type="button"
                        :aria-label="
                            showConfirm
                                ? 'Masquer la confirmation'
                                : 'Afficher la confirmation'
                        "
                        :aria-pressed="showConfirm"
                        @click="showConfirm = !showConfirm"
                        class="absolute right-0 top-1/2 flex h-11 w-11 items-center justify-center -translate-y-1/2 text-gray-400"
                    >
                        <EyeOff v-if="showConfirm" class="h-4 w-4" />
                        <Eye v-else class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <p
                id="register-password_confirmation-error"
                v-if="form.errors.password_confirmation"
                role="alert"
                class="text-xs text-red-600"
            >
                {{ form.errors.password_confirmation }}
            </p>
            <p class="text-xs text-gray-400">
                En créant un compte, vous acceptez nos
                <a
                    href="/conditions"
                    class="text-equitab-emerald hover:underline"
                    >conditions d'utilisation</a
                >
                et notre
                <a
                    href="/confidentialite"
                    class="text-equitab-emerald hover:underline"
                    >politique de confidentialité</a
                >.
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-equitab-emerald py-3 text-sm font-medium text-white hover:bg-equitab-emerald-dark disabled:opacity-60"
            >
                {{ form.processing ? "Création..." : "Créer mon compte" }}
            </button>
        </form>
    </AuthExperienceLayout>
</template>
