<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import { getBrandGradient } from '@/config/brandGradients';
import {
    Users, ChevronRight, Eye, EyeOff,
    TrendingDown, Shield, Lock, Globe, Link2, UserCheck,
    ShieldAlert, CheckCircle, Circle,
} from 'lucide-vue-next';

interface Subscription {
    id: number;
    name: string;
    slug: string;
    max_members: number;
    monthly_price: number;
    category: string;
}

interface Props {
    subscriptions: Subscription[];
    verificationError?: boolean;
    identityVerified?: boolean;
    connectActive?: boolean;
}

const props = defineProps<Props>();

const step = ref<1 | 2 | 3 | 4>(1);
const isSubmitting = ref(false);
const errors = ref<Record<string, string>>({});
const showPassword = ref(false);
const certify = ref(false);

const form = ref({
    subscription_id: null as number | null,
    name: '',
    description: '',
    tier: 'standard' as 'standard' | 'premium' | 'famille',
    max_members: 2,
    price_per_member: 0,
    split_type: 'equal' as 'equal',
    visibility: 'public' as 'public' | 'private' | 'invite_only',
    renewal_date: '',
    auto_renew: true,
    credential_email: '',
    credential_password: '',
    credential_notes: '',
});

const selectedSubscription = computed(() =>
    props.subscriptions.find(s => s.id === form.value.subscription_id)
);

const suggestedPrice = computed(() => {
    if (!selectedSubscription.value) return 0;
    return Math.round(selectedSubscription.value.monthly_price / form.value.max_members);
});

const monthlyEarnings = computed(() =>
    form.value.price_per_member * (form.value.max_members - 1)
);

const annualSavings = computed(() => {
    if (!selectedSubscription.value) return 0;
    const ownerShare = selectedSubscription.value.monthly_price - monthlyEarnings.value;
    return (selectedSubscription.value.monthly_price - ownerShare) * 12;
});

const netCostAfterSharing = computed(() => {
    if (!selectedSubscription.value) return 0;
    return selectedSubscription.value.monthly_price - monthlyEarnings.value;
});

const gradient = computed(() => {
    if (!selectedSubscription.value) return { from: '#0B1929', to: '#10B981' };
    return getBrandGradient(selectedSubscription.value.slug);
});

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}

function selectSubscription(sub: Subscription): void {
    form.value.subscription_id = sub.id;
    form.value.name = `Groupe ${sub.name}`;
    form.value.price_per_member = Math.round(sub.monthly_price / 2);
    step.value = 2;
}

async function submit(): Promise<void> {
    if (!certify.value) return;
    isSubmitting.value = true;
    errors.value = {};

    const renewalDate = form.value.renewal_date ||
        new Date(new Date().setMonth(new Date().getMonth() + 1))
            .toISOString().split('T')[0];

    router.post('/groups', {
        ...form.value,
        renewal_date: renewalDate,
    }, {
        onError: (e) => { errors.value = e; isSubmitting.value = false; },
        onSuccess: () => { router.visit('/dashboard/subscriptions'); },
        onFinish: () => { isSubmitting.value = false; },
    });
}

const stepLabels = ['Service', 'Prix', 'Visibilité', 'Identifiants'];

const visibilityOptions = [
    {
        value: 'public',
        label: 'Public',
        description: 'Votre groupe est visible par tous les utilisateurs Equitab.',
        advantage: 'Le plus rapide pour trouver des membres',
        icon: Globe,
    },
    {
        value: 'invite_only',
        label: 'Sur invitation',
        description: 'Seules les personnes avec votre lien peuvent rejoindre.',
        advantage: 'Confidentialité et contrôle total',
        icon: Link2,
    },
    {
        value: 'private',
        label: 'Privé',
        description: 'Vous invitez directement chaque membre par email.',
        advantage: 'Pour partager avec vos proches uniquement',
        icon: UserCheck,
    },
] as const;
</script>

