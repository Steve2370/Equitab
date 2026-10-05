<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
defineProps<{
    page: {
        current_page?: number;
        last_page?: number;
        prev_page_url?: string | null;
        next_page_url?: string | null;
    };
}>();
</script>
<template>
    <nav
        v-if="page.last_page && page.last_page > 1"
        class="admin-pagination"
        aria-label="Pagination"
    >
        <Link
            v-if="page.prev_page_url"
            :href="page.prev_page_url"
            class="eq-button eq-button-secondary"
            >Précédent</Link
        >
        <span>Page {{ page.current_page }} sur {{ page.last_page }}</span>
        <Link
            v-if="page.next_page_url"
            :href="page.next_page_url"
            class="eq-button eq-button-secondary"
            >Suivant</Link
        >
    </nav>
</template>
