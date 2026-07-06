<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import {
    User, Bell, Globe, Shield, Trash2,
    Camera, Check, AlertTriangle
} from 'lucide-vue-next';

interface Props {
    user: {
        name: string;
        username: string | null;
        avatar: string | null;
        email: string;
        locale: string;
        currency: string;
        timezone: string;
        notif_member_joined: boolean;
        notif_payment_received: boolean;
        notif_renewal_reminder: boolean;
        notif_payment_failed: boolean;
        show_real_name: boolean;
        allow_direct_contact: boolean;
    };
}

const props = defineProps<Props>();
const page = usePage();
const successMessage = computed(() => (page.props as any).flash?.success);

const activeTab = ref<'profile' | 'notifications' | 'region' | 'privacy' | 'danger'>('profile');

const tabs = [
    { key: 'profile', label: 'Profil public', icon: User },
    { key: 'notifications', label: 'Notifications', icon: Bell },
    { key: 'region', label: 'Langue & Région', icon: Globe },
    { key: 'privacy', label: 'Confidentialité', icon: Shield },
    { key: 'danger', label: 'Danger', icon: Trash2 },
] as const;

const form = ref({
    username: props.user.username ?? '',
    locale: props.user.locale,
    currency: props.user.currency,
    timezone: props.user.timezone,
    notif_member_joined: props.user.notif_member_joined,
    notif_payment_received: props.user.notif_payment_received,
    notif_renewal_reminder: props.user.notif_renewal_reminder,
    notif_payment_failed: props.user.notif_payment_failed,
    show_real_name: props.user.show_real_name,
    allow_direct_contact: props.user.allow_direct_contact,
});

const deletePassword = ref('');
const isSaving = ref(false);
const isDeleting = ref(false);
const avatarPreview = ref<string | null>(props.user.avatar);
const avatarFile = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

function onAvatarChange(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    avatarFile.value = file;
    avatarPreview.value = URL.createObjectURL(file);
}

function uploadAvatar(): void {
    if (!avatarFile.value) return;
    const formData = new FormData();
    formData.append('avatar', avatarFile.value);
    router.post('/dashboard/preferences/avatar', formData, {
        forceFormData: true,
        onSuccess: () => { avatarFile.value = null; },
    });
}

function savePreferences(): void {
    isSaving.value = true;
    router.patch('/dashboard/preferences', form.value, {
        onFinish: () => { isSaving.value = false; },
    });
}

function deleteAccount(): void {
    if (!deletePassword.value) return;
    isDeleting.value = true;
    router.delete('/dashboard/preferences/account', {
        data: { password: deletePassword.value },
        onFinish: () => { isDeleting.value = false; },
    });
}

const timezones = [
    'America/Toronto',
    'America/Vancouver',
    'America/Winnipeg',
    'America/Halifax',
    'America/St_Johns',
    'America/New_York',
    'Europe/Paris',
    'UTC',
];
</script>

