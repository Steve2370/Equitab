export type ArtworkScene =
    | "cinema"
    | "music"
    | "world"
    | "cloud"
    | "play"
    | "arcade"
    | "shield"
    | "book";
export interface ServiceBrand {
    name: string;
    accent: string;
    deep: string;
    ink: string;
    logo?: string;
    /** User-provided file, kept under its original filename. */
    logoFile?: string;
    logoWide?: boolean;
    logoScale?: number;
}
// Local, versioned marks. A service without a sourced asset uses its name,
// never a made-up logo. These colors identify services, not EquitAb actions.
const identities: Record<string, ServiceBrand> = {
    netflix: {
        name: "Netflix",
        accent: "#e50914",
        deep: "#360208",
        ink: "#fff",
        logo: "netflix",
    },
    spotify: {
        name: "Spotify",
        accent: "#1ed760",
        deep: "#063820",
        ink: "#092718",
        logo: "spotify",
    },
    disney: {
        name: "Disney+",
        accent: "#02d6c4",
        deep: "#004c52",
        ink: "#003b40",
        logoFile: "Disney+.png",
        logoWide: true,
        logoScale: 1.5,
    },
    "youtube-premium": {
        name: "YouTube Premium",
        accent: "#ff0033",
        deep: "#51000c",
        ink: "#fff",
        logo: "youtube",
    },
    crave: {
        name: "Crave",
        accent: "#00a8e0",
        deep: "#003f64",
        ink: "#002c46",
    },
    crunchyroll: {
        name: "Crunchyroll",
        accent: "#f47521",
        deep: "#6f2905",
        ink: "#351607",
        logo: "crunchyroll",
    },
    paramount: {
        name: "Paramount+",
        accent: "#0064ff",
        deep: "#002264",
        ink: "#fff",
        logo: "paramountplus",
    },
    canal: {
        name: "CANAL+",
        accent: "#383838",
        deep: "#080808",
        ink: "#fff",
        logoFile: "canal.png",
        logoWide: true,
        logoScale: 2.5,
    },
    "amazon-prime": {
        name: "Amazon Prime",
        accent: "#00a8e1",
        deep: "#052743",
        ink: "#002d47",
        logo: "amazonprime",
    },
    "apple-music": {
        name: "Apple Music",
        accent: "#fa2c56",
        deep: "#68142e",
        ink: "#fff",
        logo: "applemusic",
    },
    deezer: {
        name: "Deezer",
        accent: "#a238ff",
        deep: "#300e62",
        ink: "#fff",
        logoFile: "deezer-logo.png",
        logoWide: true,
        logoScale: 1.65,
    },
    tidal: {
        name: "TIDAL",
        accent: "#414141",
        deep: "#090909",
        ink: "#fff",
        logo: "tidal",
    },
    "xbox-game-pass": {
        name: "Xbox Game Pass",
        accent: "#107c10",
        deep: "#063206",
        ink: "#fff",
        logoFile: "Xbox_Game_Pass_2020_logo_-_colored_version.svg.webp",
        logoWide: true,
    },
    nintendo: {
        name: "Nintendo",
        accent: "#e60012",
        deep: "#69030c",
        ink: "#fff",
        logo: "nintendo",
    },
    nordvpn: {
        name: "NordVPN",
        accent: "#4687ff",
        deep: "#132a67",
        ink: "#fff",
        logo: "nordvpn",
    },
    cyberghost: {
        name: "CyberGhost",
        accent: "#ffcc00",
        deep: "#574807",
        ink: "#28210a",
        logoFile: "cyberghost.png",
    },
    envato: {
        name: "Envato",
        accent: "#81b441",
        deep: "#254318",
        ink: "#1c3014",
        logo: "envato",
    },
    "google-one": {
        name: "Google One",
        accent: "#4285f4",
        deep: "#173264",
        ink: "#fff",
        logo: "google",
    },
    "microsoft-365": {
        name: "Microsoft 365",
        accent: "#0078d4",
        deep: "#102f60",
        ink: "#fff",
    },
    "apple-one-family": {
        name: "Apple One",
        accent: "#686874",
        deep: "#242429",
        ink: "#fff",
        logo: "apple",
    },
    duolingo: {
        name: "Duolingo",
        accent: "#58cc02",
        deep: "#1f5003",
        ink: "#203b0b",
        logo: "duolingo",
    },
    readly: {
        name: "Readly",
        accent: "#ffcf00",
        deep: "#625012",
        ink: "#33270a",
    },
};
const aliases: Record<string, string> = {
    "disney-plus": "disney",
    "disney+": "disney",
    "paramount-plus": "paramount",
    "paramount+": "paramount",
    "canal-plus": "canal",
    "canal+": "canal",
    "apple-one": "apple-one-family",
    "cyber-ghost": "cyberghost",
    "prime-video": "amazon-prime",
    "nord-vpn": "nordvpn",
};
export function serviceBrand(slug: string): ServiceBrand | null {
    const normalized = slug.trim().toLowerCase().replace(/\s+/g, "-");
    const key = Object.prototype.hasOwnProperty.call(aliases, normalized)
        ? aliases[normalized]
        : normalized;
    return Object.prototype.hasOwnProperty.call(identities, key)
        ? identities[key]
        : null;
}
export function serviceLogoSource(brand: ServiceBrand | null): string | null {
    const file = brand?.logoFile ?? (brand?.logo ? brand.logo + ".svg" : null);
    // A plus is literal in a URL path, unlike a query string. Keep it intact
    // for static servers that preserve encoded reserved characters.
    return file
        ? "/Images/services/" + encodeURIComponent(file).replace(/%2B/g, "+")
        : null;
}
interface Presentation {
    scene: ArtworkScene;
    tagline: string;
    palette?: string;
}
const brands: Record<string, Presentation> = {
    netflix: { scene: "cinema", tagline: "Les bonnes histoires se partagent." },
    disney: { scene: "world", tagline: "Il reste tant à découvrir." },
    "disney-plus": { scene: "world", tagline: "Il reste tant à découvrir." },
    "youtube-premium": {
        scene: "play",
        tagline: "Une découverte en appelle une autre.",
    },
    spotify: { scene: "music", tagline: "Votre prochaine chanson préférée." },
    "apple-music": {
        scene: "music",
        palette: "rose",
        tagline: "La bande-son de vos journées.",
    },
    deezer: {
        scene: "music",
        palette: "violet",
        tagline: "De nouvelles notes en commun.",
    },
    tidal: {
        scene: "music",
        palette: "ice",
        tagline: "Le plaisir d’écouter, ensemble.",
    },
    "google-one": {
        scene: "cloud",
        palette: "ice",
        tagline: "De la place pour ce qui compte.",
    },
    "microsoft-365": {
        scene: "cloud",
        tagline: "Les grandes idées commencent ici.",
    },
    "apple-one-family": {
        scene: "world",
        palette: "rose",
        tagline: "Un peu de tout ce que vous aimez.",
    },
    nintendo: {
        scene: "arcade",
        palette: "rose",
        tagline: "À plusieurs, la partie est encore meilleure.",
    },
    readly: {
        scene: "book",
        palette: "rose",
        tagline: "Encore une page, encore une découverte.",
    },
    cyberghost: {
        scene: "shield",
        palette: "violet",
        tagline: "Votre quotidien numérique.",
    },
};
export function servicePresentation(
    slug: string,
    category: string,
): Presentation {
    if (Object.prototype.hasOwnProperty.call(brands, slug)) return brands[slug];
    const label = category
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase();
    if (/musique|music/.test(label))
        return { scene: "music", tagline: "Le plaisir d’écouter, ensemble." };
    if (/jeux|gaming/.test(label))
        return {
            scene: "arcade",
            tagline: "Vos prochaines parties commencent ici.",
        };
    if (/securite|vpn/.test(label))
        return { scene: "shield", tagline: "Votre quotidien numérique." };
    if (/education|lecture/.test(label))
        return { scene: "book", tagline: "La curiosité ne s’arrête jamais." };
    if (/productivite|outils|quotidien/.test(label))
        return { scene: "cloud", tagline: "Plus de place pour vos idées." };
    if (/streaming|films|series/.test(label))
        return {
            scene: "cinema",
            tagline: "Encore de belles histoires à découvrir.",
        };
    return { scene: "world", tagline: "Une nouvelle envie à partager." };
}

/** The API returns cents for the entire subscription, not a member's share. */
export function indicativeShare(
    monthlyPrice: number | null,
    capacity: number | null,
): number | null {
    if (
        monthlyPrice === null ||
        capacity === null ||
        !Number.isFinite(monthlyPrice) ||
        monthlyPrice < 0 ||
        !Number.isInteger(capacity) ||
        capacity < 1
    )
        return null;
    return Math.round(monthlyPrice / capacity);
}
export function formatCad(cents: number): string {
    return new Intl.NumberFormat("fr-CA", {
        style: "currency",
        currency: "CAD",
    }).format(cents / 100);
}
