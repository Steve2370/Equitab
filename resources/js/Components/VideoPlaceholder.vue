<script setup lang="ts">
interface Props {
    videoSrc?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-3xl bg-equitab-navy">
        <video
            v-if="videoSrc"
            :src="videoSrc"
            autoplay
            loop
            muted
            playsinline
            class="h-full w-full object-cover"
        />
    </div>
</template>
