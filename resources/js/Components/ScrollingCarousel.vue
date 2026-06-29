<script setup lang="ts">
interface Props {
    durationSeconds?: number;
}

withDefaults(defineProps<Props>(), {
    durationSeconds: 30,
});
</script>

<template>
    <div class="carousel-track-wrapper relative w-full overflow-hidden">
        <div
            class="carousel-track flex w-max gap-4"
            :style="{ animationDuration: `${durationSeconds}s` }"
        >
            <slot />
            <slot />
        </div>
    </div>
</template>

<style scoped>
.carousel-track {
    animation: scroll-left linear infinite;
}

.carousel-track-wrapper:hover .carousel-track {
    animation-play-state: paused;
}

@keyframes scroll-left {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(-50%);
    }
}
</style>
