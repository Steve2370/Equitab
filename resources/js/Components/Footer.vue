<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowUpRight } from "lucide-vue-next";
import EquitabWordmark from "./Experience/EquitabWordmark.vue";
const year = new Date().getFullYear();
</script>

<template>
    <footer class="eq-experience collection-footer border-t border-eq-line">
        <div class="eq-container pb-7 pt-12 md:pt-16">
            <div
                class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr]"
            >
                <div>
                    <Link href="/" aria-label="Equitab — accueil"
                        ><EquitabWordmark
                    /></Link>
                    <p class="mt-5 max-w-xs text-sm leading-6 text-white/75">
                        Les abonnements que vous aimez.<br />Le plaisir de les
                        partager.
                    </p>
                    <p class="mt-5 text-xs text-eq-lime">
                        Pensé au Canada. À partager ensemble.
                    </p>
                </div>
                <div>
                    <h2
                        class="text-xs font-semibold uppercase tracking-wider text-white/60"
                    >
                        Explorer
                    </h2>
                    <ul class="mt-5 space-y-3 text-sm">
                        <li>
                            <Link href="/services" class="hover:underline"
                                >Tous les services</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/dashboard/groups/create"
                                class="hover:underline"
                                >Partager un abonnement</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/#comment-ca-marche"
                                class="hover:underline"
                                >Comment ça marche</Link
                            >
                        </li>
                        <li>
                            <Link href="/dashboard" class="hover:underline"
                                >Mon espace</Link
                            >
                        </li>
                    </ul>
                </div>
                <div>
                    <h2
                        class="text-xs font-semibold uppercase tracking-wider text-white/60"
                    >
                        En confiance
                    </h2>
                    <ul class="mt-5 space-y-3 text-sm">
                        <li>
                            <Link href="/charte" class="hover:underline"
                                >Charte de confiance</Link
                            >
                        </li>
                        <li>
                            <Link href="/conditions" class="hover:underline"
                                >Conditions d’utilisation</Link
                            >
                        </li>
                        <li>
                            <Link
                                href="/confidentialite"
                                class="hover:underline"
                                >Confidentialité</Link
                            >
                        </li>
                        <li>
                            <a
                                href="mailto:privacy@equitab.ca"
                                class="hover:underline"
                                >Protection des données</a
                            >
                        </li>
                    </ul>
                </div>
                <div>
                    <h2
                        class="text-xs font-semibold uppercase tracking-wider text-white/60"
                    >
                        On garde le contact
                    </h2>
                    <a
                        href="mailto:support@equitab.ca"
                        class="mt-5 inline-flex items-center gap-2 text-sm hover:underline"
                        >Nous écrire
                        <ArrowUpRight :size="15" aria-hidden="true"
                    /></a>
                    <div class="mt-4 flex gap-5 text-sm text-white/75">
                        <a
                            href="https://x.com/LeonBeltran2"
                            aria-label="Equitab sur X"
                            class="hover:underline"
                            >X</a
                        ><a
                            href="https://www.linkedin.com/in/brice-steve-tchagam-youatchui-656aa1205/"
                            aria-label="Retrouver notre équipe sur LinkedIn"
                            class="hover:underline"
                            >LinkedIn</a
                        >
                    </div>
                    <a
                        href="mailto:legal@equitab.ca"
                        class="mt-4 block text-xs text-white/75 hover:underline"
                        >Contact légal</a
                    >
                </div>
            </div>
            <div
                class="mt-12 flex flex-wrap items-center justify-between gap-3 border-t border-white/20 pt-6 text-xs text-white/65"
            >
                <p>© {{ year }} Equitab Inc. Tous droits réservés.</p>
                <p>Frais partagés. Plaisirs multipliés.</p>
            </div>
        </div>
    </footer>
</template>
<style scoped>
.collection-footer :deep([class*="text-white/"]) {
    color: #686b70;
}
.collection-footer :deep(.text-eq-lime) {
    color: #465a37;
}
.collection-footer :deep(.border-white\/20) {
    border-color: #dededb;
}
</style>
