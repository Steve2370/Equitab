<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import {
    ShieldCheck, ShieldAlert, CreditCard,
    Building2, CheckCircle, Clock, AlertCircle,
    ChevronRight, User, Pencil, X, Save
} from 'lucide-vue-next';

interface Props {
    user: {
        name: string;
        email: string;
        phone: string | null;
        address: string | null;
        city: string | null;
        province: string | null;
        postal_code: string | null;
        identity_status: string;
        stripe_connect_status: string;
        trust_score: number | null;
        completed_payments: number;
    };
}

const props = defineProps<Props>();
const page = usePage();

const isEditing = ref(false);
const isSaving = ref(false);
const isLoadingIdentity = ref(false);
const isLoadingConnect = ref(false);

const form = ref({
    name: props.user.name,
    phone: props.user.phone ?? '',
    address: props.user.address ?? '',
    city: props.user.city ?? '',
    province: props.user.province ?? '',
    postal_code: props.user.postal_code ?? '',
});

const successMessage = computed(() => (page.props as any).flash?.success);

const isIdentityVerified = computed(() => props.user.identity_status === 'verified');
const isConnectActive = computed(() => props.user.stripe_connect_status === 'active');

const identityStatusConfig = computed(() => {
    const configs: Record<string, { label: string; icon: any; class: string; bg: string }> = {
        verified: { label: 'Identité vérifiée', icon: CheckCircle, class: 'text-equitab-emerald', bg: 'bg-equitab-emerald/10' },
        pending: { label: 'Vérification en cours', icon: Clock, class: 'text-amber-500', bg: 'bg-amber-50' },
        failed: { label: 'Vérification échouée', icon: AlertCircle, class: 'text-red-500', bg: 'bg-red-50' },
        unverified: { label: 'Non vérifié', icon: ShieldAlert, class: 'text-gray-400', bg: 'bg-gray-50' },
    };
    return configs[props.user.identity_status] ?? configs.unverified;
});

const connectStatusConfig = computed(() => {
    const configs: Record<string, { label: string; class: string }> = {
        active: { label: 'Compte actif', class: 'text-equitab-emerald' },
        pending: { label: 'Configuration en cours', class: 'text-amber-500' },
        restricted: { label: 'Accès limité', class: 'text-red-500' },
        not_started: { label: 'Non configuré', class: 'text-gray-400' },
    };
    return configs[props.user.stripe_connect_status] ?? configs.not_started;
});

function cancelEdit(): void {
    form.value = {
        name: props.user.name,
        phone: props.user.phone ?? '',
        address: props.user.address ?? '',
        city: props.user.city ?? '',
        province: props.user.province ?? '',
        postal_code: props.user.postal_code ?? '',
    };
    isEditing.value = false;
}

function saveProfile(): void {
    isSaving.value = true;
    router.patch('/dashboard/profile', form.value, {
        onSuccess: () => { isEditing.value = false; },
        onFinish: () => { isSaving.value = false; },
    });
}

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.split('; ')
            .find(r => r.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? ''
    );
}

async function startIdentityVerification(): Promise<void> {
    isLoadingIdentity.value = true;
    try {
        const response = await fetch('/api/stripe/identity', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        });
        const data = await response.json();
        if (data.url) window.location.href = data.url;
    } finally {
        isLoadingIdentity.value = false;
    }
}

async function startOnboarding(): Promise<void> {
    isLoadingConnect.value = true;
    try {
        const response = await fetch('/api/stripe/onboarding', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
        });

        console.log('Status:', response.status);
        const data = await response.json();
        console.log('Data:', data);

        if (data.url) window.location.href = data.url;
    } catch (e) {
        console.error('Erreur:', e);
    } finally {
        isLoadingConnect.value = false;
    }
}

const canadianProvinces = [
    { code: 'AB', name: 'Alberta' },
    { code: 'BC', name: 'Colombie-Britannique' },
    { code: 'MB', name: 'Manitoba' },
    { code: 'NB', name: 'Nouveau-Brunswick' },
    { code: 'NL', name: 'Terre-Neuve-et-Labrador' },
    { code: 'NS', name: 'Nouvelle-Écosse' },
    { code: 'ON', name: 'Ontario' },
    { code: 'PE', name: 'Île-du-Prince-Édouard' },
    { code: 'QC', name: 'Québec' },
    { code: 'SK', name: 'Saskatchewan' },
    { code: 'NT', name: 'Territoires du Nord-Ouest' },
    { code: 'NU', name: 'Nunavut' },
    { code: 'YT', name: 'Yukon' },
];
</script>

