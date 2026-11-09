// Apparition douce des éléments marqués .eq-reveal quand ils entrent à l'écran.
// Rien n'est masqué si le navigateur ne sait pas observer ou si l'utilisateur
// a demandé de réduire les animations : le contenu reste toujours visible.
export function installReveal(): void {
    if (
        typeof window === "undefined" ||
        typeof IntersectionObserver === "undefined" ||
        typeof MutationObserver === "undefined" ||
        window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
        return;
    }
    document.documentElement.classList.add("eq-reveal-ready");
    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                entry.target.classList.add("is-visible");
                observer.unobserve(entry.target);
            }
        },
        { threshold: 0 },
    );
    const watch = (root: ParentNode) =>
        root
            .querySelectorAll(".eq-reveal:not(.is-visible)")
            .forEach((element) => observer.observe(element));
    watch(document);
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches(".eq-reveal:not(.is-visible)")) observer.observe(node);
                watch(node);
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
}
