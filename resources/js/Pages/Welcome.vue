<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TypewriterText from '@/Components/TypewriterText.vue';
import ServiceCard from '@/Components/ServiceCard.vue';
import ScrollingCarousel from '@/Components/ScrollingCarousel.vue';
import NavbarWithSearch from '@/Components/NavbarWithSearch.vue';
import SearchBar from '@/Components/SearchBar.vue';
import HowItWorksColumn from '@/Components/HowItWorksColumn.vue';
import VideoPlaceholder from '@/Components/VideoPlaceholder.vue';
import Footer from '@/Components/Footer.vue';
import { Link } from '@inertiajs/vue3';
import { UserPlus, Send, CreditCard, PartyPopper, Plus, Share2, Wallet, BadgeDollarSign } from 'lucide-vue-next';

interface CatalogService {
    name: string;
    slug: string;
    pricePerMember: number;
    discountPercent: number;
}

interface OpenGroup {
    id: number;
    subscriptionName: string;
    subscriptionSlug: string;
    pricePerMember: number;
    currentMembers: number;
    maxMembers: number;
}

interface Props {
    canLogin: boolean;
    canRegister: boolean;
    isAuthenticated: boolean;
    catalogServices: CatalogService[];
    openGroups: OpenGroup[];
}

defineProps<Props>();

const heroWords: string[] = [
    'fiable',
    'sécurisé',
    'simple',
    'transparent',
];

const joinSteps = [
    { icon: UserPlus, title: 'Je choisis', description: 'Parmi des dizaines de services streaming, logiciels, presse.' },
    { icon: Send, title: 'Je paie ma part', description: 'Directement au propriétaire, via Stripe.' },
    { icon: CreditCard, title: "J'accède au service", description: 'Le propriétaire vous ajoute au groupe.' },
    { icon: PartyPopper, title: "J'en profite", description: 'Chaque mois, sans y penser.' },
];

const ownSteps = [
    { icon: Plus, title: 'Je crée mon groupe', description: 'Décrivez le service, le nombre de places et le prix.' },
    { icon: Share2, title: 'Je partage le lien', description: "Avec vos amis ou publiquement sur l'app." },
    { icon: Wallet, title: 'Je collecte les paiements', description: 'Stripe gère tout, automatiquement.' },
    { icon: BadgeDollarSign, title: 'Je reçois mon argent', description: 'Directement sur votre compte.' },
];
</script>

<template>
    <Head title="Equitab - Partagez vos abonnements en toute confiance" />

    <div class="min-h-screen bg-white">
        <NavbarWithSearch
            :can-login="canLogin"
            :can-register="canRegister"
            :is-authenticated="isAuthenticated"
        />

        <main class="flex flex-col items-center justify-center px-6 py-20 text-center">
            <h1 class="flex flex-wrap items-center justify-center gap-x-3 text-4xl font-semibold tracking-tight text-equitab-navy lg:text-6xl">
                <img src="/Images/EquitabLogo.svg" alt="Equitab" class="inline-block h-[0.85em] w-auto align-middle" />
                <span>est</span>
                <span class="text-equitab-emerald">
                    <TypewriterText :words="heroWords" />
                </span>
            </h1>

            <p class="mt-6 max-w-xl text-lg text-gray-600">
                Partagez vos abonnements Netflix, Disney+, et plus encore avec des
                paiements sécurisés directement entre membres.
            </p>

            <div class="mt-10 w-full">
                <SearchBar />
            </div>
        </main>

        <section class="py-8">
            <h2 class="mb-4 px-6 text-xl font-semibold text-equitab-navy lg:px-12">
                Services populaires
            </h2>
            <ScrollingCarousel :duration-seconds="60">
                <ServiceCard
                    v-for="service in catalogServices"
                    :key="service.slug"
                    v-bind="service"
                />
            </ScrollingCarousel>
            <div class="mt-8 flex justify-center">
                <Link
                    href="/services"
                    class="rounded-xl bg-equitab-navy px-6 py-3 text-sm font-medium text-white hover:bg-equitab-navy-light"
                >
                    Voir tous les services
                </Link>
            </div>
        </section>

        <section class="px-6 py-16 lg:px-12">
            <div class="mx-auto max-w-5xl">
                <p class="text-center text-sm font-medium text-gray-500">Comment ça marche ?</p>
                <h2 class="mt-2 text-center text-3xl font-semibold text-equitab-navy">
                    Partage d'abonnement
                </h2>

                <div class="mt-12 rounded-2xl border border-gray-100 p-6 lg:p-10">
                    <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-12">
                        <HowItWorksColumn
                            label="Participer"
                            accent-color="emerald"
                            :steps="joinSteps"
                        />
                        <VideoPlaceholder />
                    </div>
                </div>

                <div class="mt-8 rounded-2xl border border-gray-100 p-6 lg:p-10">
                    <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-12">
                        <VideoPlaceholder class="lg:order-1" />
                        <HowItWorksColumn
                            label="Proposer"
                            accent-color="navy"
                            :steps="ownSteps"
                            class="lg:order-2"
                        />
                    </div>
                </div>
            </div>
        </section>
    </div>
    <Footer />
</template>
