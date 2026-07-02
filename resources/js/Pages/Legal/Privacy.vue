<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NavbarWithSearch from '@/Components/NavbarWithSearch.vue';
import Footer from '@/Components/Footer.vue';
</script>

<template>
    <Head title="Politique de confidentialité — Equitab" />
    <div class="min-h-screen bg-gray-50">
        <NavbarWithSearch />

        <div class="mx-auto max-w-3xl px-6 py-16">
            <h1 class="text-3xl font-bold text-equitab-navy mb-2">Politique de confidentialité</h1>
            <p class="text-gray-400 text-sm mb-2">Dernière mise à jour : 1er juillet 2026</p>
            <p class="text-gray-500 text-sm mb-10 leading-relaxed">
                Chez Equitab, la protection de vos données personnelles est une priorité. Cette politique explique de façon transparente quelles données nous collectons, pourquoi, et comment nous les protégeons.
            </p>

            <div class="space-y-10">

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">1. Données que nous collectons</h2>
                    <p class="text-gray-600 leading-relaxed mb-3">Nous collectons uniquement les données nécessaires au bon fonctionnement d'Equitab :</p>
                    <ul class="space-y-2 text-gray-600">
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Nom et prénom</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Adresse courriel</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Photo de profil (optionnelle)</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Documents de vérification d'identité (traités par Stripe Identity)</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Historique des groupes et des transactions</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Préférences de notification</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">2. Ce que nous ne stockons jamais</h2>
                    <div class="rounded-xl bg-equitab-emerald/5 border border-equitab-emerald/20 p-5">
                        <ul class="space-y-2 text-gray-600">
                            <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">✓</span> Numéros de carte bancaire</li>
                            <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">✓</span> Codes CVV</li>
                            <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">✓</span> Coordonnées bancaires</li>
                        </ul>
                        <p class="text-sm text-gray-500 mt-3">Toutes les informations de paiement sont gérées exclusivement par <strong>Stripe</strong>, certifié PCI DSS niveau 1 — le standard de sécurité le plus élevé dans l'industrie des paiements.</p>
                    </div>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">3. Utilisation de vos données</h2>
                    <p class="text-gray-600 leading-relaxed mb-3">Vos données sont utilisées exclusivement pour :</p>
                    <ul class="space-y-2 text-gray-600">
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Fournir et améliorer le service Equitab</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Vérifier votre identité et prévenir la fraude</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Traiter vos paiements et vous envoyer les confirmations</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Vous envoyer des notifications liées à votre compte (si activées)</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> Respecter nos obligations légales</li>
                    </ul>
                    <p class="text-gray-600 leading-relaxed mt-3 font-medium">
                        Nous ne vendons jamais vos données à des tiers. Point final.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">4. Stockage et sécurité</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Vos données sont stockées sur des serveurs sécurisés situés <strong>au Canada</strong>, dans le respect de la législation canadienne sur la protection des données personnelles (PIPEDA).
                    </p>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Toutes les communications entre votre navigateur et nos serveurs sont chiffrées via <strong>HTTPS/TLS</strong>. Les identifiants de connexion partagés sur la plateforme sont chiffrés avec <strong>AES-256</strong> et ne sont jamais accessibles à l'équipe Equitab.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">5. Cookies</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Equitab utilise uniquement des cookies essentiels au fonctionnement de la plateforme (session utilisateur, sécurité CSRF). Nous n'utilisons pas de cookies publicitaires ou de suivi comportemental.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">6. Vos droits (PIPEDA)</h2>
                    <p class="text-gray-600 leading-relaxed mb-3">
                        Conformément à la Loi sur la protection des renseignements personnels et les documents électroniques (PIPEDA), vous disposez des droits suivants :
                    </p>
                    <ul class="space-y-2 text-gray-600">
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> <strong>Accès</strong>: consulter les données que nous détenons sur vous</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> <strong>Rectification</strong>: corriger des informations inexactes</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> <strong>Suppression</strong>: demander l'effacement de vos données</li>
                        <li class="flex items-start gap-2"><span class="text-equitab-emerald font-bold mt-0.5">•</span> <strong>Portabilité</strong>: recevoir vos données dans un format lisible</li>
                    </ul>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Pour exercer ces droits, contactez-nous à <a href="mailto:privacy@equitab.ca" class="text-equitab-emerald hover:underline">privacy@equitab.ca</a>.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">7. Suppression de compte</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Vous pouvez supprimer votre compte à tout moment depuis vos préférences. Vos données personnelles seront effacées dans un délai de <strong>30 jours</strong>, à l'exception des données requises par la loi (historique de transactions, obligations fiscales).
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">8. Contact</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Pour toute question relative à la protection de vos données, notre responsable de la confidentialité est joignable à <a href="mailto:privacy@equitab.ca" class="text-equitab-emerald hover:underline">privacy@equitab.ca</a>.
                    </p>
                </section>
            </div>
        </div>
        <Footer />
    </div>
</template>