<template>
    <Head title="Profil — Equitab" />

    <DashboardLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-equitab-navy">Mon profil</h1>
                <p class="mt-1 text-sm text-gray-500">Gérez votre identité et vos moyens de paiement</p>
            </div>
        </div>

        <div
            v-if="successMessage"
            class="mb-4 rounded-xl border border-equitab-emerald/20 bg-equitab-emerald/5 p-4 text-sm font-medium text-equitab-emerald"
        >
            {{ successMessage }}
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-4">

                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <div class="mb-5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-equitab-navy/10">
                                <User class="h-5 w-5 text-equitab-navy" />
                            </div>
                            <h2 class="font-semibold text-equitab-navy">Informations personnelles</h2>
                        </div>
                        <button
                            v-if="!isEditing"
                            @click="isEditing = true"
                            class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:border-equitab-navy hover:text-equitab-navy"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                            Modifier
                        </button>
                        <div v-else class="flex gap-2">
                            <button
                                @click="cancelEdit"
                                class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
                            >
                                <X class="h-3.5 w-3.5" />
                                Annuler
                            </button>
                            <button
                                @click="saveProfile"
                                :disabled="isSaving"
                                class="flex items-center gap-1.5 rounded-lg bg-equitab-emerald px-3 py-1.5 text-sm font-medium text-white hover:bg-equitab-emerald-dark disabled:opacity-60"
                            >
                                <Save class="h-3.5 w-3.5" />
                                {{ isSaving ? 'Sauvegarde...' : 'Sauvegarder' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="!isEditing" class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Nom complet</label>
                                <p class="mt-1 font-medium text-equitab-navy">{{ user.name }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Email</label>
                                <p class="mt-1 font-medium text-equitab-navy">{{ user.email }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Téléphone</label>
                                <p class="mt-1 font-medium text-equitab-navy">{{ user.phone ?? '—' }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Paiements complétés</label>
                                <p class="mt-1 font-medium text-equitab-navy">{{ user.completed_payments }}</p>
                            </div>
                        </div>

                        <div class="border-t border-gray-50 pt-4">
                            <p class="mb-3 text-xs font-medium uppercase tracking-wider text-gray-400">Adresse</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="text-xs text-gray-400">Adresse</label>
                                    <p class="mt-1 font-medium text-equitab-navy">{{ user.address ?? '—' }}</p>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-400">Ville</label>
                                    <p class="mt-1 font-medium text-equitab-navy">{{ user.city ?? '—' }}</p>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-400">Province</label>
                                    <p class="mt-1 font-medium text-equitab-navy">{{ user.province ?? '—' }}</p>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-400">Code postal</label>
                                    <p class="mt-1 font-medium text-equitab-navy">{{ user.postal_code ?? '—' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-medium text-gray-600">Nom complet</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-equitab-emerald focus:outline-none"
                                />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-600">Email</label>
                                <input
                                    :value="user.email"
                                    type="email"
                                    disabled
                                    class="mt-1 w-full rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-400 cursor-not-allowed"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-gray-600">Téléphone</label>
                                <input
                                    v-model="form.phone"
                                    type="tel"
                                    placeholder="+1 514 000 0000"
                                    class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-equitab-emerald focus:outline-none"
                                />
                            </div>
                        </div>

                        <div class="border-t border-gray-100 pt-4">
                            <p class="mb-3 text-xs font-medium text-gray-600">Adresse</p>
                            <div class="space-y-3">
                                <div>
                                    <label class="text-xs text-gray-500">Adresse</label>
                                    <input
                                        v-model="form.address"
                                        type="text"
                                        placeholder="123 Rue Principale"
                                        class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-equitab-emerald focus:outline-none"
                                    />
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div class="sm:col-span-1">
                                        <label class="text-xs text-gray-500">Ville</label>
                                        <input
                                            v-model="form.city"
                                            type="text"
                                            placeholder="Montréal"
                                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-equitab-emerald focus:outline-none"
                                        />
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Province</label>
                                        <select
                                            v-model="form.province"
                                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-equitab-emerald focus:outline-none"
                                        >
                                            <option value="">—</option>
                                            <option
                                                v-for="p in canadianProvinces"
                                                :key="p.code"
                                                :value="p.code"
                                            >
                                                {{ p.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Code postal</label>
                                        <input
                                            v-model="form.postal_code"
                                            type="text"
                                            placeholder="H2X 1Y6"
                                            class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-equitab-emerald focus:outline-none"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full" :class="identityStatusConfig.bg">
                            <component :is="identityStatusConfig.icon" class="h-5 w-5" :class="identityStatusConfig.class" />
                        </div>
                        <div>
                            <h2 class="font-semibold text-equitab-navy">Vérification d'identité</h2>
                            <p class="text-sm" :class="identityStatusConfig.class">{{ identityStatusConfig.label }}</p>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500 mb-4">
                        La vérification est requise pour créer un groupe. Vos données sont traitées par Stripe Identity.
                    </p>
                    <div v-if="isIdentityVerified" class="rounded-lg bg-equitab-emerald/5 border border-equitab-emerald/20 p-4">
                        <div class="flex items-center gap-2">
                            <ShieldCheck class="h-5 w-5 text-equitab-emerald" />
                            <p class="text-sm font-medium text-equitab-emerald">Identité vérifiée avec succès.</p>
                        </div>
                    </div>
                    <button
                        v-else
                        @click="startIdentityVerification"
                        :disabled="isLoadingIdentity"
                        class="flex items-center gap-2 rounded-md bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-60"
                    >
                        <ShieldCheck class="h-4 w-4" />
                        {{ isLoadingIdentity ? 'Chargement...' : 'Vérifier mon identité' }}
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>

                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-equitab-navy/10">
                            <Building2 class="h-5 w-5 text-equitab-navy" />
                        </div>
                        <div>
                            <h2 class="font-semibold text-equitab-navy">Compte bancaire (Stripe Connect)</h2>
                            <p class="text-sm" :class="connectStatusConfig.class">{{ connectStatusConfig.label }}</p>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500 mb-4">
                        Connectez votre compte bancaire pour recevoir les paiements. Equitab ne détient jamais votre argent.
                    </p>
                    <div v-if="isConnectActive" class="rounded-lg bg-equitab-emerald/5 border border-equitab-emerald/20 p-4">
                        <div class="flex items-center gap-2">
                            <CheckCircle class="h-5 w-5 text-equitab-emerald" />
                            <p class="text-sm font-medium text-equitab-emerald">Votre compte est prêt à recevoir des paiements.</p>
                        </div>
                    </div>
                    <button
                        v-else
                        @click="startOnboarding"
                        :disabled="isLoadingConnect"
                        class="flex items-center gap-2 rounded-md bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-60"
                    >
                        <CreditCard class="h-4 w-4" />
                        {{ isLoadingConnect ? 'Chargement...' : 'Configurer mon compte bancaire' }}
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <h3 class="font-semibold text-equitab-navy mb-4">Score de confiance</h3>
                    <div class="flex items-center gap-3">
                        <div class="relative h-16 w-16">
                            <svg viewBox="0 0 36 36" class="h-16 w-16 -rotate-90">
                                <circle cx="18" cy="18" r="15.9" fill="none" stroke="#E5E7EB" stroke-width="2.5" />
                                <circle
                                    cx="18" cy="18" r="15.9" fill="none"
                                    stroke="#10B981" stroke-width="2.5"
                                    stroke-dasharray="100"
                                    :stroke-dashoffset="100 - (user.trust_score ?? 0) * 100"
                                    stroke-linecap="round"
                                />
                            </svg>
                            <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-equitab-navy">
                                {{ user.trust_score ? Math.round(user.trust_score * 100) : 0 }}%
                            </span>
                        </div>
                        <div>
                            <p class="font-medium text-equitab-navy">
                                {{ (user.trust_score ?? 0) >= 0.8 ? 'Excellent' : (user.trust_score ?? 0) >= 0.5 ? 'Bon' : 'En construction' }}
                            </p>
                            <p class="text-xs text-gray-400">{{ user.completed_payments }} paiements</p>
                        </div>
                    </div>

                    <div class="mt-4 space-y-2">
                        <div class="flex items-center gap-2 text-sm">
                            <component :is="isIdentityVerified ? CheckCircle : AlertCircle" class="h-4 w-4" :class="isIdentityVerified ? 'text-equitab-emerald' : 'text-gray-300'" />
                            <span :class="isIdentityVerified ? 'text-gray-700' : 'text-gray-400'">Identité vérifiée</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <component :is="isConnectActive ? CheckCircle : AlertCircle" class="h-4 w-4" :class="isConnectActive ? 'text-equitab-emerald' : 'text-gray-300'" />
                            <span :class="isConnectActive ? 'text-gray-700' : 'text-gray-400'">Compte bancaire connecté</span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <component :is="user.completed_payments > 0 ? CheckCircle : AlertCircle" class="h-4 w-4" :class="user.completed_payments > 0 ? 'text-equitab-emerald' : 'text-gray-300'" />
                            <span :class="user.completed_payments > 0 ? 'text-gray-700' : 'text-gray-400'">Historique de paiements</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-dashed border-amber-200 bg-amber-50/50 p-4">
                    <p class="text-xs font-medium text-amber-700">Pourquoi vérifier mon identité ?</p>
                    <p class="mt-1 text-xs text-amber-600">
                        Cela rassure les membres et augmente votre score de confiance, rendant vos offres plus visibles.
                    </p>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>
