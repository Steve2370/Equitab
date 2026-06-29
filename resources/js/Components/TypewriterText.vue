<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';

interface Props {
    words: string[];
    typingSpeedMs?: number;
    deletingSpeedMs?: number;
    pauseMs?: number;
}

const props = withDefaults(defineProps<Props>(), {
    typingSpeedMs: 90,
    deletingSpeedMs: 45,
    pauseMs: 1400,
});

const displayedText = ref<string>('');
let wordIndex = 0;
let charIndex = 0;
let isDeleting = false;
let timeoutId: ReturnType<typeof setTimeout> | null = null;

function tick(): void {
    const currentWord = props.words[wordIndex] ?? '';

    if (!isDeleting) {
        charIndex++;
        displayedText.value = currentWord.slice(0, charIndex);

        if (charIndex === currentWord.length) {
            isDeleting = true;
            timeoutId = setTimeout(tick, props.pauseMs);
            return;
        }

        timeoutId = setTimeout(tick, props.typingSpeedMs);
        return;
    }

    charIndex--;
    displayedText.value = currentWord.slice(0, charIndex);

    if (charIndex === 0) {
        isDeleting = false;
        wordIndex = (wordIndex + 1) % props.words.length;
    }

    timeoutId = setTimeout(tick, props.deletingSpeedMs);
}

onMounted(() => {
    if (props.words.length > 0) {
        timeoutId = setTimeout(tick, props.typingSpeedMs);
    }
});

onUnmounted(() => {
    if (timeoutId) clearTimeout(timeoutId);
});
</script>

<template>
    <span class="inline-flex items-center">
        {{ displayedText }}<span class="animate-pulse ml-0.5">|</span>
    </span>
</template>
