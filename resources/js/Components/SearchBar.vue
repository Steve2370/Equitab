<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';

const searchQuery = ref('');

function handleSearch(): void {
    if (!searchQuery.value.trim()) return;
    router.get('/services', { search: searchQuery.value }, { preserveState: false });
}
</script>

<template>
    <form @submit.prevent="handleSearch" class="relative mx-auto max-w-md">
        <Search class="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
        <input
            v-model="searchQuery"
            type="text"
            placeholder="Rechercher un service..."
            class="w-full rounded-2xl border border-gray-200 bg-white py-3.5 pl-12 pr-4 text-sm text-gray-700 placeholder-gray-400 shadow-sm focus:border-equitab-emerald focus:outline-none focus:ring-1 focus:ring-equitab-emerald"
        />
        <button
            type="submit"
            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-xl bg-equitab-emerald px-4 py-1.5 text-sm font-medium text-white hover:bg-equitab-emerald-dark"
        >
            Rechercher
        </button>
    </form>
</template>
