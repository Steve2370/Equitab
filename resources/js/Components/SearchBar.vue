<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const searchQuery = ref<string>('');

function handleSearch(): void {
    if (searchQuery.value.trim().length === 0) return;
    router.get('/groups', { search: searchQuery.value });
}
</script>

<template>
    <form @submit.prevent="handleSearch" class="mx-auto flex w-full max-w-xl items-center gap-2">
        <input
            v-model="searchQuery"
            type="text"
            placeholder="Netflix, Apple TV, Prime Video..."
            class="w-full rounded-full border border-gray-200 px-5 py-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-equitab-emerald focus:outline-none"
        />
        <button
            type="submit"
            class="shrink-0 rounded-full bg-equitab-navy px-6 py-3 text-sm font-medium text-white hover:bg-equitab-navy-light"
        >
            Rechercher
        </button>
    </form>
</template>
