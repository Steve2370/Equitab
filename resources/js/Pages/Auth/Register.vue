<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock, User, ArrowLeft } from 'lucide-vue-next';
import { ref } from 'vue';
declare function route(name: string, params?: any): string;

const showPassword = ref(false);
const showConfirm = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Inscription" />

    <div class="flex min-h-screen">
        <div class="hidden lg:flex lg:w-1/2 flex-col justify-between bg-equitab-navy p-12">
            <Link href="/" class="text-2xl font-semibold text-white">
                Equitab
            </Link>

            <div>
                <h1 class="text-4xl font-semibold leading-tight text-white">
                    Rejoignez des milliers d'utilisateurs qui partagent leurs abonnements
                </h1>
                <p class="mt-4 text-lg text-white/60">
                    Créez un compte gratuit et commencez à économiser dès aujourd'hui.
                </p>

                <div class="mt-10 grid grid-cols-2 gap-4">
                    <div
                        v-for="stat in [
                            { value: 'Secure', label: 'Paiements sécurisés' },
                            { value: 'CAD', label: 'Devise canadienne' },
                            { value: '100%', label: 'Sécurisé par Stripe' },
                            { value: '0$', label: 'Frais d\'inscription' },
                        ]"
                        :key="stat.value"
                        class="rounded-xl bg-white/5 p-4"
                    >
                        <p class="text-2xl font-semibold text-equitab-emerald">{{ stat.value }}</p>
                        <p class="text-sm text-white/60">{{ stat.label }}</p>
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

                <h2 class="text-2xl font-semibold text-equitab-navy">Créer un compte</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Déjà un compte ?
                    <Link href="/login" class="font-medium text-equitab-emerald hover:underline">
                        Se connecter
                    </Link>
                </p>

                <form @submit.prevent="submit" class="mt-8 space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Nom complet</label>
                        <div class="relative mt-1">
                            <User class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="form.name"
                                type="text"
                                autocomplete="name"
                                required
                                placeholder="Jean Tremblay"
                                class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-4 text-sm focus:border-equitab-emerald focus:outline-none"
                                :class="{ 'border-red-400': form.errors.name }"
                            />
                        </div>
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Email</label>
                        <div class="relative mt-1">
                            <Mail class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                required
                                placeholder="zlatanibra@equitab.ca"
                                class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-4 text-sm focus:border-equitab-emerald focus:outline-none"
                                :class="{ 'border-red-400': form.errors.email }"
                            />
                        </div>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Mot de passe</label>
                        <div class="relative mt-1">
                            <Lock class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                required
                                placeholder="Min. 8 caractères"
                                class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-10 text-sm focus:border-equitab-emerald focus:outline-none"
                                :class="{ 'border-red-400': form.errors.password }"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400"
                            >
                                <EyeOff v-if="showPassword" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-500">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-700">Confirmer le mot de passe</label>
                        <div class="relative mt-1">
                            <Lock class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="form.password_confirmation"
                                :type="showConfirm ? 'text' : 'password'"
                                autocomplete="new-password"
                                required
                                placeholder="••••••••"
                                class="w-full rounded-lg border border-gray-200 py-2.5 pl-10 pr-10 text-sm focus:border-equitab-emerald focus:outline-none"
                            />
                            <button
                                type="button"
                                @click="showConfirm = !showConfirm"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400"
                            >
                                <EyeOff v-if="showConfirm" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <p class="text-xs text-gray-400">
                        En créant un compte, vous acceptez nos
                        <a href="/conditions" class="text-equitab-emerald hover:underline">conditions d'utilisation</a>
                        et notre
                        <a href="/confidentialite" class="text-equitab-emerald hover:underline">politique de confidentialité</a>.
                    </p>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-equitab-emerald py-3 text-sm font-medium text-white hover:bg-equitab-emerald-dark disabled:opacity-60"
                    >
                        {{ form.processing ? 'Création...' : 'Créer mon compte' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