<template>
    <Head title="Préférences — Equitab" />

    <DashboardLayout>
        <div class="mb-6">
            <h1 class="text-2xl font-semibold text-equitab-navy">Préférences</h1>
            <p class="mt-1 text-sm text-gray-500">Personnalisez votre expérience Equitab</p>
        </div>

        <div
            v-if="successMessage"
            class="mb-4 rounded-xl border border-equitab-emerald/20 bg-equitab-emerald/5 p-4 text-sm font-medium text-equitab-emerald"
        >
            {{ successMessage }}
        </div>

        <div class="flex gap-6">
            <aside class="w-48 shrink-0">
                <nav class="space-y-1">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        @click="activeTab = tab.key"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="activeTab === tab.key
                            ? tab.key === 'danger'
                                ? 'bg-red-50 text-red-600'
                                : 'bg-equitab-navy text-white'
                            : tab.key === 'danger'
                                ? 'text-red-500 hover:bg-red-50'
                                : 'text-gray-500 hover:bg-gray-50 hover:text-equitab-navy'"
                    >
                        <component :is="tab.icon" class="h-4 w-4 shrink-0" />
                        {{ tab.label }}
                    </button>
                </nav>
            </aside>

            <div class="flex-1 space-y-4">

                <!-- Profil public -->
                <div v-if="activeTab === 'profile'" class="space-y-4">
                    <div class="rounded-xl border border-gray-100 bg-white p-6">
                        <h2 class="mb-5 font-semibold text-equitab-navy">Avatar</h2>

                        <div class="flex items-center gap-6">
                            <div class="relative">
                                <label class="cursor-pointer block">
                                    <div class="h-20 w-20 overflow-hidden rounded-full bg-equitab-navy/10 relative">
                                        <img
                                            v-if="avatarPreview"
                                            :src="avatarPreview"
                                            class="h-full w-full object-cover"
                                            alt="Avatar"
                                        />
                                        <span v-else class="flex h-full w-full items-center justify-center text-2xl font-bold text-equitab-navy">
                                            {{ user.name.charAt(0).toUpperCase() }}
                                        </span>
                                        <div class="absolute bottom-0 right-0 flex h-6 w-6 items-center justify-center rounded-full bg-equitab-emerald text-white">
                                            <Camera class="h-3 w-3" />
                                        </div>
                                    </div>
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden"
                                        @change="onAvatarChange"
                                    />
                                </label>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-equitab-navy">{{ user.name }}</p>
                                <p class="text-xs text-gray-400">JPG, PNG ou WebP · Max 2 Mo</p>
                                <button
                                    v-if="avatarFile"
                                    @click="uploadAvatar"
                                    class="mt-2 flex items-center gap-1.5 rounded-lg bg-equitab-emerald px-3 py-1.5 text-xs font-medium text-white hover:bg-equitab-emerald-dark"
                                >
                                    <Check class="h-3.5 w-3.5" />
                                    Sauvegarder l'avatar
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-100 bg-white p-6">
                        <h2 class="mb-5 font-semibold text-equitab-navy">Pseudo public</h2>
                        <p class="mb-3 text-sm text-gray-500">
                            Votre pseudo est affiché à la place de votre vrai nom dans les groupes publics.
                        </p>
                        <div class="flex items-center gap-3">
                            <div class="relative flex-1">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">@</span>
                                <input
                                    v-model="form.username"
                                    type="text"
                                    placeholder="mon_pseudo"
                                    maxlength="30"
                                    class="w-full rounded-lg border border-gray-200 pl-7 pr-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                                />
                            </div>
                            <button
                                @click="savePreferences"
                                :disabled="isSaving"
                                class="rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-60"
                            >
                                {{ isSaving ? 'Sauvegarde...' : 'Sauvegarder' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Notifications -->
                <div v-if="activeTab === 'notifications'" class="rounded-xl border border-gray-100 bg-white p-6">
                    <h2 class="mb-5 font-semibold text-equitab-navy">Notifications par email</h2>

                    <div class="space-y-4">
                        <label
                            v-for="(label, key) in {
                                notif_member_joined: 'Un membre rejoint votre groupe',
                                notif_payment_received: 'Un paiement est reçu',
                                notif_renewal_reminder: 'Rappel avant renouvellement (3 jours)',
                                notif_payment_failed: 'Un paiement échoue',
                            }"
                            :key="key"
                            class="flex items-center justify-between rounded-lg border border-gray-100 p-4 cursor-pointer hover:bg-gray-50"
                        >
                            <span class="text-sm text-gray-700">{{ label }}</span>
                            <div
                                class="relative h-6 w-11 rounded-full transition-colors cursor-pointer"
                                :class="form[key as keyof typeof form] ? 'bg-equitab-emerald' : 'bg-gray-200'"
                                @click="(form[key as keyof typeof form] as boolean) = !(form[key as keyof typeof form] as boolean)"
                            >
                                <div
                                    class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                    :class="form[key as keyof typeof form] ? 'translate-x-5' : 'translate-x-0.5'"
                                />
                            </div>
                        </label>
                    </div>

                    <button
                        @click="savePreferences"
                        :disabled="isSaving"
                        class="mt-6 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-60"
                    >
                        {{ isSaving ? 'Sauvegarde...' : 'Sauvegarder' }}
                    </button>
                </div>

                <!-- Langue & Région -->
                <div v-if="activeTab === 'region'" class="rounded-xl border border-gray-100 bg-white p-6">
                    <h2 class="mb-5 font-semibold text-equitab-navy">Langue & Région</h2>

                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-gray-700">Langue</label>
                            <select
                                v-model="form.locale"
                                class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                            >
                                <option value="fr">Français</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">Devise</label>
                            <select
                                v-model="form.currency"
                                class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                            >
                                <option value="CAD">Dollar canadien (CAD)</option>
                                <option value="USD">Dollar américain (USD)</option>
                                <option value="EUR">Euro (EUR)</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700">Fuseau horaire</label>
                            <select
                                v-model="form.timezone"
                                class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                            >
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                        </div>
                    </div>

                    <button
                        @click="savePreferences"
                        :disabled="isSaving"
                        class="mt-6 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-60"
                    >
                        {{ isSaving ? 'Sauvegarde...' : 'Sauvegarder' }}
                    </button>
                </div>

                <!-- Confidentialité -->
                <div v-if="activeTab === 'privacy'" class="rounded-xl border border-gray-100 bg-white p-6">
                    <h2 class="mb-5 font-semibold text-equitab-navy">Confidentialité</h2>

                    <div class="space-y-4">
                        <label
                            v-for="(label, key) in {
                                show_real_name: 'Afficher mon vrai nom dans les groupes publics',
                                allow_direct_contact: 'Autoriser les membres à me contacter directement',
                            }"
                            :key="key"
                            class="flex items-center justify-between rounded-lg border border-gray-100 p-4 cursor-pointer hover:bg-gray-50"
                        >
                            <span class="text-sm text-gray-700">{{ label }}</span>
                            <div
                                class="relative h-6 w-11 rounded-full transition-colors cursor-pointer"
                                :class="form[key as keyof typeof form] ? 'bg-equitab-emerald' : 'bg-gray-200'"
                                @click="(form[key as keyof typeof form] as boolean) = !(form[key as keyof typeof form] as boolean)"
                            >
                                <div
                                    class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform"
                                    :class="form[key as keyof typeof form] ? 'translate-x-5' : 'translate-x-0.5'"
                                />
                            </div>
                        </label>
                    </div>

                    <button
                        @click="savePreferences"
                        :disabled="isSaving"
                        class="mt-6 rounded-lg bg-equitab-navy px-4 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-60"
                    >
                        {{ isSaving ? 'Sauvegarde...' : 'Sauvegarder' }}
                    </button>
                </div>

                <!-- Danger zone -->
                <div v-if="activeTab === 'danger'" class="rounded-xl border border-red-100 bg-white p-6">
                    <div class="flex items-center gap-2 mb-5">
                        <AlertTriangle class="h-5 w-5 text-red-500" />
                        <h2 class="font-semibold text-red-600">Zone de danger</h2>
                    </div>

                    <p class="mb-4 text-sm text-gray-500">
                        La suppression de votre compte est irréversible. Toutes vos données seront définitivement effacées,
                        y compris vos groupes, paiements et messages.
                    </p>

                    <div class="rounded-xl border border-red-100 bg-red-50 p-4">
                        <p class="mb-3 text-sm font-medium text-red-700">
                            Confirmez votre mot de passe pour supprimer votre compte
                        </p>
                        <input
                            v-model="deletePassword"
                            type="password"
                            placeholder="Votre mot de passe actuel"
                            class="w-full rounded-lg border border-red-200 bg-white px-3 py-2.5 text-sm focus:border-red-400 focus:outline-none"
                        />
                        <button
                            @click="deleteAccount"
                            :disabled="isDeleting || !deletePassword"
                            class="mt-3 flex items-center gap-2 rounded-lg bg-red-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50"
                        >
                            <Trash2 class="h-4 w-4" />
                            {{ isDeleting ? 'Suppression...' : 'Supprimer définitivement mon compte' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </DashboardLayout>
</template>
