<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import {
    ArrowLeft,
    ArrowUpRight,
    Eye,
    EyeOff,
    LockKeyhole,
    Mail,
    LoaderCircle,
} from "lucide-vue-next";
import EquitabWordmark from "@/Components/Experience/EquitabWordmark.vue";
import EquitabStory from "@/Components/Experience/EquitabStory.vue";
declare function route(name: string, params?: Record<string, string>): string;
defineProps<{ canResetPassword: boolean; status?: string }>();
const showPassword = ref(false);
const emailInput = ref<HTMLInputElement | null>(null);
const passwordInput = ref<HTMLInputElement | null>(null);
const form = useForm({ email: "", password: "", remember: false });
function submit() {
    form.post(route("login"), {
        onFinish: () => form.reset("password"),
        onError: (errors) => {
            if (errors.email) emailInput.value?.focus();
            else if (errors.password) passwordInput.value?.focus();
        },
    });
}
</script>
<template>
    <Head title="Connexion" />
    <div class="collection-login">
        <a href="#login-form" class="login-skip"
            >Aller au formulaire de connexion</a
        >
        <main class="login-panel">
            <div class="login-panel-top">
                <Link
                    href="/"
                    class="mobile-wordmark"
                    aria-label="Equitab — accueil"
                    ><EquitabWordmark /></Link
                ><Link href="/" class="login-back"
                    ><ArrowLeft :size="15" aria-hidden="true" /> Retour à
                    l’accueil</Link
                >
            </div>
            <div class="login-form-wrap">
                <p class="login-eyebrow"><span /> VOTRE ESPACE EQUITAB</p>
                <h1>Ravi de vous<br /><em>retrouver.</em></h1>
                <p class="login-intro">Les bonnes choses continuent ici.</p>
                <div v-if="status" role="status" class="login-status">
                    {{ status }}
                </div>
                <a
                    :href="
                        route('auth.social.redirect', { provider: 'google' })
                    "
                    class="google-login"
                >
                    <svg
                        width="18"
                        height="18"
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                    >
                        <path
                            fill="#4285F4"
                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                        />
                        <path
                            fill="#34A853"
                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                        />
                        <path
                            fill="#FBBC05"
                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                        />
                        <path
                            fill="#EA4335"
                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                        />
                    </svg>

                    Continuer avec Google</a
                >
                <div class="login-divider">
                    <span />ou avec votre courriel<span />
                </div>
                <form
                    id="login-form"
                    :aria-busy="form.processing"
                    @submit.prevent="submit"
                >
                    <div class="login-field">
                        <label for="login-email">Adresse courriel</label>
                        <div class="login-input">
                            <Mail :size="17" aria-hidden="true" /><input
                                ref="emailInput"
                                id="login-email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                inputmode="email"
                                autocapitalize="none"
                                :spellcheck="false"
                                required
                                placeholder="vous@exemple.com"
                                :aria-invalid="!!form.errors.email"
                                :aria-describedby="
                                    form.errors.email
                                        ? 'login-email-error'
                                        : undefined
                                "
                            />
                        </div>
                        <p
                            v-if="form.errors.email"
                            id="login-email-error"
                            role="alert"
                            class="login-error"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>
                    <div class="login-field">
                        <label for="login-password">Mot de passe</label>
                        <div class="login-input">
                            <LockKeyhole :size="17" aria-hidden="true" /><input
                                ref="passwordInput"
                                id="login-password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                placeholder="Votre mot de passe"
                                :aria-invalid="!!form.errors.password"
                                :aria-describedby="
                                    form.errors.password
                                        ? 'login-password-error'
                                        : undefined
                                "
                            /><button
                                type="button"
                                :aria-label="
                                    showPassword
                                        ? 'Masquer le mot de passe'
                                        : 'Afficher le mot de passe'
                                "
                                :aria-pressed="showPassword"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff
                                    v-if="showPassword"
                                    :size="17"
                                    aria-hidden="true"
                                /><Eye v-else :size="17" aria-hidden="true" />
                            </button>
                        </div>
                        <p
                            v-if="form.errors.password"
                            id="login-password-error"
                            role="alert"
                            class="login-error"
                        >
                            {{ form.errors.password }}
                        </p>
                    </div>
                    <div class="login-options">
                        <label
                            ><input
                                v-model="form.remember"
                                type="checkbox"
                            /><span>Rester connecté</span></label
                        ><Link v-if="canResetPassword" href="/forgot-password"
                            >Mot de passe oublié ?</Link
                        >
                    </div>
                    <button
                        class="login-submit"
                        type="submit"
                        :disabled="form.processing"
                    >
                        <span>{{
                            form.processing
                                ? "Connexion en cours…"
                                : "Se connecter"
                        }}</span
                        ><LoaderCircle
                            v-if="form.processing"
                            :size="18"
                            aria-hidden="true"
                        /><ArrowUpRight v-else :size="18" aria-hidden="true" />
                    </button>
                </form>
                <p class="login-register">
                    Pas encore de compte ?
                    <Link href="/register">Rejoignez-nous.</Link>
                </p>
            </div>
            <footer class="login-panel-bottom">
                <p>
                    Une question ?
                    <a href="mailto:support@equitab.ca">On est là pour vous.</a>
                </p>
                <div>
                    <Link href="/confidentialite">Confidentialité</Link
                    ><span>·</span><Link href="/conditions">Conditions</Link>
                </div>
            </footer>
        </main>
        <aside class="login-story-panel" aria-labelledby="login-story-title">
            <div class="login-story-nav">
                <Link href="/" aria-label="Equitab — accueil"
                    ><EquitabWordmark /></Link
                ><span>LES BONNES CHOSES SE PARTAGENT.</span>
            </div>
            <div class="login-story-heading">
                <p>MOINS CHER. ENCORE MIEUX ENSEMBLE.</p>
                <h2 id="login-story-title">
                    Moins de frais.<br />Plus de <em>vie.</em
                    ><span class="story-title-star" aria-hidden="true">✳</span>
                </h2>
                <p class="story-heading-description">
                    Vos services préférés. Des frais partagés.<br />Et un peu
                    plus pour tout le reste.
                </p>
            </div>
            <EquitabStory />
        </aside>
    </div>
