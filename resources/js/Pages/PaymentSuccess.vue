<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle, Eye, EyeOff, Copy, MessageSquare, LayoutDashboard, Key } from 'lucide-vue-next';
import { getBrandGradient } from '@/config/brandGradients';

interface Credentials {
    email: string | null;
    password: string | null;
    notes: string | null;
}

interface Group {
    id: number;
    name: string;
    subscriptionName: string;
    subscriptionSlug: string;
    ownerName: string;
    pricePerMember: number;
    renewalDate: string;
    memberStatus?: string;
}

interface Props {
    group: Group;
    credentials: Credentials | null;
}

const props = defineProps<Props>();

const showPassword = ref(false);
const copiedEmail = ref(false);
const copiedPassword = ref(false);

const gradient = computed(() => getBrandGradient(props.group.subscriptionSlug));

const formattedPrice = computed(() =>
    new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(
        props.group.pricePerMember / 100
    )
);

async function copyToClipboard(text: string, type: 'email' | 'password'): Promise<void> {
    await navigator.clipboard.writeText(text);
    if (type === 'email') {
        copiedEmail.value = true;
        setTimeout(() => copiedEmail.value = false, 2000);
    } else {
        copiedPassword.value = true;
        setTimeout(() => copiedPassword.value = false, 2000);
    }
}
</script>

<template>
    <Head title="Paiement confirmé — Equitab" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-6">
        <div class="w-full max-w-lg">

            <div class="mb-6 flex flex-col items-center text-center">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-equitab-emerald/10">
                    <CheckCircle class="h-8 w-8 text-equitab-emerald" />
                </div>
                <h1 class="mt-4 text-2xl font-semibold text-equitab-navy">
                    Votre paiement a été réalisé avec succès
                </h1>
                <p class="mt-2 text-sm text-gray-500">
                    Vous recevrez un email de confirmation sous peu.
                </p>
            </div>

            <div class="rounded-xl border border-gray-100 bg-white overflow-hidden mb-4">
                <div
                    class="p-5 text-white"
                    :style="{ background: `linear-gradient(135deg, ${gradient.from}, ${gradient.to})` }"
                >
                    <p class="text-lg font-semibold">{{ group.subscriptionName }}</p>
                    <p class="text-sm opacity-80">Partagé par {{ group.ownerName }}</p>
                    <div class="mt-3 flex items-baseline gap-1">
                        <span class="text-2xl font-bold">{{ formattedPrice }}</span>
                        <span class="text-sm opacity-80">/ mois</span>
                    </div>
                    <p class="mt-1 text-xs opacity-70">
                        Renouvellement automatique le {{ group.renewalDate }}
                    </p>
                </div>

                <div v-if="credentials" class="p-5">
                    <div class="mb-3 flex items-center gap-2">
                        <Key class="h-4 w-4 text-equitab-navy" />
                        <p class="font-semibold text-equitab-navy">Accès par identifiants</p>
                    </div>

                    <div class="space-y-3">
                        <div v-if="credentials.email" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                            <div>
                                <p class="text-xs text-gray-400">Email</p>
                                <p class="font-medium text-equitab-navy">{{ credentials.email }}</p>
                            </div>
                            <button
                                @click="copyToClipboard(credentials.email!, 'email')"
                                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-gray-400 hover:text-equitab-emerald transition-colors"
                            >
                                <Copy class="h-3.5 w-3.5" />
                                {{ copiedEmail ? 'Copié !' : 'Copier' }}
                            </button>
                        </div>

                        <div v-if="credentials.password" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                            <div>
                                <p class="text-xs text-gray-400">Mot de passe</p>
                                <p class="font-medium text-equitab-navy font-mono">
                                    {{ showPassword ? credentials.password : '••••••••••' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    @click="showPassword = !showPassword"
                                    class="text-gray-400 hover:text-equitab-navy transition-colors"
                                >
                                    <EyeOff v-if="showPassword" class="h-4 w-4" />
                                    <Eye v-else class="h-4 w-4" />
                                </button>
                                <button
                                    @click="copyToClipboard(credentials.password!, 'password')"
                                    class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-gray-400 hover:text-equitab-emerald transition-colors"
                                >
                                    <Copy class="h-3.5 w-3.5" />
                                    {{ copiedPassword ? 'Copié !' : 'Copier' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="credentials.notes" class="rounded-lg bg-amber-50 px-4 py-3">
                            <p class="text-xs font-medium text-amber-700">Note du propriétaire</p>
                            <p class="mt-1 text-sm text-amber-800">{{ credentials.notes }}</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-lg border border-gray-100 p-3">
                        <p class="text-xs font-medium text-gray-700">Comment accéder au service :</p>
                        <ol class="mt-2 space-y-1 text-xs text-gray-500 list-decimal list-inside">
                            <li>Connectez-vous sur le site ou l'app du service</li>
                            <li>Utilisez les identifiants ci-dessus</li>
                            <li>En cas de problème, contactez le propriétaire via le chat</li>
                        </ol>
                    </div>
                </div>

                <div v-else-if="group.memberStatus && group.memberStatus !== 'active'" class="p-5">
                    <p class="text-sm text-gray-500">
                        Votre paiement est en cours de traitement par Stripe. L'accès s'activera
                        automatiquement dans quelques instants — rechargez cette page pour vérifier,
                        ou revenez plus tard depuis votre espace.
                    </p>
                </div>

                <div v-else class="p-5">
                    <p class="text-sm text-gray-500">
                        Le propriétaire vous partagera les identifiants d'accès via le chat.
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    :href="`/dashboard/chat`"
                    class="flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-equitab-navy hover:bg-gray-50"
                >
                    <MessageSquare class="h-4 w-4" />
                    Contacter le propriétaire
                </Link>
                <Link
                    href="/dashboard/subscriptions"
                    class="flex items-center justify-center gap-2 rounded-xl bg-equitab-emerald px-4 py-3 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
                >
                    <LayoutDashboard class="h-4 w-4" />
                    Accéder à mon espace
                </Link>
            </div>
        </div>
    </div>
</template>
