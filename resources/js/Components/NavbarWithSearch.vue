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

const page = usePage<{ auth: { user: { name: string; avatar: string | null } | null } }>();

function isActive(href: string): boolean {
    return page.url.startsWith(href);
}

const user = computed(() => page.props.auth?.user);

const userInitial = computed(() => {
    const name = user.value?.name ?? props.userName;
    return name && name.trim().length > 0 ? name.trim().charAt(0).toUpperCase() : '?';
});
</script>

<template>
    <nav class="sticky top-0 z-10 flex items-center justify-between gap-3 whitespace-nowrap bg-equitab-navy px-4 py-3 sm:px-6 sm:py-4 lg:px-12">
        <div class="flex min-w-0 items-center gap-3 sm:gap-8">
            <Link href="/" class="flex shrink-0 items-center">
                <img src="/Images/EquitabLogoblanc.png" alt="Equitab" class="h-6 w-auto shrink-0 sm:h-8" />
            </Link>

            <div class="hidden items-center gap-5 sm:flex">
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

        <div class="flex shrink-0 items-center gap-2 sm:gap-4">
            <Link
                v-if="isAuthenticated"
                href="/dashboard"
                class="flex h-7 w-7 shrink-0 overflow-hidden rounded-full bg-equitab-emerald sm:h-8 sm:w-8"
            >
                <img
                    v-if="user?.avatar"
                    :src="user.avatar"
                    class="h-full w-full object-cover"
                    alt="Avatar"
                />
                <span
                    v-else
                    class="flex h-full w-full items-center justify-center text-sm font-medium text-white"
                >
                    {{ userInitial }}
                </span>
            </Link>
            <template v-else>
                <Link
                    v-if="canLogin"
                    href="/login"
                    class="text-xs font-medium text-white/70 hover:text-white sm:text-sm"
                >
                    Connexion
                </Link>
                <Link
                    v-if="canRegister"
                    href="/register"
                    class="rounded-md bg-equitab-emerald px-3 py-1.5 text-xs font-medium text-white hover:bg-equitab-emerald-dark sm:px-4 sm:py-2 sm:text-sm"
                >
                    S'inscrire
                </Link>
            </template>
        </div>

        <Toast ref="toastComponent" />
    </nav>
</template>
