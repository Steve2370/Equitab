<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowLeft, ArrowUpRight } from "lucide-vue-next";
import EquitabWordmark from "@/Components/Experience/EquitabWordmark.vue";
import EquitabStory from "@/Components/Experience/EquitabStory.vue";
</script>
<template>
    <div class="eq-experience auth-collection">
        <main class="auth-form">
            <div class="auth-form-inner">
                <Link
                    href="/"
                    class="auth-mobile-brand"
                    aria-label="Equitab — accueil"
                    ><EquitabWordmark
                /></Link>
                <Link href="/" class="eq-link mb-8 !font-normal !text-eq-muted"
                    ><ArrowLeft :size="16" aria-hidden="true" /> Retour à
                    l’accueil</Link
                >
                <div class="eq-auth-content"><slot /></div>
                <p
                    class="mt-8 border-t border-eq-line pt-5 text-center text-xs text-eq-muted"
                >
                    Une question ?
                    <a
                        href="mailto:support@equitab.ca"
                        class="underline underline-offset-4"
                        >On est là pour vous.</a
                    >
                </p>
            </div>
        </main>
        <aside class="auth-story">
            <Link href="/" aria-label="Equitab — accueil"
                ><EquitabWordmark
            /></Link>
            <div class="auth-story-content">
                <p class="eq-eyebrow">LES BONNES CHOSES SE PARTAGENT</p>
                <h2>Un peu moins de frais.<br />Beaucoup plus de vie.</h2>
                <p>
                    Vos envies, vos groupes, votre part.<br />Tout commence
                    ensemble.
                </p>
                <EquitabStory />
            </div>
            <Link href="/charte" class="eq-link"
                >Notre charte de confiance
                <ArrowUpRight :size="16" aria-hidden="true"
            /></Link>
        </aside>
    </div>
</template>
<style scoped>
.auth-collection {
    display: grid;
    grid-template-columns: 3fr 2fr;
    min-height: 100dvh;
}
.auth-form {
    min-width: 0;
    grid-column: 2;
    grid-row: 1;
    padding: 48px 36px;
    display: grid;
    place-items: center;
}
.auth-form-inner {
    width: 100%;
    max-width: 420px;
}
.auth-mobile-brand {
    display: none;
}
.auth-story {
    grid-column: 1;
    grid-row: 1;
    padding: 46px 7%;
    background: #e7f3ed;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 45px;
    min-width: 0;
}
.auth-story-content > h2 {
    font-size: clamp(38px, 4vw, 64px);
    letter-spacing: -0.065em;
    line-height: 1.08;
    font-weight: 500;
    margin: 23px 0;
}
.auth-story-content > p:not(.eq-eyebrow) {
    font-size: 13px;
    line-height: 1.9;
    color: #65756d;
    margin-bottom: 25px;
}
.eq-auth-content :deep(input:not([type="checkbox"])) {
    min-height: 48px;
    background: white;
    border-color: #dce5df;
    border-radius: 12px;
}
.eq-auth-content :deep(button[type="submit"]) {
    min-height: 50px;
    border-radius: 28px;
    background: #187a57;
    color: #fff;
}
.eq-auth-content :deep(.text-equitab-emerald) {
    color: #187a57;
}
.eq-auth-content :deep(.text-gray-400) {
    color: #686b70;
}
@media (max-width: 1000px) {
    .auth-collection {
        display: flex;
        flex-direction: column;
    }
    .auth-form {
        padding: 32px 24px 44px;
    }
    .auth-mobile-brand {
        display: block;
        margin-bottom: 35px;
    }
    .auth-story {
        padding: 40px 24px;
    }
    .auth-story > a:first-child {
        display: none;
    }
    .auth-story-content {
        max-width: 640px;
        margin: auto;
        width: 100%;
    }
}
</style>
