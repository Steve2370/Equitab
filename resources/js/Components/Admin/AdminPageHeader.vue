<script setup lang="ts">
defineProps<{ title: string; description?: string; section?: string }>();
</script>
<template>
    <header class="eq-page-heading">
        <div>
            <p class="eq-eyebrow text-eq-green">
                {{ section || "EQUITAB / ADMINISTRATION" }}
            </p>
            <h1>{{ title }}</h1>
            <p v-if="description">{{ description }}</p>
        </div>
        <div v-if="$slots.default"><slot /></div>
    </header>
</template>
