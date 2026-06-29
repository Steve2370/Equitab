export interface BrandGradient {
    from: string;
    to: string;
}

const brandGradients: Record<string, BrandGradient> = {
    spotify: { from: '#1DB954', to: '#0A7A35' },
    netflix: { from: '#E50914', to: '#831010' },
    disney: { from: '#1132A8', to: '#0A1F5C' },
    deezer: { from: '#FF6B35', to: '#A855F7' },
    youtube: { from: '#FF0000', to: '#990000' },
    'apple-music': { from: '#FA2C56', to: '#7B1530' },
    default: { from: '#0B1929', to: '#10B981' },
};

export function getBrandGradient(slug: string): BrandGradient {
    return brandGradients[slug] ?? brandGradients.default;
}
