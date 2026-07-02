<script setup lang="ts">
import { Home, Users, Scale, ShieldCheck, CreditCard, UserCheck, MessageSquare, Flag, RefreshCw, Lock, MapPin } from 'lucide-vue-next';
import { Head } from '@inertiajs/vue3';
import NavbarWithSearch from '@/Components/NavbarWithSearch.vue';
import Footer from '@/Components/Footer.vue';
</script>

<template>
    <Head title="Charte de confiance — Equitab" />
    <div class="min-h-screen bg-gray-50">
        <NavbarWithSearch />

        <div class="mx-auto max-w-3xl px-6 py-16">
            <h1 class="text-3xl font-bold text-equitab-navy mb-2">Charte de confiance</h1>
            <p class="text-gray-400 text-sm mb-2">En vigueur depuis le 1er juillet 2026</p>
            <p class="text-gray-500 text-sm mb-10 leading-relaxed">
                La confiance est au cœur d'Equitab. Cette charte définit les engagements mutuels entre les propriétaires, les membres, et Equitab — pour que chaque expérience sur notre plateforme soit sécurisée, équitable et agréable.
            </p>

            <div class="space-y-10">

                <section>
                    <div class="flex items-center gap-3 mb-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-equitab-navy">
                            <Home class="h-5 w-5 text-white" />
                        </div>
                        <h2 class="text-xl font-semibold text-equitab-navy">Les propriétaires s'engagent à</h2>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-white p-6 space-y-3">
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Transmettre les identifiants d'accès dans les <strong>48 heures</strong> suivant chaque paiement.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Maintenir les identifiants exacts et à jour tout au long de la durée du partage.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Prévenir les membres en cas de changement de mot de passe, de plan, ou d'interruption du service.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">S'assurer que leur plan d'abonnement autorise le partage avec le nombre de membres du groupe.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Traiter les membres avec respect et répondre rapidement aux messages via le chat Equitab.</p>
                        </div>
                    </div>
                </section>

                <section>
                    <div class="flex items-center gap-3 mb-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-equitab-emerald">
                            <Users class="h-5 w-5 text-white" />
                        </div>
                        <h2 class="text-xl font-semibold text-equitab-navy">Les membres s'engagent à</h2>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-white p-6 space-y-3">
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Payer leur quote-part dans les délais prévus, sans tentative de contournement du système de paiement.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Ne pas partager les identifiants reçus avec des personnes extérieures au groupe.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Ne pas modifier les paramètres du compte partagé (mot de passe, plan, informations de facturation).</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Utiliser le service partagé dans le respect des conditions d'utilisation du fournisseur.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Signaler tout problème d'accès via les canaux prévus (chat ou dispute) plutôt que de réclamer directement auprès du fournisseur.</p>
                        </div>
                    </div>
                </section>

                <section>
                    <div class="flex items-center gap-3 mb-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500">
                            <Scale class="h-5 w-5 text-white" />
                        </div>
                        <h2 class="text-xl font-semibold text-equitab-navy">Equitab s'engage à</h2>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-white p-6 space-y-3">
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Protéger vos données personnelles conformément à la législation canadienne (PIPEDA).</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Sécuriser tous les paiements via Stripe — vos informations bancaires ne transitent jamais par nos serveurs.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Rembourser automatiquement tout membre dont les identifiants n'ont pas été fournis dans les 48 heures.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Proposer une médiation équitable et impartiale en cas de litige entre membres.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Améliorer continuellement la sécurité et les fonctionnalités de la plateforme.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            <p class="text-gray-600">Traiter chaque utilisateur avec équité, transparence et respect.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl bg-equitab-navy p-8 text-white">
                    <h2 class="text-xl font-semibold mb-6 text-center">Pourquoi faire confiance à Equitab ?</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <ShieldCheck class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Paiements 100% sécurisés par Stripe</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <CreditCard class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Cartes bancaires jamais stockées par Equitab</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <UserCheck class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Vérification d'identité des propriétaires</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <MessageSquare class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Messagerie sécurisée entre membres</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <Flag class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Ouverture de litige en un clic</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <RefreshCw class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Remboursement garanti sous 48h si accès non fourni</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <Lock class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Identifiants chiffrés AES-256</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-equitab-emerald/20">
                                <MapPin class="h-4 w-4 text-equitab-emerald" />
                            </div>
                            <p class="text-sm text-white/80 pt-1">Données hébergées au Canada</p>
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">Une question ?</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Notre équipe est disponible pour répondre à toutes vos questions à <a href="mailto:support@equitab.ca" class="text-equitab-emerald hover:underline">support@equitab.ca</a>. Nous nous engageons à vous répondre dans les 48 heures ouvrables.
                    </p>
                </section>
            </div>
        </div>
        <Footer />
    </div>
</template>
