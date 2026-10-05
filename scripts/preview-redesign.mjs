// Local-only visual preview. No Laravel bootstrap, database, secrets or payment SDK.
// Run: npm run preview:design
import { createServer } from "node:http";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import { createServer as createViteServer } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";

const root = fileURLToPath(new URL("../", import.meta.url));
const port = Number(process.env.EQUITAB_PREVIEW_PORT || 4173);
const origin = "http://127.0.0.1:" + port;
const categories = [
    {
        id: 1,
        name: "Films et séries",
        subscriptions: [
            {
                id: 1,
                name: "Netflix",
                slug: "netflix",
                monthly_price: 2400,
                max_members: 4,
            },
            {
                id: 2,
                name: "Disney+",
                slug: "disney",
                monthly_price: 1600,
                max_members: 4,
            },
            {
                id: 3,
                name: "YouTube Premium",
                slug: "youtube-premium",
                monthly_price: 2400,
                max_members: 6,
            },
        ],
    },
    {
        id: 2,
        name: "Musique",
        subscriptions: [
            {
                id: 4,
                name: "Spotify",
                slug: "spotify",
                monthly_price: 1800,
                max_members: 6,
            },
            {
                id: 5,
                name: "Apple Music",
                slug: "apple-music",
                monthly_price: 1800,
                max_members: 6,
            },
        ],
    },
    {
        id: 3,
        name: "Outils et quotidien",
        subscriptions: [
            {
                id: 6,
                name: "Microsoft 365",
                slug: "microsoft-365",
                monthly_price: 1200,
                max_members: 6,
            },
            {
                id: 7,
                name: "Google One",
                slug: "google-one",
                monthly_price: 1200,
                max_members: 6,
            },
        ],
    },
];
// Extra, explicitly fictional fixtures to inspect every artwork family.
categories.push(
    {
        id: 4,
        name: "Jeux",
        subscriptions: [
            {
                id: 8,
                name: "Xbox Game Pass",
                slug: "xbox-game-pass",
                monthly_price: 1600,
                max_members: 4,
            },
            {
                id: 9,
                name: "Nintendo",
                slug: "nintendo",
                monthly_price: 2400,
                max_members: 4,
            },
        ],
    },
    {
        id: 5,
        name: "Sécurité",
        subscriptions: [
            {
                id: 10,
                name: "NordVPN",
                slug: "nordvpn",
                monthly_price: 1800,
                max_members: 6,
            },
        ],
    },
    {
        id: 6,
        name: "Éducation et lecture",
        subscriptions: [
            {
                id: 11,
                name: "Duolingo",
                slug: "duolingo",
                monthly_price: 1200,
                max_members: 6,
            },
            {
                id: 12,
                name: "Readly",
                slug: "readly",
                monthly_price: 1000,
                max_members: 5,
            },
        ],
    },
);
const services = categories.flatMap((category) => category.subscriptions);
const demoUser = {
    id: 1,
    name: "Camille Démo",
    email: "camille@example.test",
    avatar: null,
    identity_status: "unverified",
};
const featuredServices = ["netflix", "spotify", "disney"].map((slug) =>
    services.find((s) => s.slug === slug),
);
const demoGroups = featuredServices.map((s, index) => ({
    id: index + 1,
    subscriptionName: s.name,
    subscriptionSlug: s.slug,
    ownerName: ["Camille", "Alex", "Sam"][index],
    pricePerMember: [600, 300, 400][index],
    currentMembers: [3, 4, 4][index],
    maxMembers: s.max_members,
}));
const profileUser = {
    ...demoUser,
    phone: null,
    address: null,
    city: "Montréal",
    province: "QC",
    postal_code: null,
    stripe_connect_status: "not_started",
    trust_score: null,
    completed_payments: 3,
};
const preferenceUser = {
    ...demoUser,
    username: "camille_demo",
    locale: "fr-CA",
    currency: "CAD",
    timezone: "America/Toronto",
    notif_member_joined: true,
    notif_payment_received: true,
    notif_renewal_reminder: true,
    notif_payment_failed: true,
    show_real_name: false,
    allow_direct_contact: true,
};
const vite = await createViteServer({
    configFile: false,
    root,
    envFile: false,
    plugins: [
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
    ],
    resolve: { alias: { "@": resolve(root, "resources/js") } },
    server: {
        host: "127.0.0.1",
        middlewareMode: true,
        hmr: { host: "127.0.0.1", port: port + 1 },
        fs: { allow: [root] },
    },
    appType: "custom",
});
function pageFor(url) {
    const empty = url.searchParams.has("empty");
    const signedIn =
        url.pathname.startsWith("/dashboard") ||
        url.searchParams.has("signedin");
    const props = {
        auth: { user: signedIn ? demoUser : null },
        isAdmin: false,
        errors: {},
        canLogin: true,
        canRegister: true,
        isAuthenticated: signedIn,
    };
    let component = "DesignPreviewNotice";
    if (url.pathname === "/direction") {
        component = "DirectionPreview";
    } else if (url.pathname === "/") {
        component = "Welcome";
        Object.assign(props, {
            catalogServices: empty
                ? []
                : services.map((service) => ({
                      name: service.name,
                      slug: service.slug,
                      pricePerMember: service.monthly_price / 100,
                      discountPercent: 50,
                  })),
            openGroups:
                empty || url.searchParams.has("catalogonly") ? [] : demoGroups,
        });
    } else if (url.pathname === "/services") {
        component = "Services";
        Object.assign(props, {
            categories: empty
                ? []
                : url.searchParams.has("edge")
                  ? [
                        {
                            id: 99,
                            name: "Nouveaux univers",
                            subscriptions: [
                                {
                                    id: 99,
                                    name: "Un service avec un nom particulièrement long à découvrir ensemble",
                                    slug: "service-inconnu",
                                    monthly_price: null,
                                    max_members: null,
                                },
                                {
                                    id: 100,
                                    name: "Capacité à confirmer",
                                    slug: "capacite-inconnue",
                                    monthly_price: 1200,
                                    max_members: 0,
                                },
                            ],
                        },
                    ]
                  : categories,
        });
    } else if (url.pathname.startsWith("/groups/service/")) {
        component = "ServiceGroups";
        const service =
            services.find(
                (service) => service.slug === url.pathname.split("/").pop(),
            ) || services[0];
        Object.assign(props, {
            subscription: service,
            groups: empty
                ? []
                : ["Camille", "Alex", "Sam"].map((name, index) => ({
                      id: index + 1,
                      subscriptionName: service.name,
                      description:
                          "Groupe de démonstration pour examiner les détails et les places disponibles.",
                      ownerName: name,
                      ownerIdentityStatus:
                          index === 1 ? "unverified" : "verified",
                      ownerActiveGroupsCount: index + 1,
                      ownerTrustScore: index === 1 ? null : 85,
                      tier: "standard",
                      pricePerMember: Math.round(
                          service.monthly_price / service.max_members,
                      ),
                      spotsAvailable: index === 2 ? 0 : index + 1,
                      maxMembers: service.max_members,
                      createdAt: "1 oct. 2026",
                  })),
        });
    } else if (url.pathname === "/login") {
        component = "Auth/Login";
        Object.assign(props, {
            canResetPassword: !url.searchParams.has("noreset"),
            status: url.searchParams.has("status")
                ? "Message de démonstration : votre mot de passe a été réinitialisé. Vous pouvez vous connecter."
                : undefined,
        });
    } else if (url.pathname === "/register") {
        component = "Auth/Register";
    } else if (url.pathname === "/dashboard") {
        component = "Dashboard/Index";
        const populated = !empty;
        Object.assign(props, {
            userName: demoUser.name,
            totalSavings: populated ? 37 : 0,
            monthlySpend: populated ? 15 : 0,
            activeSubscriptionsCount: populated ? 3 : 0,
            upcomingPayments: populated
                ? [
                      {
                          id: 1,
                          groupName: "Spotify — groupe de démonstration",
                          amount: 300,
                          status: "pending",
                          paidAt: null,
                          dueDate: "12 oct. 2026",
                      },
                  ]
                : [],
        });
    } else if (url.pathname === "/dashboard/subscriptions") {
        component = "Dashboard/Subscriptions";
        Object.assign(props, {
            joinedSubscriptions: empty
                ? []
                : demoGroups.map((g) => ({
                      ...g,
                      joinedAt: "01/10/2026",
                      status: "active",
                      spotsLeft: g.maxMembers - g.currentMembers,
                  })),
            ownedSubscriptions: empty
                ? []
                : demoGroups
                      .slice(0, 2)
                      .map((g) => ({
                          ...g,
                          membersCount: g.currentMembers,
                          status: "open",
                          renewalDate: "1 nov. 2026",
                          inviteLink: origin + "/invite/demo",
                      })),
        });
    } else if (url.pathname === "/dashboard/payments") {
        component = "Dashboard/Payments";
        Object.assign(props, {
            payments: {
                data: empty
                    ? []
                    : demoGroups.map((g, index) => ({
                          id: g.id,
                          groupName: g.subscriptionName,
                          amount: g.pricePerMember,
                          status: ["completed", "pending", "failed"][index],
                          paidAt: index === 0 ? "1 oct. 2026" : null,
                          dueDate: "1 oct. 2026",
                      })),
                current_page: 1,
                last_page: 1,
                total: empty ? 0 : 3,
            },
        });
    } else if (url.pathname === "/dashboard/chat") {
        component = "Dashboard/Chat";
        Object.assign(props, {
            conversations: empty
                ? []
                : demoGroups.map((g, index) => ({
                      groupId: g.id,
                      subscriptionName: g.subscriptionName,
                      otherName: g.ownerName,
                      otherId: index + 10,
                      otherAvatar: null,
                      lastMessage: [
                          "Bienvenue dans notre groupe !",
                          "Merci, à très bientôt.",
                          "On se retrouve ici.",
                      ][index],
                      lastMessageAt: "14:28",
                      unreadCount: index === 0 ? 1 : 0,
                  })),
        });
    } else if (url.pathname === "/dashboard/profile") {
        component = "Dashboard/Profile";
        Object.assign(props, { user: profileUser });
    } else if (url.pathname === "/dashboard/preferences") {
        component = "Dashboard/Preferences";
        Object.assign(props, { user: preferenceUser });
    } else if (url.pathname === "/dashboard/groups/create") {
        component = "Dashboard/Groups/Create";
        Object.assign(props, {
            subscriptions: categories.flatMap((c) =>
                c.subscriptions.map((s) => ({ ...s, category: c.name })),
            ),
            verificationError: url.searchParams.has("unverified"),
            identityVerified: !url.searchParams.has("unverified"),
            connectActive: !url.searchParams.has("unverified"),
        });
    } else if (url.pathname === "/charte") component = "Legal/Trust";
    else if (url.pathname === "/conditions") component = "Legal/Terms";
    else if (url.pathname === "/confidentialite") component = "Legal/Privacy";
    return {
        component,
        props,
        url: url.pathname + url.search,
        version: "design-preview",
        clearHistory: false,
        encryptHistory: false,
    };
}
const server = createServer((req, res) => {
    // Block all writes, including auth and payments, even if requested directly.
    if (!["GET", "HEAD"].includes(req.method)) {
        res.writeHead(405, {
            "Content-Type": "application/json",
            Allow: "GET, HEAD",
        });
        res.end(
            JSON.stringify({
                message:
                    "Aperçu uniquement : aucune modification ni transaction possible.",
            }),
        );
        return;
    }
    vite.middlewares(req, res, async () => {
        try {
            const url = new URL(req.url, origin);
            if (url.pathname.startsWith("/api/")) {
                const messages = /^\/api\/groups\/\d+\/messages$/.test(
                    url.pathname,
                );
                const members = /^\/api\/groups\/\d+\/chat-members$/.test(
                    url.pathname,
                );
                res.writeHead(messages || members ? 200 : 403, {
                    "Content-Type": "application/json",
                    "Cache-Control": "no-store",
                });
                res.end(
                    JSON.stringify(
                        messages
                            ? [
                                  {
                                      id: 1,
                                      body: "Bienvenue dans notre groupe ! Ceci est une conversation de démonstration.",
                                      sender_id: 10,
                                      sender_name: "Camille",
                                      sender_avatar: null,
                                      created_at: "14:28",
                                      is_mine: false,
                                  },
                                  {
                                      id: 2,
                                      body: "Merci ! Tout est clair pour moi.",
                                      sender_id: 1,
                                      sender_name: "Vous",
                                      sender_avatar: null,
                                      created_at: "14:30",
                                      is_mine: true,
                                  },
                              ]
                            : members
                              ? [
                                    {
                                        id: 10,
                                        name: "Camille Démo",
                                        avatar: null,
                                    },
                                    { id: 11, name: "Alex Démo", avatar: null },
                                ]
                              : {
                                    message:
                                        "Aperçu uniquement : les accès privés et le paiement sont désactivés.",
                                },
                    ),
                );
                return;
            }
            const page = pageFor(url);
            res.setHeader("Cache-Control", "no-store");
            if (req.headers["x-inertia"]) {
                res.writeHead(200, {
                    "Content-Type": "application/json",
                    "X-Inertia": "true",
                    Vary: "X-Inertia",
                });
                res.end(JSON.stringify(page));
                return;
            }
            const encoded = JSON.stringify(page)
                .replaceAll("&", "&amp;")
                .replaceAll("'", "&#39;")
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;");
            const html = await vite.transformIndexHtml(
                url.pathname,
                `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Equitab — aperçu de la refonte</title><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet"></head><body><div id="app" data-page='${encoded}'></div><script type="module" src="/resources/js/design-preview.ts"></script></body></html>`,
            );
            res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
            res.end(html);
        } catch (error) {
            vite.ssrFixStacktrace(error);
            console.error(error);
            res.writeHead(500);
            res.end("Impossible de charger cet aperçu.");
        }
    });
});
server.listen(port, "127.0.0.1", () =>
    console.log(
        "Aperçu Equitab — données fictives, aucun paiement : " + origin,
    ),
);
async function shutdown() {
    await vite.close();
    server.close();
}
process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
