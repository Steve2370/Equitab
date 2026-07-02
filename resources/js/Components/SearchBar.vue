<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';


const searchQuery = ref('');

function handleSearch(): void {
    if (!searchQuery.value.trim()) return;
    router.get('/services', { search: searchQuery.value }, { preserveState: false });
}
</script>

<template>
    <form @submit.prevent="handleSearch" class="relative hidden md:block">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-white/40" />
        <input
            v-model="searchQuery"
            type="text"
            placeholder="Rechercher un service..."
            class="rounded-xl border border-white/10 bg-white/10 py-2 pl-9 pr-4 text-sm text-white placeholder-white/40 focus:border-equitab-emerald focus:outline-none w-56"
            @keyup.enter="handleSearch"
        />
    </form>
</template>
