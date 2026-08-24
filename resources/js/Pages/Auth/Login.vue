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
                <img src="/Images/EquitabLogoblanc.svg" alt="Equitab" class="h-7 w-auto" />
            </Link>

            <div>
                <h1 class="text-4xl font-semibold leading-tight text-white">
                    Partagez vos abonnements en toute confiance
                </h1>
                <p class="mt-4 text-lg text-white/60">
                    Des paiements sécurisés via Stripe, des identifiants protégés, et une communauté de confiance.
                </p>

                <div class="mt-10 space-y-4">
                    <div
                        v-for="item in [
                            { title: 'Paiements sécurisés par Stripe', desc: 'Votre argent ne transite jamais par Equitab' },
                            { title: 'Identifiants chiffrés', desc: 'Accessibles uniquement aux membres actifs' },
                            { title: 'Paiements 100% sécurisés', desc: 'Vos transactions sont protégées par Stripe' },
                        ]"
                        :key="item.title"
                        class="flex items-start gap-3"
                    >
                        <div class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-equitab-emerald">
                            <svg class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div>
                            <p class="font-medium text-white">{{ item.title }}</p>
                            <p class="text-sm text-white/50">{{ item.desc }}</p>
                        </div>
                    </div>
                </div>
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

                <h2 class="text-2xl font-semibold text-equitab-navy">Connexion</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Pas encore de compte ?
                    <Link href="/register" class="font-medium text-equitab-emerald hover:underline">
                        S'inscrire
                    </Link>
                </p>

                <div v-if="status" class="mt-4 rounded-lg bg-equitab-emerald/10 p-3 text-sm text-equitab-emerald">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="mt-8 space-y-4">
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
