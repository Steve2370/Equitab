<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageSquare } from 'lucide-vue-next';
import ExperienceDialog from '@/Components/Experience/ExperienceDialog.vue';
import ServiceAccessPanel from '@/Components/ServiceAccessPanel.vue';
import { useServiceAccess } from '@/composables/useServiceAccess';

const props = defineProps<{ groupId: number; subscriptionName: string }>();
const emit = defineEmits<{ close: [] }>();
const { access, status, loading, error, timedOut, retry } = useServiceAccess(() => props.groupId);
</script>

<template>
    <ExperienceDialog :open="true" :title="'Accès — ' + subscriptionName" @close="emit('close')">
        <ServiceAccessPanel :access="access" :status="status" :loading="loading" :error="error" :timed-out="timedOut" @retry="retry" />
        <Link href="/dashboard/chat" class="mt-4 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-equitab-navy hover:bg-gray-50" @click="emit('close')">
            <MessageSquare class="h-4 w-4" aria-hidden="true" />Contacter le propriétaire
        </Link>
    </ExperienceDialog>
</template>
