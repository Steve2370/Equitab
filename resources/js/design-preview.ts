// Separate entry point: never used by the production application.
import "../css/app.css";
import { createApp, h, type DefineComponent } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { ZiggyVue, type Config } from "../../vendor/tightenco/ziggy";
import DesignPreviewNotice from "./Components/DesignPreviewNotice.vue";
import DirectionPreview from "./Components/Experience/DirectionPreview.vue";

const previewRoutes: Config = {
    url: window.location.origin,
    port: Number(window.location.port),
    defaults: {},
    routes: {
        login: { uri: "login", methods: ["GET", "POST"] },
        register: { uri: "register", methods: ["GET", "POST"] },
        "auth.social.redirect": {
            uri: "auth/{provider}/redirect",
            methods: ["GET"],
        },
    },
};
// Native validation and password toggles remain testable, but submission is
// intercepted before application handlers can send anything.
document.addEventListener(
    "submit",
    (event) => {
        const form = event.target;
        if (
            form instanceof HTMLFormElement &&
            form.getAttribute("role") === "search"
        )
            return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.alert(
            "Aperçu uniquement : aucune inscription ni transaction ne sera envoyée.",
        );
    },
    true,
);
router.on("before", (event) => {
    if (event.detail.visit.method !== "get") {
        event.preventDefault();
        window.alert("Cette action est désactivée dans l’aperçu.");
    }
});
const pages = import.meta.glob("./Pages/**/*.vue");
createInertiaApp({
    title: (title) => title + " — aperçu Equitab",
    resolve: async (name) => {
        if (name === "DirectionPreview")
            return DirectionPreview as unknown as DefineComponent;
        if (name === "DesignPreviewNotice")
            return DesignPreviewNotice as unknown as DefineComponent;
        const module = (await pages["./Pages/" + name + ".vue"]()) as {
            default: DefineComponent;
        };
        return module.default;
    },
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => [
                h(
                    "div",
                    {
                        class: "bg-[#deedb4] px-4 py-2 text-center text-[11px] font-medium text-[#183d33]",
                    },
                    [
                        "Aperçu de la refonte · Données et prix fictifs · Aucune transaction · ",
                        h(
                            "a",
                            {
                                href: "/dashboard",
                                class: "underline underline-offset-2",
                            },
                            "Voir mon espace",
                        ),
                    ],
                ),
                h(App, props),
            ],
        })
            .use(plugin)
            .use(ZiggyVue, previewRoutes)
            .mount(el);
    },
});
