<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import Toast from '@/Components/Toast.vue';
import { useToast } from '@/composables/useToast';
import { ref, computed, onMounted } from 'vue';

interface Props {
    canLogin?: boolean;
    canRegister?: boolean;
    isAuthenticated?: boolean;
    userName?: string;
}

const props = withDefaults(defineProps<Props>(), {
    canLogin: true,
    canRegister: true,
    isAuthenticated: false,
    userName: '',
});

const toastComponent = ref();
const { setRef } = useToast();

onMounted(() => {
    if (toastComponent.value) setRef(toastComponent.value);
});

const page = usePage();

function isActive(href: string): boolean {
    return page.url.startsWith(href);
}

const sharedUserName = computed(() => {
    // Convention Laravel/Inertia standard : le nom de l'utilisateur connecté
    // est généralement partagé globalement via auth.user dans HandleInertiaRequests.
    const auth = page.props.auth as { user?: { name?: string } } | undefined;
    return auth?.user?.name ?? '';
});

const userInitial = computed(() => {
    const name = props.userName.trim().length > 0 ? props.userName : sharedUserName.value;
    return name.trim().length > 0 ? name.trim().charAt(0).toUpperCase() : '?';
});
</script>

<template>
    <nav class="sticky top-0 z-10 flex items-center justify-between bg-equitab-navy px-6 py-4 lg:px-12">
        <div class="flex items-center gap-8">
            <Link href="/" class="flex shrink-0 items-center">
                <img src="/Images/EquitabLogoblanc.png" alt="Equitab" class="h-8 w-auto shrink-0" />
            </Link>

            <div class="flex items-center gap-5">
                <Link
                    href="/services"
                    class="text-sm font-medium"
                    :class="isActive('/services') ? 'text-white' : 'text-white/70 hover:text-white'"
                >
                    Services
                </Link>
                <Link
                    v-if="isAuthenticated"
                    href="/dashboard"
                    class="text-sm font-medium"
                    :class="isActive('/dashboard') ? 'text-white' : 'text-white/70 hover:text-white'"
                >
                    Tableau de bord
                </Link>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <div
                v-if="isAuthenticated"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-equitab-emerald text-sm font-medium text-white"
            >
                {{ userInitial }}
            </div>
            <template v-else>
                <Link
                    v-if="canLogin"
                    href="/login"
                    class="text-sm font-medium text-white/70 hover:text-white"
                >
                    Connexion
                </Link>
                <Link
                    v-if="canRegister"
                    href="/register"
                    class="rounded-md bg-equitab-emerald px-4 py-2 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
                >
                    S'inscrire
                </Link>
            </template>
        </div>

        <Toast ref="toastComponent" />
    </nav>
</template>
