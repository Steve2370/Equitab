export interface BrandGradient {
    from: string;
    to: string;
}

const brandGradients: Record<string, BrandGradient> = {
    netflix: { from: '#E50914', to: '#B20710' },
    'disney': { from: '#1132A8', to: '#0D2680' },
    'youtube-premium': { from: '#FF0000', to: '#CC0000' },
    crave: { from: '#00A8E0', to: '#0080B0' },
    crunchyroll: { from: '#F47521', to: '#D4611A' },
    'paramount': { from: '#0064FF', to: '#0050CC' },
    'canal-plus': { from: '#FF0000', to: '#FF0000' },
    'amazon-prime': { from: '#00A8E1', to: '#0087B5' },
    spotify: { from: '#1DB954', to: '#17A349' },
    'apple-music': { from: '#FA2C56', to: '#D4234A' },
    deezer: { from: '#FF6B35', to: '#E55A28' },
    tidal: { from: '#000000', to: '#1A1A1A' },
    'xbox-game-pass': { from: '#107C10', to: '#0D6B0D' },
    nintendo: { from: '#E4000F', to: '#C2000D' },
    nordvpn: { from: '#4687FF', to: '#2E6FE0' },
    cyberghost: { from: '#FFCC00', to: '#E0B200' },
    envato: { from: '#81B441', to: '#6A9535' },
    'google-one': { from: '#4285F4', to: '#2B6FD4' },
    'microsoft-365': { from: '#D83B01', to: '#B53201' },
    adobe: { from: '#FF0000', to: '#CC0000' },
    'adobe-creative-cloud': { from: '#FF0000', to: '#CC0000' },
    duolingo: { from: '#58CC02', to: '#46A801' },
    readly: { from: '#E91E8C', to: '#C4177A' },
    default: { from: '#0B1929', to: '#10B981' },
};

export function getBrandGradient(slug: string): BrandGradient {
    return brandGradients[slug] ?? brandGradients.default;
}
