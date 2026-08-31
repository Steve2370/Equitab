<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock, ArrowLeft } from 'lucide-vue-next';
import { ref } from 'vue';
declare function route(name: string, params?: any): string;

interface Props {
    canResetPassword: boolean;
    status?: string;
}

defineProps<Props>();

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Connexion" />

    <div class="flex min-h-screen">
        <div class="hidden lg:flex lg:w-1/2 flex-col justify-between bg-equitab-navy p-12">
           <Link href="/" class="flex items-center">
                <img src="/Images/EquitabLogoblanc.png" alt="Equitab" class="h-12 w-auto" />
            </Link>

            <div>
                <h1 class="text-4xl font-semibold leading-tight text-white">
                    Partagez vos abonnements. Payez juste votre part.
                </h1>
                <p class="mt-4 text-lg text-white/60">
                    Des paiements sécurisés via Stripe, des identifiants protégés, et une communauté de confiance.
                </p>

                <div class="mt-10 flex items-center">
                    <div
                        v-for="n in 3"
                        :key="n"
                        class="-mr-3 flex h-12 w-12 items-center justify-center rounded-full border-2 border-equitab-emerald bg-equitab-navy-light last:mr-0"
                    >
                        <svg class="h-5 w-5 text-equitab-emerald" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                </div>
                <p class="mt-2 text-xs text-white/40">Un groupe, un abonnement, plusieurs personnes</p>
            </div>

            <p class="text-xs text-white/30">© 2026 Equitab. Tous droits réservés.</p>
        </div>

        <div class="flex w-full flex-col items-center justify-center px-6 lg:w-1/2 lg:px-16">
            <div class="w-full max-w-sm">
                <Link
                    href="/"
                    class="mb-6 flex items-center gap-2 text-sm text-gray-400 hover:text-equitab-navy"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Retour à l'accueil
                </Link>

                <Link href="/" class="mb-8 block text-xl font-semibold text-equitab-navy lg:hidden">
                    Equitab
                </Link>

                <h2 class="text-2xl font-semibold text-equitab-navy">Bonjour,</h2>
                <h2 class="text-2xl font-semibold text-equitab-navy">Content de vous revoir</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Pas encore de compte ?
                    <Link href="/register" class="font-medium text-equitab-emerald hover:underline">
                        S'inscrire
                    </Link>
                </p>

                <div v-if="status" class="mt-4 rounded-lg bg-equitab-emerald/10 p-3 text-sm text-equitab-emerald">
                    {{ status }}
                </div>

                <a
                    :href="route('auth.social.redirect', { provider: 'google' })"
                    class="mt-6 flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                    </svg>
                    Continuer avec Google
                </a>

                <div class="mt-6 flex items-center gap-3">
                    <div class="h-px flex-1 bg-gray-200" />
                    <span class="text-xs text-gray-400">ou continuer avec</span>
                    <div class="h-px flex-1 bg-gray-200" />
                </div>

                <form @submit.prevent="submit" class="mt-6 space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Email</label>
                        <div class="relative mt-1">
                            <Mail class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                required
                                placeholder="vous@exemple.com"
                                class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-4 text-sm focus:border-equitab-emerald focus:outline-none"
                                :class="{ 'border-red-400': form.errors.email }"
                            />
                        </div>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-medium text-gray-700">Mot de passe</label>
                            <Link
                                v-if="canResetPassword"
                                href="/forgot-password"
                                class="text-xs text-equitab-emerald hover:underline"
                            >
                                Mot de passe oublié ?
                            </Link>
                        </div>
                        <div class="relative mt-1">
                            <Lock class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••"
                                class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-10 text-sm focus:border-equitab-emerald focus:outline-none"
                                :class="{ 'border-red-400': form.errors.password }"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                <EyeOff v-if="showPassword" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-500">{{ form.errors.password }}</p>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="rounded border-gray-300 accent-equitab-emerald"
                        />
                        <span class="text-sm text-gray-600">Se souvenir de moi</span>
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-equitab-emerald py-3 text-sm font-medium text-white hover:bg-equitab-emerald-dark disabled:opacity-60"
                    >
                        {{ form.processing ? 'Connexion...' : 'Se connecter' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