</template>
<style scoped>
.collection-login {
    display: grid;
    grid-template-columns: 7fr 3fr;
    min-height: 100svh;
    color: #303b37;
    background: #f6f8f6;
    font-family: "Montserrat", sans-serif;
    -webkit-font-smoothing: antialiased;
}
.collection-login :where(a, button, input):focus-visible {
    outline: 3px solid #187a57;
    outline-offset: 4px;
}
.login-skip {
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 30;
    padding: 14px 20px;
    border-radius: 10px;
    background: white;
    transform: translateY(-180%);
    font-size: 13px;
}
.login-skip:focus {
    transform: none;
}
.login-story-panel {
    grid-column: 1;
    grid-row: 1;
    min-width: 0;
    padding: 36px clamp(30px, 4.4vw, 80px) 20px;
    background: #e7f3ed;
    border-right: 1px solid #d3e6dc;
}
.login-story-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}
.login-story-nav > span {
    font-size: 8px;
    letter-spacing: 0.14em;
    color: #65756d;
}
.login-story-heading {
    position: relative;
    padding-top: 42px;
}
.login-story-heading > p:first-child {
    font-size: 9px;
    letter-spacing: 0.13em;
    color: #187a57;
    font-weight: 600;
}
.login-story-heading h2 {
    position: relative;
    display: inline-block;
    font-size: clamp(45px, 4.4vw, 69px);
    font-weight: 500;
    letter-spacing: -0.065em;
    line-height: 1.06;
    margin-top: 17px;
}
.login-story-heading em {
    font-family: Georgia, serif;
    font-weight: 400;
}
.story-title-star {
    position: absolute;
    right: -73px;
    top: 19px;
    font-size: 50px;
    color: #35af7f;
    transform: rotate(12deg);
}
.story-heading-description {
    position: absolute;
    right: 0;
    bottom: 7px;
    font-size: 12px;
    line-height: 1.8;
    color: #65756d;
}
.login-panel {
    grid-column: 2;
    grid-row: 1;
    padding: 34px clamp(24px, 2.9vw, 54px) 26px;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 38px;
}
.login-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    color: #73796f;
    font-size: 10px;
}
.mobile-wordmark {
    display: none;
}
.login-form-wrap {
    width: 100%;
    max-width: 380px;
    margin-inline: auto;
}
.login-eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 8px;
    letter-spacing: 0.1em;
    color: #717b68;
    font-weight: 600;
}
.login-eyebrow > span {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #35af7f;
}
h1 {
    font-size: clamp(32px, 2.6vw, 42px);
    font-weight: 500;
    line-height: 1.08;
    letter-spacing: -0.055em;
    margin-top: 17px;
}
h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
}
.login-intro {
    font-size: 11px;
    color: #7a8075;
    margin-top: 14px;
    line-height: 1.7;
}
.google-login {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 49px;
    gap: 11px;
    margin-top: 28px;
    border: 1px solid #dce5df;
    border-radius: 12px;
    background: #ffffff70;
    font-size: 11px;
    font-weight: 500;
}
.google-login svg {
    flex: none;
}
.google-login:hover {
    background: white;
    border-color: #82bea4;
}
.login-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-block: 24px;
    color: #82897a;
    font-size: 9px;
}
.login-divider span {
    flex: 1;
    height: 1px;
    background: #dce5df;
}
.login-field {
    margin-top: 19px;
}
.login-field > label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    margin-bottom: 9px;
}
.login-input {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 49px;
    border: 1px solid #dce5df;
    border-radius: 12px;
    background: #fff;
}
.login-input > svg {
    flex: none;
    margin-left: 14px;
    color: #788f82;
}
.login-input input {
    display: block;
    width: 100%;
    min-width: 0;
    border: 0;
    background: transparent;
    font-size: 12px;
    height: 49px;
    padding: 12px;
    border-radius: 12px;
    box-shadow: none;
    color: #293128;
}
.login-input input::placeholder {
    color: #8a9182;
}
/* Preserve autofill without its disconnected blue browser surface. */
.login-input input:autofill {
    background: #fff;
    color: var(--color-eq-ink);
    box-shadow: 0 0 0 1000px #fff inset;
}
.login-input input:-webkit-autofill,
.login-input input:-webkit-autofill:hover,
.login-input input:-webkit-autofill:focus {
    -webkit-text-fill-color: var(--color-eq-ink);
    caret-color: var(--color-eq-ink);
    -webkit-box-shadow: 0 0 0 1000px #fff inset;
    box-shadow: 0 0 0 1000px #fff inset;
}
@media (forced-colors: active) {
    .login-input input:-webkit-autofill {
        -webkit-text-fill-color: CanvasText;
        -webkit-box-shadow: none;
        box-shadow: none;
    }
}
.login-input input[aria-invalid="true"] {
    outline: 1px solid #be5147;
}
.login-input:focus-within {
    border-color: #35af7f;
}
.login-input button {
    flex: none;
    width: 43px;
    height: 45px;
    display: grid;
    place-items: center;
    color: #657b6e;
    border-radius: 10px;
    cursor: pointer;
}
.login-options {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 4px 10px;
    margin-top: 13px;
    font-size: 9px;
}
.login-options label {
    display: flex;
    align-items: center;
    min-height: 40px;
    gap: 7px;
    color: #65756d;
    cursor: pointer;
}
.login-options input {
    width: 14px;
    height: 14px;
    accent-color: #187a57;
}
.login-options a {
    display: flex;
    align-items: center;
    min-height: 40px;
    text-underline-offset: 3px;
}
.login-options a:hover {
    text-decoration: underline;
}
.login-submit {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 51px;
    padding: 13px 18px;
    margin-top: 15px;
    background: #187a57;
    color: #ffffff;
    border: 1px solid #187a57;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.login-submit:hover {
    background: #126344;
}
.login-submit:disabled {
    opacity: 0.65;
    cursor: wait;
}
.login-register {
    font-size: 10px;
    text-align: center;
    color: #7c8374;
    margin-top: 26px;
    line-height: 1.8;
}
.login-register a {
    color: #187a57;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.login-panel-bottom {
    text-align: center;
    font-size: 9px;
    color: #7b8273;
    line-height: 1.8;
}
.login-panel-bottom a:hover {
    text-decoration: underline;
}
.login-panel-bottom p a {
    color: #187a57;
}
.login-panel-bottom > div {
    display: flex;
    justify-content: center;
    gap: 12px;
    margin-top: 12px;
}
.login-panel-bottom > div a {
    display: flex;
    align-items: center;
    min-height: 32px;
}
.login-panel-bottom > div > span {
    align-self: center;
}
.login-error {
    font-size: 11px;
    color: #a83a32;
    margin-top: 7px;
    line-height: 1.6;
}
.login-status {
    background: #e7f3ed;
    border: 1px solid #cbe6d9;
    padding: 12px;
    border-radius: 10px;
    font-size: 11px;
    line-height: 1.6;
    margin-top: 20px;
    overflow-wrap: anywhere;
}
@media (min-width: 1600px) {
    .login-story-panel {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .login-story-panel :deep(.equitable-story) {
        width: 100%;
        max-width: 1000px;
        margin-inline: auto;
    }
    .login-story-heading {
        padding-top: 36px;
    }
}
@media (max-width: 1300px) {
    .story-heading-description {
        font-size: 10px;
    }
    .story-title-star {
        right: -49px;
        font-size: 34px;
    }
    .login-story-panel {
        padding-inline: 32px;
    }
    .login-panel {
        padding-inline: 24px;
    }
}
@media (max-width: 1099px) {
    .collection-login {
        display: flex;
        flex-direction: column;
    }
    .login-panel {
        order: 0;
        padding: 22px 28px 24px;
        min-height: 100svh;
        gap: 38px;
    }
    .login-panel-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }
    .mobile-wordmark {
        display: block;
    }
    .login-back {
        font-size: 10px;
    }
    .login-form-wrap {
        max-width: 420px;
    }
    h1 {
        font-size: 43px;
    }
    .login-eyebrow {
        font-size: 9px;
    }
    .login-intro {
        font-size: 12px;
    }
    .google-login {
        font-size: 12px;
    }
    .login-input input {
        font-size: 16px;
    }
    .login-field > label {
        font-size: 12px;
    }
    .login-options {
        font-size: 11px;
    }
    .login-register {
        font-size: 11px;
    }
    .login-story-panel {
        order: 1;
        padding: 36px max(24px, calc((100% - 820px) / 2)) 24px;
        border: 0;
    }
    .login-story-nav {
        display: none;
    }
    .login-story-heading {
        padding-top: 0;
        margin-bottom: 20px;
    }
    .login-story-heading h2 {
        font-size: 54px;
    }
    .story-heading-description {
        font-size: 12px;
    }
    .login-panel-bottom {
        font-size: 10px;
    }
}
@media (max-width: 600px) {
    .login-panel {
        padding: 20px 22px 24px;
        gap: 34px;
    }
    .login-panel-top {
        gap: 12px;
    }
    .login-back {
        font-size: 0;
        width: 44px;
        height: 44px;
        justify-content: center;
        border: 1px solid #dce5df;
        border-radius: 50%;
    }
    .login-back svg {
        width: 18px;
        height: 18px;
    }
    .login-form-wrap {
        max-width: 390px;
    }
    h1 {
        font-size: 40px;
    }
    .login-options {
        font-size: 10px;
    }
    .login-panel-bottom {
        font-size: 9px;
    }
    .login-story-panel {
        padding: 32px 16px 24px;
    }
    .login-story-heading {
        padding-inline: 7px;
    }
    .login-story-heading h2 {
        font-size: 45px;
    }
    .story-heading-description {
        position: static;
        font-size: 11px;
        margin-top: 20px;
    }
    .story-title-star {
        position: static;
        display: inline-block;
        margin-left: 15px;
        vertical-align: middle;
        font-size: 32px;
    }
    .login-story-heading > p:first-child {
        font-size: 8px;
    }
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
