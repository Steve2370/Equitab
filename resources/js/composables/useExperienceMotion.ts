import { computed, onMounted, onBeforeUnmount, ref } from "vue";

export function useExperienceMotion() {
    // Start still: do not animate before the system preference has been read.
    const reducedMotion = ref(true);
    const motion = computed(() => !reducedMotion.value);
    let preference: MediaQueryList | undefined;
    function changed(event: MediaQueryListEvent) {
        reducedMotion.value = event.matches;
    }
    onMounted(() => {
        preference = window.matchMedia("(prefers-reduced-motion: reduce)");
        reducedMotion.value = preference.matches;
        preference.addEventListener("change", changed);
    });
    onBeforeUnmount(() => preference?.removeEventListener("change", changed));
    return { motion, reducedMotion };
}
