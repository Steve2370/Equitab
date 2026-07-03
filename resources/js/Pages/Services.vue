<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Search } from 'lucide-vue-next';
import { getBrandGradient } from '@/config/brandGradients';
import Footer from '@/Components/Footer.vue';
import NavbarWithSearch from '@/Components/NavbarWithSearch.vue';

interface Subscription {
    id: number;
    name: string;
    slug: string;
    monthly_price: number;
    max_members: number;
}

interface Category {
    id: number;
    name: string;
    subscriptions: Subscription[];
}

interface Props {
    categories: Category[];
}

const props = defineProps<Props>();

const activeCategory = ref<number | null>(null);
const urlParams = new URLSearchParams(window.location.search);
const search = ref(urlParams.get('search') ?? '');

const filteredCategories = computed(() => {
    return props.categories
        .map(cat => ({
            ...cat,
            subscriptions: cat.subscriptions.filter(s =>
                s.name.toLowerCase().includes(search.value.toLowerCase())
            ),
        }))
        .filter(cat =>
            (activeCategory.value === null || cat.id === activeCategory.value) &&
            cat.subscriptions.length > 0
        );
});

const totalServices = computed(() =>
    props.categories.reduce((sum, cat) => sum + cat.subscriptions.length, 0)
);

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(cents / 100);
}
</script>

<template>
    <Head title="Tous les services - Equitab" />

    <div class="min-h-screen bg-gray-50">
        <NavbarWithSearch />

        <div class="bg-equitab-navy py-12 px-6">
            <div class="mx-auto max-w-4xl text-center">
                <h1 class="text-3xl font-bold text-white">Tous les services</h1>
                <p class="mt-2 text-white/60">{{ totalServices }} abonnements disponibles au partage sur Equitab</p>

                <div class="relative mt-6 max-w-md mx-auto">
                    <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Rechercher un service..."
                        class="w-full rounded-xl border border-white/10 bg-white/10 py-3 pl-11 pr-4 text-sm text-white placeholder-white/40 focus:border-equitab-emerald focus:outline-none"
                    />
                </div>
            </div>
        </div>

        <div class="mx-auto max-w-6xl px-6 py-10">

            <div class="flex flex-wrap gap-2 mb-8">
                <button
                    @click="activeCategory = null"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
                    :class="activeCategory === null
                        ? 'bg-equitab-navy text-white'
                        : 'bg-white border border-gray-200 text-gray-600 hover:border-equitab-navy'"
                >
                    Tous
                </button>
                <button
                    v-for="cat in categories"
                    :key="cat.id"
                    @click="activeCategory = cat.id"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
                    :class="activeCategory === cat.id
                        ? 'bg-equitab-navy text-white'
                        : 'bg-white border border-gray-200 text-gray-600 hover:border-equitab-navy'"
                >
                    {{ cat.name }}
                </button>
            </div>

            <div class="space-y-10">
                <div v-for="cat in filteredCategories" :key="cat.id">
                    <h2 class="text-lg font-semibold text-equitab-navy mb-4">{{ cat.name }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <Link
                            v-for="sub in cat.subscriptions"
                            :key="sub.id"
                            :href="`/groups/service/${sub.slug}`"
                            class="group rounded-xl overflow-hidden border border-gray-100 bg-white hover:shadow-md transition-all hover:-translate-y-0.5"
                        >
                            <div
                                class="h-2 w-full"
                                :style="{ background: `linear-gradient(135deg, ${getBrandGradient(sub.slug).from}, ${getBrandGradient(sub.slug).to})` }"
                            />

                            <div class="p-4">
                                <div class="flex items-center gap-3 mb-3">
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg font-bold text-white"
                                        :style="{ background: `linear-gradient(135deg, ${getBrandGradient(sub.slug).from}, ${getBrandGradient(sub.slug).to})` }"
                                    >
                                        {{ sub.name.charAt(0) }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-equitab-navy">{{ sub.name }}</p>
                                        <p class="text-xs text-gray-400">{{ sub.max_members }} membres max</p>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-xs text-gray-400">À partir de</p>
                                        <p class="font-bold text-equitab-navy">
                                            {{ formatPrice(Math.round(sub.monthly_price / sub.max_members)) }}
                                            <span class="text-xs font-normal text-gray-400">/ mois</span>
                                        </p>
                                    </div>
                                    <span class="text-xs font-medium text-equitab-emerald group-hover:underline">
                                        Voir les groupes →
                                    </span>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <div v-if="filteredCategories.length === 0" class="text-center py-16 text-gray-400">
                    Aucun service trouvé pour "{{ search }}"
                </div>
            </div>
        </div>
    </div>
    <Footer />
</template>
