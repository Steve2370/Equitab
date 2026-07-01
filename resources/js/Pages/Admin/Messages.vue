<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Send } from 'lucide-vue-next';

interface User {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{ users: User[] }>();

const form = ref({
    subject: '',
    body: '',
    recipients: 'all' as 'all' | 'specific',
    user_ids: [] as number[],
});

const isSending = ref(false);

function send(): void {
    isSending.value = true;
    router.post('/admin/messages/send', form.value, {
        onSuccess: () => {
            form.value = { subject: '', body: '', recipients: 'all', user_ids: [] };
        },
        onFinish: () => { isSending.value = false; },
    });
}
</script>

<template>
    <Head title="Messagerie — Admin" />
    <AdminLayout>
        <div class="mb-6">
            <h1 class="text-2xl font-semibold text-equitab-navy">Messagerie</h1>
            <p class="mt-1 text-sm text-gray-500">Envoyer un email aux utilisateurs Equitab</p>
        </div>

        <div class="max-w-2xl space-y-4">
            <div class="rounded-xl border border-gray-100 bg-white p-6 space-y-4">

                <div>
                    <label class="text-sm font-medium text-gray-700">Destinataires</label>
                    <div class="mt-2 flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.recipients" type="radio" value="all" class="accent-equitab-emerald" />
                            <span class="text-sm">Tous les utilisateurs ({{ users.length }})</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.recipients" type="radio" value="specific" class="accent-equitab-emerald" />
                            <span class="text-sm">Utilisateurs spécifiques</span>
                        </label>
                    </div>
                </div>

                <div v-if="form.recipients === 'specific'">
                    <label class="text-sm font-medium text-gray-700">Sélectionner les utilisateurs</label>
                    <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-50">
                        <label
                            v-for="user in users"
                            :key="user.id"
                            class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-gray-50"
                        >
                            <input
                                v-model="form.user_ids"
                                type="checkbox"
                                :value="user.id"
                                class="accent-equitab-emerald"
                            />
                            <div>
                                <p class="text-sm font-medium text-equitab-navy">{{ user.name }}</p>
                                <p class="text-xs text-gray-400">{{ user.email }}</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Sujet</label>
                    <input
                        v-model="form.subject"
                        type="text"
                        placeholder="Sujet de l'email"
                        class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                    />
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Message</label>
                    <textarea
                        v-model="form.body"
                        rows="8"
                        placeholder="Rédigez votre message ici..."
                        class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                    />
                </div>

                <button
                    @click="send"
                    :disabled="isSending || !form.subject || !form.body"
                    class="flex items-center gap-2 rounded-lg bg-equitab-navy px-5 py-2.5 text-sm font-medium text-white hover:bg-equitab-navy-light disabled:opacity-50"
                >
                    <Send class="h-4 w-4" />
                    {{ isSending ? 'Envoi en cours...' : 'Envoyer' }}
                </button>
            </div>
        </div>
    </AdminLayout>
</template>
