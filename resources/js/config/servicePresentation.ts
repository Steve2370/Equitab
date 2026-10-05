export type ArtworkScene =
    | "cinema"
    | "music"
    | "world"
    | "cloud"
    | "play"
    | "arcade"
    | "shield"
    | "book";
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
