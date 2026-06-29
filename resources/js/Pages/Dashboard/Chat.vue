<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardLayout from '@/Layouts/DashboardLayout.vue';
import { Send, MessageSquare } from 'lucide-vue-next';

interface Conversation {
    groupId: number;
    subscriptionName: string;
    otherName: string;
    otherId: number | null;
    lastMessage: string | null;
    lastMessageAt: string | null;
    unreadCount: number;
}

interface Message {
    id: number;
    body: string;
    sender_id: number;
    sender_name: string;
    created_at: string;
    is_mine: boolean;
}

interface Props {
    conversations: Conversation[];
}

const props = defineProps<Props>();

const selectedConversation = ref<Conversation | null>(null);
const messages = ref<Message[]>([]);
const newMessage = ref('');
const isLoading = ref(false);
const messagesContainer = ref<HTMLElement | null>(null);
let echoChannel: any = null;

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie.split('; ')
            .find(r => r.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? ''
    );
}

async function selectConversation(conv: Conversation): Promise<void> {
    selectedConversation.value = conv;
    isLoading.value = true;

    try {
        const response = await fetch(`/api/groups/${conv.groupId}/messages`, {
            headers: { 'Accept': 'application/json' },
        });
        messages.value = await response.json();
        await nextTick();
        scrollToBottom();
    } finally {
        isLoading.value = false;
    }

    // Subscribe au canal WebSocket
    if (echoChannel) echoChannel.stopListening('.MessageSent');

    const userId = (window as any).__user_id;
    const otherId = conv.otherId;

    if (userId && otherId) {
        const min = Math.min(userId, otherId);
        const max = Math.max(userId, otherId);
        const channelName = `chat.${conv.groupId}.${min}.${max}`;

        echoChannel = (window as any).Echo
            .private(channelName)
            .listen('.MessageSent', (data: Message) => {
                messages.value.push(data);
                nextTick(() => scrollToBottom());
            });
    }
}

async function sendMessage(): Promise<void> {
    if (! newMessage.value.trim() || ! selectedConversation.value) return;

    const body = newMessage.value;
    newMessage.value = '';

    const response = await fetch(`/api/groups/${selectedConversation.value.groupId}/messages`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify({
            body,
            receiver_id: selectedConversation.value.otherId,
        }),
    });

    const message = await response.json();
    messages.value.push(message);
    await nextTick();
    scrollToBottom();
}

function scrollToBottom(): void {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

onUnmounted(() => {
    if (echoChannel) echoChannel.stopListening('.MessageSent');
});
</script>

<template>
    <Head title="Chat — Equitab" />

    <DashboardLayout>
        <div class="flex h-[calc(100vh-8rem)] overflow-hidden rounded-xl border border-gray-100 bg-white">

            <div class="w-80 shrink-0 border-r border-gray-100 flex flex-col">
                <div class="border-b border-gray-100 px-4 py-4">
                    <h2 class="font-semibold text-equitab-navy">Messages</h2>
                </div>

                <div class="flex-1 overflow-y-auto">
                    <div
                        v-if="conversations.length === 0"
                        class="flex flex-col items-center justify-center py-12 text-center px-4"
                    >
                        <MessageSquare class="h-8 w-8 text-gray-200" />
                        <p class="mt-2 text-sm text-gray-400">Aucune conversation</p>
                    </div>

                    <button
                        v-for="conv in conversations"
                        :key="conv.groupId"
                        @click="selectConversation(conv)"
                        class="w-full flex items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50"
                        :class="selectedConversation?.groupId === conv.groupId ? 'bg-gray-50' : ''"
                    >
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-equitab-navy/10 text-sm font-semibold text-equitab-navy">
                            {{ conv.subscriptionName.charAt(0) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <p class="truncate text-sm font-medium text-equitab-navy">
                                    {{ conv.subscriptionName }}
                                </p>
                                <span v-if="conv.unreadCount > 0" class="ml-2 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-equitab-emerald text-xs font-medium text-white">
                                    {{ conv.unreadCount }}
                                </span>
                            </div>
                            <p class="truncate text-xs text-gray-400">{{ conv.otherName }}</p>
                            <p v-if="conv.lastMessage" class="truncate text-xs text-gray-400 mt-0.5">
                                {{ conv.lastMessage }}
                            </p>
                        </div>
                    </button>
                </div>
            </div>

            <div class="flex flex-1 flex-col">
                <div v-if="!selectedConversation" class="flex flex-1 flex-col items-center justify-center text-center">
                    <MessageSquare class="h-12 w-12 text-gray-200" />
                    <p class="mt-3 text-sm font-medium text-gray-400">Sélectionnez une conversation</p>
                    <p class="text-xs text-gray-300">pour afficher les messages</p>
                </div>

                <template v-else>
                    <div class="flex items-center gap-3 border-b border-gray-100 px-6 py-4">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-equitab-navy/10 text-sm font-semibold text-equitab-navy">
                            {{ selectedConversation.subscriptionName.charAt(0) }}
                        </div>
                        <div>
                            <p class="font-semibold text-equitab-navy">{{ selectedConversation.subscriptionName }}</p>
                            <p class="text-xs text-gray-400">{{ selectedConversation.otherName }}</p>
                        </div>
                    </div>

                    <div
                        ref="messagesContainer"
                        class="flex-1 overflow-y-auto p-6 space-y-3"
                    >
                        <div v-if="isLoading" class="flex justify-center py-8">
                            <div class="h-5 w-5 animate-spin rounded-full border-2 border-equitab-emerald border-t-transparent" />
                        </div>

                        <div
                            v-else
                            v-for="msg in messages"
                            :key="msg.id"
                            class="flex"
                            :class="msg.is_mine ? 'justify-end' : 'justify-start'"
                        >
                            <div
                                class="max-w-xs rounded-2xl px-4 py-2.5 text-sm"
                                :class="msg.is_mine
                                    ? 'rounded-br-sm bg-equitab-navy text-white'
                                    : 'rounded-bl-sm bg-gray-100 text-equitab-navy'"
                            >
                                <p>{{ msg.body }}</p>
                                <p
                                    class="mt-1 text-right text-xs"
                                    :class="msg.is_mine ? 'text-white/60' : 'text-gray-400'"
                                >
                                    {{ msg.created_at }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 p-4">
                        <div class="flex items-end gap-3">
                            <textarea
                                v-model="newMessage"
                                @keydown="handleKeydown"
                                placeholder="Écrivez un message... (Entrée pour envoyer)"
                                rows="1"
                                class="flex-1 resize-none rounded-xl border border-gray-200 px-4 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                            />
                            <button
                                @click="sendMessage"
                                :disabled="!newMessage.trim()"
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-equitab-emerald text-white hover:bg-equitab-emerald-dark disabled:opacity-50"
                            >
                                <Send class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </DashboardLayout>
</template>
