<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <footer class="bg-equitab-navy text-white">
        <div class="mx-auto max-w-7xl px-6 py-14">
            <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">

                <div>
                    <Link href="/" class="text-xl font-bold text-white">Equitab</Link>
                    <p class="mt-3 text-sm text-white/50 leading-relaxed">
                        Plateforme de partage d'abonnements numériques. Économisez sur Netflix, Spotify, et plus encore.
                    </p>
                    <p class="mt-4 text-xs text-white/30">Made in Canada</p>

                    <div class="mt-5 flex items-center gap-3">
                        <a href="#" class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 hover:bg-white/20 transition-colors">
                            <svg class="h-4 w-4 fill-white" viewBox="0 0 24 24"><path d="M22.46 6c-.77.35-1.6.58-2.46.69.88-.53 1.56-1.37 1.88-2.38-.83.5-1.75.85-2.72 1.05C18.37 4.5 17.26 4 16 4c-2.35 0-4.27 1.92-4.27 4.29 0 .34.04.67.11.98C8.28 9.09 5.11 7.38 3 4.79c-.37.63-.58 1.37-.58 2.15 0 1.49.75 2.81 1.91 3.56-.71 0-1.37-.2-1.95-.5v.03c0 2.08 1.48 3.82 3.44 4.21a4.22 4.22 0 0 1-1.93.07 4.28 4.28 0 0 0 4 2.98 8.521 8.521 0 0 1-5.33 1.84c-.34 0-.68-.02-1.02-.06C3.44 20.29 5.7 21 8.12 21 16 21 20.33 14.46 20.33 8.79c0-.19 0-.37-.01-.56.84-.6 1.56-1.36 2.14-2.23z"/></svg>
                        </a>
                        <a href="#" class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 hover:bg-white/20 transition-colors">
                            <svg class="h-4 w-4 fill-white" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                        </a>
                        <a href="#" class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 hover:bg-white/20 transition-colors">
                            <svg class="h-4 w-4 fill-white" viewBox="0 0 24 24"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/></svg>
                        </a>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-white mb-4">Services populaires</h3>
                    <ul class="space-y-2.5">
                        <li><Link href="/groups/service/netflix" class="text-sm text-white/50 hover:text-white transition-colors">Partager Netflix</Link></li>
                        <li><Link href="/groups/service/spotify" class="text-sm text-white/50 hover:text-white transition-colors">Partager Spotify</Link></li>
                        <li><Link href="/groups/service/disney" class="text-sm text-white/50 hover:text-white transition-colors">Partager Disney+</Link></li>
                        <li><Link href="/groups/service/youtube-premium" class="text-sm text-white/50 hover:text-white transition-colors">Partager YouTube Premium</Link></li>
                        <li><Link href="/groups/service/apple-music" class="text-sm text-white/50 hover:text-white transition-colors">Partager Apple Music</Link></li>
                        <li><Link href="/services" class="text-sm text-equitab-emerald hover:text-equitab-emerald/80 transition-colors">Voir tous les services →</Link></li>
                        <li><Link href="/charte" class="text-sm text-white/50 hover:text-white transition-colors">Charte de confiance</Link></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-white mb-4">Equitab</h3>
                    <ul class="space-y-2.5">
                        <li><Link href="/register" class="text-sm text-white/50 hover:text-white transition-colors">Créer un compte</Link></li>
                        <li><Link href="/login" class="text-sm text-white/50 hover:text-white transition-colors">Se connecter</Link></li>
                        <li><Link href="/services" class="text-sm text-white/50 hover:text-white transition-colors">Tous les services</Link></li>
                        <li><Link href="/login" class="text-sm text-white/50 hover:text-white transition-colors">Mon espace</Link></li>
                        <li><a href="mailto:support@equitab.ca" class="text-sm text-white/50 hover:text-white transition-colors">Nous contacter</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-white mb-4">Légal</h3>
                    <ul class="space-y-2.5">
                        <li><Link href="/conditions" class="text-sm text-white/50 hover:text-white transition-colors">Conditions d'utilisation</Link></li>
                        <li><Link href="/confidentialite" class="text-sm text-white/50 hover:text-white transition-colors">Politique de confidentialité</Link></li>
                        <li><a href="mailto:legal@equitab.ca" class="text-sm text-white/50 hover:text-white transition-colors">Contact légal</a></li>
                        <li><a href="mailto:privacy@equitab.ca" class="text-sm text-white/50 hover:text-white transition-colors">Protection des données</a></li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 border-t border-white/10 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-xs text-white/30">
                    © 2026 Equitab Inc. Tous droits réservés. Plateforme de partage d'abonnements.
                </p>
                <div class="flex items-center gap-6">
                    <Link href="/conditions" class="text-xs text-white/30 hover:text-white/60">CGU</Link>
                    <Link href="/confidentialite" class="text-xs text-white/30 hover:text-white/60">Confidentialité</Link>
                    <a href="mailto:support@equitab.ca" class="text-xs text-white/30 hover:text-white/60">Support</a>
                </div>
            </div>
        </div>
    </footer>
</template>
