<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { X, Eye, EyeOff, Copy, Check, Key, Lock, MessageSquare } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

interface Credentials {
    email: string | null;
    password: string | null;
    notes: string | null;
}

interface Props {
    groupId: number;
    subscriptionName: string;
}

const props = defineProps<Props>();
const emit = defineEmits<{ (e: 'close'): void }>();

const credentials = ref<Credentials | null>(null);
const isLoading = ref(true);
const error = ref<string | null>(null);
const showPassword = ref(false);
const copiedEmail = ref(false);
const copiedPassword = ref(false);

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.split('; ')
            .find(r => r.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? ''
    );
}

async function loadCredentials(): Promise<void> {
    try {
        const response = await fetch(`/api/groups/${props.groupId}/credentials`, {
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });

        if (response.status === 403) {
            error.value = 'Accès refusé — votre abonnement doit être actif pour voir les identifiants.';
            return;
        }

        const data = await response.json();
        credentials.value = data;
    } catch {
        error.value = 'Impossible de charger les identifiants.';
    } finally {
        isLoading.value = false;
    }
}

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

onMounted(() => loadCredentials());
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40" @click="emit('close')" />

        <div class="relative w-full max-w-md rounded-2xl border border-gray-100 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <div class="flex items-center gap-2">
                    <Key class="h-5 w-5 text-equitab-navy" />
                    <h2 class="font-semibold text-equitab-navy">Identifiants — {{ subscriptionName }}</h2>
                </div>
                <button @click="emit('close')" class="text-gray-400 hover:text-gray-600">
                    <X class="h-5 w-5" />
                </button>
            </div>

            <div class="p-6">
                <div v-if="isLoading" class="flex justify-center py-8">
                    <div class="h-6 w-6 animate-spin rounded-full border-2 border-equitab-emerald border-t-transparent" />
                </div>

                <div v-else-if="error" class="rounded-xl bg-red-50 p-4 text-sm text-red-600">
                    {{ error }}
                </div>

                <div v-else-if="credentials" class="space-y-3">
                    <div class="rounded-lg bg-blue-50 p-3 text-xs text-blue-700">
                        <Lock class="mb-1 h-3.5 w-3.5 inline" />
                        Ces identifiants sont chiffrés et accessibles uniquement aux membres actifs.
                    </div>

                    <div v-if="credentials.email" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-gray-400">Email / Identifiant</p>
                            <p class="truncate font-medium text-equitab-navy">{{ credentials.email }}</p>
                        </div>
                        <button
                            @click="copyToClipboard(credentials.email!, 'email')"
                            class="ml-3 shrink-0 flex items-center gap-1 rounded-md px-2 py-1 text-xs transition-colors"
                            :class="copiedEmail ? 'text-equitab-emerald' : 'text-gray-400 hover:text-equitab-emerald'"
                        >
                            <Check v-if="copiedEmail" class="h-3.5 w-3.5" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            {{ copiedEmail ? 'Copié !' : 'Copier' }}
                        </button>
                    </div>

                    <div v-if="credentials.password" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-gray-400">Mot de passe</p>
                            <p class="truncate font-medium font-mono text-equitab-navy">
                                {{ showPassword ? credentials.password : '••••••••••' }}
                            </p>
                        </div>
                        <div class="ml-3 flex shrink-0 items-center gap-2">
                            <button
                                @click="showPassword = !showPassword"
                                class="text-gray-400 hover:text-equitab-navy"
                            >
                                <EyeOff v-if="showPassword" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                            <button
                                @click="copyToClipboard(credentials.password!, 'password')"
                                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs transition-colors"
                                :class="copiedPassword ? 'text-equitab-emerald' : 'text-gray-400 hover:text-equitab-emerald'"
                            >
                                <Check v-if="copiedPassword" class="h-3.5 w-3.5" />
                                <Copy v-else class="h-3.5 w-3.5" />
                                {{ copiedPassword ? 'Copié !' : 'Copier' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="credentials.notes" class="rounded-lg border border-amber-100 bg-amber-50 px-4 py-3">
                        <p class="text-xs font-medium text-amber-700">Note du propriétaire</p>
                        <p class="mt-1 text-sm text-amber-800">{{ credentials.notes }}</p>
                    </div>

                    <div v-if="!credentials.email && !credentials.password && !credentials.notes"
                        class="space-y-3"
                    >
                        <div class="rounded-xl bg-amber-50 border border-amber-100 p-4">
                            <p class="text-sm font-medium text-amber-700">
                                Identifiants non encore disponibles
                            </p>
                            <p class="mt-1 text-xs text-amber-600">
                                Le propriétaire n'a pas encore renseigné les identifiants d'accès.
                                Vous pouvez le contacter via le chat pour les obtenir.
                            </p>
                        </div>
                        <Link
                            href="/dashboard/chat"
                            class="flex items-center justify-center gap-2 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light"
                            @click="emit('close')"
                        >
                            <MessageSquare class="h-4 w-4" />
                            Contacter le propriétaire
                        </Link>
                    </div>

                    <div class="rounded-lg border border-gray-100 p-3 text-xs text-gray-500">
                        <p class="font-medium text-gray-700 mb-1">Comment accéder au service :</p>
                        <ol class="space-y-1 list-decimal list-inside">
                            <li>Connectez-vous sur le site ou l'app du service</li>
                            <li>Utilisez les identifiants ci-dessus</li>
                            <li>En cas de problème, contactez le propriétaire via le chat</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
