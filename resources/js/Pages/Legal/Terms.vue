<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NavbarWithSearch from '@/Components/NavbarWithSearch.vue';
import Footer from '@/Components/Footer.vue';
</script>

<template>
    <Head title="Conditions d'utilisation — Equitab" />
    <div class="min-h-screen bg-gray-50">
        <NavbarWithSearch />

        <div class="mx-auto max-w-3xl px-6 py-16">
            <h1 class="text-3xl font-bold text-equitab-navy mb-2">Conditions d'utilisation</h1>
            <p class="text-gray-400 text-sm mb-2">Dernière mise à jour : 1er juillet 2026</p>
            <p class="text-gray-500 text-sm mb-10 leading-relaxed">
                Bienvenue sur Equitab. Avant d'utiliser notre plateforme, nous vous invitons à lire attentivement ces conditions. Elles ont été rédigées pour être claires et transparentes — parce que vous méritez de savoir exactement comment fonctionne Equitab.
            </p>

            <div class="space-y-10">

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">1. Qu'est-ce qu'Equitab ?</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Equitab est une plateforme canadienne de mise en relation qui permet à des particuliers de gérer et de répartir équitablement les coûts d'abonnements numériques multi-utilisateurs. Equitab n'est <strong>pas</strong> un fournisseur d'abonnements et n'est en aucun cas affilié à Netflix, Spotify, Disney+, ou toute autre plateforme tierce.
                    </p>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Notre rôle est exclusivement celui d'un <strong>intermédiaire technique</strong> : nous facilitons la gestion des paiements entre membres d'un groupe, de manière sécurisée et transparente.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">
                        2. Le partage d'abonnement est-il autorisé ?
                    </h2>

                    <p class="text-gray-600 leading-relaxed">
                        Le partage d'un abonnement est possible lorsque le fournisseur concerné l'autorise dans ses conditions d'utilisation. De nombreux services proposent des offres multi-utilisateurs ou familiales conçues pour être utilisées par plusieurs personnes.
                    </p>

                    <p class="text-gray-600 leading-relaxed mt-3">
                        Avant de créer un groupe, nous invitons chaque propriétaire à vérifier que son abonnement permet le type de partage qu'il souhaite proposer. Equitab agit comme une plateforme de mise en relation et de gestion des paiements entre utilisateurs, sans être affiliée aux fournisseurs d'abonnements proposés sur la plateforme.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">3. Responsabilités des utilisateurs</h2>
                    <p class="text-gray-600 leading-relaxed mb-3">En utilisant Equitab, vous vous engagez à :</p>
                    <ul class="space-y-2 text-gray-600">
                        <li class="flex items-start gap-2">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            Fournir des informations exactes et à jour lors de votre inscription.
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            Vérifier que le partage de votre abonnement est conforme aux conditions du fournisseur.
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            Ne pas utiliser la plateforme à des fins frauduleuses ou illégales.
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-equitab-emerald font-bold mt-0.5">✓</span>
                            Respecter les autres membres de la communauté Equitab.
                        </li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">4. Vérification d'identité</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Pour créer un groupe et partager un abonnement, une vérification d'identité est obligatoire. Cette vérification est réalisée par <strong>Stripe Identity</strong>, un service tiers certifié. Equitab ne stocke aucun document d'identité sur ses serveurs.
                    </p>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Nous recommandons aux membres de privilégier les groupes dont le propriétaire possède le <strong>badge Vérifié</strong> c'est votre garantie que l'identité du propriétaire a été confirmée.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">5. Paiements et commission</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Tous les paiements sur Equitab sont traités exclusivement par <strong>Stripe</strong>, l'un des leaders mondiaux des paiements en ligne. Vos informations bancaires ne transitent jamais par les serveurs d'Equitab et ne sont jamais stockées par nous.
                    </p>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Equitab perçoit une commission sur chaque transaction facilitée via la plateforme. Cette commission est déduite automatiquement au moment du paiement et est clairement indiquée avant toute transaction. Les fonds sont transférés directement au propriétaire du groupe — Equitab ne détient jamais les fonds des utilisateurs.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">6. Garantie de remboursement</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Si les identifiants d'accès ne sont pas transmis dans les <strong>48 heures</strong> suivant le paiement, un remboursement automatique est déclenché. Vous pouvez également ouvrir un litige manuellement depuis votre historique de paiements si vous rencontrez un problème d'accès.
                    </p>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Equitab s'engage à traiter chaque litige avec équité et dans les meilleurs délais. Notre équipe examine chaque situation individuellement avant de prendre une décision.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">7. Identifiants et sécurité</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Les identifiants de connexion partagés sur Equitab sont chiffrés avec le standard <strong>AES-256</strong>. Ils sont uniquement accessibles aux membres actifs du groupe concerné et sont inaccessibles à l'équipe Equitab.
                    </p>
                    <p class="text-gray-600 leading-relaxed mt-3">
                        Le propriétaire est seul responsable de l'exactitude et de la validité des identifiants fournis. Les membres s'engagent à ne pas partager ces identifiants en dehors du groupe et à ne pas en modifier les paramètres.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">8. Suspension et résiliation</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Equitab se réserve le droit de suspendre ou de supprimer tout compte en cas de :
                    </p>
                    <ul class="mt-3 space-y-2 text-gray-600">
                        <li class="flex items-start gap-2"><span class="text-red-400 font-bold mt-0.5">✕</span> Fraude ou tentative de fraude</li>
                        <li class="flex items-start gap-2"><span class="text-red-400 font-bold mt-0.5">✕</span> Fourniture de faux documents ou de faux identifiants</li>
                        <li class="flex items-start gap-2"><span class="text-red-400 font-bold mt-0.5">✕</span> Comportement abusif envers d'autres membres</li>
                        <li class="flex items-start gap-2"><span class="text-red-400 font-bold mt-0.5">✕</span> Violation répétée des présentes conditions</li>
                        <li class="flex items-start gap-2"><span class="text-red-400 font-bold mt-0.5">✕</span> Utilisation de la plateforme à des fins illégales</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">9. Limitation de responsabilité</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Equitab agit exclusivement comme intermédiaire technique. Nous ne garantissons pas la disponibilité permanente des abonnements proposés par les utilisateurs, ni la conformité de ces abonnements aux conditions des fournisseurs tiers. Equitab ne peut être tenu responsable des interruptions de service des plateformes tierces.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">10. Modification des conditions</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Equitab se réserve le droit de modifier ces conditions à tout moment. En cas de modification substantielle, nous vous en informerons par email avec un préavis raisonnable. La poursuite de l'utilisation de la plateforme après notification vaut acceptation des nouvelles conditions.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">11. Droit applicable</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Les présentes conditions sont régies par le droit canadien. Tout litige sera soumis à la juridiction des tribunaux compétents de la province d'Ontario, Canada.
                    </p>
                    <p class="text-gray-500 text-sm mt-4 p-4 bg-amber-50 rounded-xl border border-amber-100">
                        Ces conditions sont fournies à titre informatif. Elles ne remplacent pas un avis juridique professionnel. Nous recommandons de faire relire ces documents par un juriste spécialisé en droit du numérique au Canada avant tout lancement commercial.
                    </p>
                </section>

                <section>
                    <h2 class="text-xl font-semibold text-equitab-navy mb-3">12. Contact</h2>
                    <p class="text-gray-600 leading-relaxed">
                        Pour toute question relative aux présentes conditions, notre équipe est disponible à <a href="mailto:legal@equitab.ca" class="text-equitab-emerald hover:underline">legal@equitab.ca</a>.
                    </p>
                </section>
            </div>
        </div>
        <Footer />
    </div>
</template>
