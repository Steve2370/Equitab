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
}
// Service identities: name and colors only. No third-party logo is shown;
// cards display the service name. These colors identify services, not EquitAb actions.
const identities: Record<string, ServiceBrand> = {
    netflix: {
        name: "Netflix",
        accent: "#e50914",
        deep: "#360208",
        ink: "#fff",
    },
    spotify: {
        name: "Spotify",
        accent: "#1ed760",
        deep: "#063820",
        ink: "#092718",
    },
    disney: {
        name: "Disney+",
        accent: "#02d6c4",
        deep: "#004c52",
        ink: "#003b40",
    },
    "youtube-premium": {
        name: "YouTube Premium",
        accent: "#ff0033",
        deep: "#51000c",
        ink: "#fff",
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
    },
    paramount: {
        name: "Paramount+",
        accent: "#0064ff",
        deep: "#002264",
        ink: "#fff",
    },
    canal: {
        name: "CANAL+",
        accent: "#383838",
        deep: "#080808",
        ink: "#fff",
    },
    "amazon-prime": {
        name: "Amazon Prime",
        accent: "#00a8e1",
        deep: "#052743",
        ink: "#002d47",
    },
    "apple-music": {
        name: "Apple Music",
        accent: "#fa2c56",
        deep: "#68142e",
        ink: "#fff",
    },
    deezer: {
        name: "Deezer",
        accent: "#a238ff",
        deep: "#300e62",
        ink: "#fff",
    },
    tidal: {
        name: "TIDAL",
        accent: "#414141",
        deep: "#090909",
        ink: "#fff",
    },
    "xbox-game-pass": {
        name: "Xbox Game Pass",
        accent: "#107c10",
        deep: "#063206",
        ink: "#fff",
    },
    nintendo: {
        name: "Nintendo",
        accent: "#e60012",
        deep: "#69030c",
        ink: "#fff",
    },
    nordvpn: {
        name: "NordVPN",
        accent: "#4687ff",
        deep: "#132a67",
        ink: "#fff",
    },
    cyberghost: {
        name: "CyberGhost",
        accent: "#ffcc00",
        deep: "#574807",
        ink: "#28210a",
    },
    envato: {
        name: "Envato",
        accent: "#81b441",
        deep: "#254318",
        ink: "#1c3014",
    },
    "google-one": {
        name: "Google One",
        accent: "#4285f4",
        deep: "#173264",
        ink: "#fff",
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
    },
    duolingo: {
        name: "Duolingo",
        accent: "#58cc02",
        deep: "#1f5003",
        ink: "#203b0b",
    },
    readly: {
        name: "Readly",
        accent: "#ffcf00",
        deep: "#625012",
        ink: "#33270a",
    },
    // Visual identities only: these entries do not publish catalogue offers.
    "dropbox-family": {
        name: "Dropbox Family",
        accent: "#0061ff",
        deep: "#002563",
        ink: "#fff",
    },
    "bitwarden-families": {
        name: "Bitwarden Families",
        accent: "#175ddc",
        deep: "#102b61",
        ink: "#fff",
    },
    "nordpass-family": {
        name: "NordPass Family",
        accent: "#007c83",
        deep: "#063c40",
        ink: "#fff",
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
    dropbox: "dropbox-family",
    bitwarden: "bitwarden-families",
    nordpass: "nordpass-family",
};
function serviceIdentityKey(slug: string): string {
    const normalized = slug.trim().toLowerCase().replace(/\s+/g, "-");
    return Object.prototype.hasOwnProperty.call(aliases, normalized)
        ? aliases[normalized]
        : normalized;
}
export function serviceBrand(slug: string): ServiceBrand | null {
    const key = serviceIdentityKey(slug);
    return Object.prototype.hasOwnProperty.call(identities, key)
        ? identities[key]
        : null;
}
interface Presentation {
    scene: ArtworkScene;
    tagline: string;
    palette?: string;
}
const brands: Record<string, Presentation> = {
    "dropbox-family": {
        scene: "cloud",
        palette: "ice",
        tagline: "Vos fichiers à portée de main.",
    },
    "bitwarden-families": {
        scene: "shield",
        tagline: "Un coffre personnel pour vos mots de passe.",
    },
    "nordpass-family": {
        scene: "shield",
        tagline: "Vos mots de passe, dans votre coffre.",
    },
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
    const key = serviceIdentityKey(slug);
    if (Object.prototype.hasOwnProperty.call(brands, key)) return brands[key];
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