<template>
    <Head title="Partager un abonnement — Equitab" />

    <DashboardLayout>
        <div v-if="verificationError" class="mx-auto max-w-lg text-center py-16">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-50">
                <ShieldAlert class="h-8 w-8 text-amber-500" />
            </div>
            <h1 class="mt-6 text-2xl font-semibold text-equitab-navy">
                Vérification requise
            </h1>
            <p class="mt-3 text-gray-500">
                Pour des raisons de sécurité, vous devez vérifier votre identité et configurer votre compte bancaire avant de pouvoir partager un abonnement.
            </p>

            <div class="mt-8 space-y-3 text-left">
                <div class="flex items-center gap-3 rounded-xl border border-gray-100 bg-white p-4">
                    <component
                        :is="identityVerified ? CheckCircle : Circle"
                        class="h-5 w-5 shrink-0"
                        :class="identityVerified ? 'text-equitab-emerald' : 'text-gray-300'"
                    />
                    <span class="text-sm" :class="identityVerified ? 'text-gray-700' : 'text-gray-400'">
                        Identité vérifiée
                    </span>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-gray-100 bg-white p-4">
                    <component
                        :is="connectActive ? CheckCircle : Circle"
                        class="h-5 w-5 shrink-0"
                        :class="connectActive ? 'text-equitab-emerald' : 'text-gray-300'"
                    />
                    <span class="text-sm" :class="connectActive ? 'text-gray-700' : 'text-gray-400'">
                        Compte bancaire configuré
                    </span>
                </div>
            </div>

            <Link
                href="/dashboard/profile"
                class="mt-8 inline-flex items-center gap-2 rounded-lg bg-equitab-navy px-6 py-3 text-sm font-medium text-white hover:bg-equitab-navy-light"
            >
                Compléter ma vérification
            </Link>
        </div>

        <div v-else class="mx-auto max-w-2xl">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold text-equitab-navy">Partager un abonnement</h1>
                <p class="mt-1 text-sm text-gray-500">Créez un groupe et invitez des membres à partager vos frais.</p>
            </div>

            <div class="mb-8 flex items-center gap-1">
                <template v-for="(label, i) in stepLabels" :key="i">
                    <div class="flex items-center gap-2">
                        <div
                            class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                            :class="step > i + 1
                                ? 'bg-equitab-emerald text-white'
                                : step === i + 1
                                ? 'bg-equitab-navy text-white'
                                : 'bg-gray-100 text-gray-400'"
                        >
                            <span v-if="step > i + 1">✓</span>
                            <span v-else>{{ i + 1 }}</span>
                        </div>
                        <span
                            class="hidden text-xs sm:block"
                            :class="step === i + 1 ? 'font-medium text-equitab-navy' : 'text-gray-400'"
                        >
                            {{ label }}
                        </span>
                    </div>
                    <div v-if="i < stepLabels.length - 1" class="mx-1 h-px flex-1 bg-gray-200" />
                </template>
            </div>

            <div v-if="step === 1">
                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="sub in subscriptions"
                        :key="sub.id"
                        @click="selectSubscription(sub)"
                        class="flex items-center gap-4 rounded-xl border border-gray-100 bg-white p-4 text-left transition-all hover:border-equitab-emerald hover:shadow-sm"
                    >
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-xl font-bold text-white"
                            :style="{ background: `linear-gradient(135deg, ${getBrandGradient(sub.slug).from}, ${getBrandGradient(sub.slug).to})` }"
                        >
                            {{ sub.name.charAt(0) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="truncate font-semibold text-equitab-navy">{{ sub.name }}</p>
                            <div class="mt-1 flex items-center gap-2 text-xs text-gray-400">
                                <span class="flex items-center gap-1">
                                    <Users class="h-3.5 w-3.5" />
                                    {{ sub.max_members }} max
                                </span>
                                <span>·</span>
                                <span class="font-medium text-equitab-navy">{{ formatPrice(sub.monthly_price) }}/mois</span>
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <div v-if="step === 2 && selectedSubscription" class="space-y-4">
                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl text-lg font-bold text-white"
                            :style="{ background: `linear-gradient(135deg, ${gradient.from}, ${gradient.to})` }"
                        >
                            {{ selectedSubscription.name.charAt(0) }}
                        </div>
                        <div>
                            <p class="font-semibold text-equitab-navy">{{ selectedSubscription.name }}</p>
                            <p class="text-xs text-gray-400">Prix total : {{ formatPrice(selectedSubscription.monthly_price) }} / mois</p>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div>
                            <label class="text-sm font-medium text-equitab-emerald">
                                Combien de places souhaitez-vous partager ?
                            </label>
                            <div class="mt-3 flex items-center gap-4">
                                <button
                                    @click="form.max_members = Math.max(2, form.max_members - 1)"
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-equitab-emerald text-white hover:bg-equitab-emerald-dark"
                                >
                                    −
                                </button>
                                <span class="flex h-14 w-24 items-center justify-center rounded-xl border-2 border-gray-200 text-2xl font-bold text-equitab-navy">
                                    {{ form.max_members - 1 }}
                                </span>
                                <button
                                    @click="form.max_members = Math.min(selectedSubscription.max_members, form.max_members + 1)"
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-200 text-gray-600 hover:bg-gray-300"
                                >
                                    +
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-gray-400">
                                Maximum {{ selectedSubscription.max_members - 1 }} places pour ce service
                            </p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">Prix par membre / mois</label>
                            <div class="mt-2 flex items-center gap-3">
                                <div class="relative flex-1">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">$</span>
                                    <input
                                        v-model.number="form.price_per_member"
                                        type="number"
                                        min="100"
                                        step="50"
                                        class="w-full rounded-lg border border-gray-200 pl-7 pr-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                                    />
                                </div>
                                <button
                                    @click="form.price_per_member = suggestedPrice"
                                    class="rounded-lg border border-dashed border-equitab-emerald px-3 py-2.5 text-xs font-medium text-equitab-emerald hover:bg-equitab-emerald/5"
                                >
                                    Prix suggéré
                                </button>
                            </div>
                        </div>

                        <div class="rounded-xl bg-equitab-emerald/5 p-4">
                            <p class="text-sm text-gray-600">
                                Chaque personne vous paie
                                <strong class="text-equitab-navy">{{ formatPrice(form.price_per_member) }} / mois</strong>
                            </p>
                            <p class="mt-1 text-base font-semibold text-equitab-navy">
                                Vous recevrez
                                <span class="text-equitab-emerald">{{ formatPrice(monthlyEarnings) }} / mois</span>
                                au total
                            </p>
                            <div class="mt-3 border-t border-equitab-emerald/20 pt-3">
                                <p class="text-sm text-gray-600">
                                    Votre coût net après partage :
                                    <strong class="text-equitab-navy">{{ formatPrice(netCostAfterSharing) }} / mois</strong>
                                </p>
                                <p class="mt-1 flex items-center gap-1 text-sm font-semibold text-equitab-emerald">
                                    <TrendingDown class="h-4 w-4" />
                                    Vous économisez {{ formatPrice(annualSavings) }} par an grâce au partage
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button @click="step = 1" class="rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        ← Précédent
                    </button>
                    <button
                        @click="step = 3"
                        :disabled="!form.price_per_member"
                        class="flex-1 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-50"
                    >
                        Continuer
                    </button>
                </div>
            </div>

            <div v-if="step === 3" class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-equitab-navy">
                        Visibilité de votre abonnement
                        <span class="text-equitab-emerald">{{ selectedSubscription?.name }}</span>
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">Choisissez qui peut voir et rejoindre votre groupe.</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <button
                        v-for="opt in visibilityOptions"
                        :key="opt.value"
                        @click="form.visibility = opt.value"
                        class="rounded-xl border p-4 text-left transition-all"
                        :class="form.visibility === opt.value
                            ? 'border-equitab-emerald bg-equitab-emerald/5'
                            : 'border-gray-100 bg-white hover:border-gray-200'"
                    >
                        <component
                            :is="opt.icon"
                            class="h-6 w-6"
                            :class="form.visibility === opt.value ? 'text-equitab-emerald' : 'text-gray-400'"
                        />
                        <p
                            class="mt-2 font-semibold"
                            :class="form.visibility === opt.value ? 'text-equitab-emerald' : 'text-equitab-navy'"
                        >
                            {{ opt.label }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">{{ opt.description }}</p>
                        <p class="mt-2 text-xs font-medium text-equitab-emerald">{{ opt.advantage }}</p>
                    </button>
                </div>

                <div class="flex gap-3">
                    <button @click="step = 2" class="rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        ← Précédent
                    </button>
                    <button
                        @click="step = 4"
                        class="flex-1 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light"
                    >
                        Continuer
                    </button>
                </div>
            </div>

            <div v-if="step === 4" class="space-y-4">
                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <div class="mb-4 flex items-center gap-2">
                        <Lock class="h-5 w-5 text-equitab-navy" />
                        <h3 class="font-semibold text-equitab-navy">Partagez vos identifiants</h3>
                    </div>

                    <div class="mb-4 rounded-lg bg-blue-50 p-3 text-xs text-blue-700">
                        <p>1. Ces informations sont <strong>chiffrées</strong> et ne sont visibles qu'aux membres ayant un abonnement actif.</p>
                        <p class="mt-1">2. Equitab ne peut pas lire vos identifiants — ils sont chiffrés de bout en bout.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-medium text-gray-700">Identifiant (email)</label>
                                <input
                                    v-model="form.credential_email"
                                    type="email"
                                    placeholder="exemple@email.com"
                                    class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                                />
                            </div>
                            <div>
                                <label class="text-sm font-medium text-gray-700">Mot de passe</label>
                                <div class="relative mt-1">
                                    <input
                                        v-model="form.credential_password"
                                        :type="showPassword ? 'text' : 'password'"
                                        placeholder="Mot de passe du service"
                                        class="w-full rounded-lg border border-gray-200 px-3 py-2.5 pr-10 text-sm focus:border-equitab-emerald focus:outline-none"
                                    />
                                    <button
                                        @click="showPassword = !showPassword"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400"
                                    >
                                        <EyeOff v-if="showPassword" class="h-4 w-4" />
                                        <Eye v-else class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">
                                Informations complémentaires
                                <span class="text-gray-400">(optionnel)</span>
                            </label>
                            <textarea
                                v-model="form.credential_notes"
                                rows="3"
                                placeholder="Ex: Utilisez le profil 'Invité 1', connectez-vous via l'app mobile..."
                                class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                            />
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <h3 class="mb-3 font-semibold text-equitab-navy">Récapitulatif</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Service</span>
                            <span class="font-medium">{{ selectedSubscription?.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Places</span>
                            <span class="font-medium">{{ form.max_members - 1 }} membre(s)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Prix par membre</span>
                            <span class="font-medium">{{ formatPrice(form.price_per_member) }} / mois</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Visibilité</span>
                            <span class="font-medium capitalize">{{ form.visibility }}</span>
                        </div>
                        <div class="flex justify-between border-t border-gray-100 pt-2">
                            <span class="text-gray-500">Vous récupérerez</span>
                            <span class="font-semibold text-equitab-emerald">{{ formatPrice(monthlyEarnings) }} / mois</span>
                        </div>
                    </div>
                </div>

                <label class="flex items-start gap-3 rounded-xl border border-gray-100 bg-white p-4 cursor-pointer">
                    <input v-model="certify" type="checkbox" class="mt-0.5 accent-equitab-emerald" />
                    <span class="text-sm text-gray-600">
                        Je certifie être titulaire de cet abonnement et accepte les
                        <a href="#" class="text-equitab-emerald hover:underline">conditions générales</a>
                        d'Equitab.
                    </span>
                </label>

                <div class="flex gap-3">
                    <button @click="step = 3" class="rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        ← Précédent
                    </button>
                    <button
                        @click="submit"
                        :disabled="isSubmitting || !certify"
                        class="flex-1 rounded-lg bg-equitab-emerald px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-emerald-dark disabled:opacity-50"
                    >
                        {{ isSubmitting ? 'Création en cours...' : 'Créer le groupe' }}
                    </button>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>
