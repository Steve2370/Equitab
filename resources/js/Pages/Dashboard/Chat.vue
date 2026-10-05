<script setup lang="ts">
import { ref, onUnmounted, nextTick } from "vue";
import { Head } from "@inertiajs/vue3";
import DashboardLayout from "@/Layouts/DashboardLayout.vue";
import { Send, MessageSquare, UserPlus, ArrowLeft } from "lucide-vue-next";
import ExperienceDialog from "@/Components/Experience/ExperienceDialog.vue";

interface Conversation {
    groupId: number;
    subscriptionName: string;
    otherName: string;
    otherId: number | null;
    otherAvatar: string | null;
    lastMessage: string | null;
    lastMessageAt: string | null;
    unreadCount: number;
}

interface Message {
    id: number;
    body: string;
    sender_id: number;
    sender_name: string;
    sender_avatar: string | null;
    created_at: string;
    is_mine: boolean;
}

interface ChatMember {
    id: number;
    name: string;
    avatar: string | null;
}

interface Props {
    conversations: Conversation[];
}

const props = defineProps<Props>();

const conversationsList = ref<Conversation[]>([...props.conversations]);
const selectedConversation = ref<Conversation | null>(null);
const messages = ref<Message[]>([]);
const newMessage = ref("");
const isLoading = ref(false);
const messagesContainer = ref<HTMLElement | null>(null);
let echoChannel: any = null;

const showMemberPicker = ref(false);
const pickerMembers = ref<ChatMember[]>([]);
const pickerGroupId = ref<number | null>(null);

function getCsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split("; ")
            .find((r) => r.startsWith("XSRF-TOKEN="))
            ?.split("=")[1] ?? "",
    );
}

function initials(name: string): string {
    return name.charAt(0).toUpperCase();
}

async function selectConversation(conv: Conversation): Promise<void> {
    selectedConversation.value = conv;
    isLoading.value = true;

    try {
        const response = await fetch(
            `/api/groups/${conv.groupId}/messages?other_id=${conv.otherId}`,
            {
                headers: { Accept: "application/json" },
            },
        );
        messages.value = await response.json();
        await nextTick();
        scrollToBottom();
    } finally {
        isLoading.value = false;
    }

    if (echoChannel) echoChannel.stopListening(".MessageSent");

    const userId = (window as any).__user_id;
    const otherId = conv.otherId;

    if (userId && otherId) {
        const min = Math.min(userId, otherId);
        const max = Math.max(userId, otherId);
        const channelName = `chat.${conv.groupId}.${min}.${max}`;

        echoChannel = (window as any).Echo.private(channelName).listen(
            ".MessageSent",
            (data: Message) => {
                messages.value.push(data);
                nextTick(() => scrollToBottom());
            },
        );
    }
}

async function sendMessage(): Promise<void> {
    if (!newMessage.value.trim() || !selectedConversation.value) return;

    const body = newMessage.value;
    newMessage.value = "";

    const response = await fetch(
        `/api/groups/${selectedConversation.value.groupId}/messages`,
        {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": getCsrfToken(),
            },
            body: JSON.stringify({
                body,
                receiver_id: selectedConversation.value.otherId,
            }),
        },
    );

    const message = await response.json();
    messages.value.push(message);
    await nextTick();
    scrollToBottom();
}

async function openMemberPicker(groupId: number): Promise<void> {
    pickerGroupId.value = groupId;
    showMemberPicker.value = true;

    const response = await fetch(`/api/groups/${groupId}/chat-members`, {
        headers: { Accept: "application/json" },
    });
    pickerMembers.value = await response.json();
}

function startConversationWith(member: ChatMember): void {
    if (!pickerGroupId.value) return;

    const existing = conversationsList.value.find(
        (c) => c.groupId === pickerGroupId.value && c.otherId === member.id,
    );

    showMemberPicker.value = false;

    if (existing) {
        selectConversation(existing);
        return;
    }

    const group = conversationsList.value.find(
        (c) => c.groupId === pickerGroupId.value,
    );

    const newConv: Conversation = {
        groupId: pickerGroupId.value,
        subscriptionName: group?.subscriptionName ?? "",
        otherName: member.name,
        otherId: member.id,
        otherAvatar: member.avatar,
        lastMessage: null,
        lastMessageAt: null,
        unreadCount: 0,
    };

    conversationsList.value.unshift(newConv);
    selectConversation(newConv);
}

function scrollToBottom(): void {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop =
            messagesContainer.value.scrollHeight;
    }
}

function handleKeydown(e: KeyboardEvent): void {
    if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

onUnmounted(() => {
    if (echoChannel) echoChannel.stopListening(".MessageSent");
});
</script>

<template>
    <Head title="Chat — Equitab" />

    <DashboardLayout>
        <div class="eq-page-heading">
            <div>
                <p class="eq-eyebrow">ON GARDE LE LIEN</p>
                <h1>Les conversations.</h1>
                <p>
                    Un accès à demander, une question à partager. Retrouvez
                    votre groupe.
                </p>
            </div>
        </div>
        <div
            class="chat-shell"
            :class="{ 'conversation-selected': selectedConversation }"
        >
            <div class="chat-list">
                <div
                    class="flex items-center justify-between border-b border-gray-100 px-4 py-4"
                >
                    <h2 class="font-semibold text-equitab-navy">Messages</h2>
                </div>

                <div class="flex-1 overflow-y-auto">
                    <div
                        v-if="conversationsList.length === 0"
                        class="flex flex-col items-center justify-center py-12 text-center px-4"
                    >
                        <MessageSquare class="h-8 w-8 text-gray-200" />
                        <p class="mt-2 text-sm text-gray-400">
                            Aucune conversation
                        </p>
                    </div>

                    <div
                        v-for="conv in conversationsList"
                        :key="`${conv.groupId}-${conv.otherId}`"
                        class="group relative flex items-start gap-3 border-b border-gray-50 px-4 py-3 transition-colors hover:bg-gray-50"
                        :class="
                            selectedConversation?.groupId === conv.groupId &&
                            selectedConversation?.otherId === conv.otherId
                                ? 'bg-gray-50'
                                : ''
                        "
                    >
                        <button
                            @click="selectConversation(conv)"
                            :aria-pressed="
                                selectedConversation?.groupId ===
                                    conv.groupId &&
                                selectedConversation?.otherId === conv.otherId
                            "
                            class="flex flex-1 items-start gap-3 text-left min-w-0"
                        >
                            <img
                                v-if="conv.otherAvatar"
                                :src="conv.otherAvatar"
                                :alt="conv.otherName"
                                class="h-10 w-10 shrink-0 rounded-full object-cover"
                            />
                            <div
                                v-else
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-equitab-navy/10 text-sm font-semibold text-equitab-navy"
                            >
                                {{ initials(conv.otherName) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <p
                                        class="truncate text-sm font-medium text-equitab-navy"
                                    >
                                        {{ conv.otherName }}
                                    </p>
                                    <span
                                        v-if="conv.unreadCount > 0"
                                        class="ml-2 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-equitab-emerald text-xs font-medium text-white"
                                    >
                                        {{ conv.unreadCount }}
                                    </span>
                                </div>
                                <p class="truncate text-xs text-gray-400">
                                    {{ conv.subscriptionName }}
                                </p>
                                <p
                                    v-if="conv.lastMessage"
                                    class="truncate text-xs text-gray-400 mt-0.5"
                                >
                                    {{ conv.lastMessage }}
                                </p>
                            </div>
                        </button>

                        <button
                            @click="openMemberPicker(conv.groupId)"
                            class="shrink-0 rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-equitab-navy"
                            title="Écrire à un autre membre de ce groupe"
                            :aria-label="
                                'Écrire à un membre de ' + conv.subscriptionName
                            "
                        >
                            <UserPlus class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="chat-thread">
                <div
                    v-if="!selectedConversation"
                    class="flex flex-1 flex-col items-center justify-center text-center"
                >
                    <MessageSquare class="h-12 w-12 text-gray-200" />
                    <p class="mt-3 text-sm font-medium text-gray-400">
                        Sélectionnez une conversation
                    </p>
                    <p class="text-xs text-gray-300">
                        pour afficher les messages
                    </p>
                </div>

                <template v-else>
                    <div
                        class="chat-thread-header flex items-center gap-3 border-b border-gray-100 px-6 py-4"
                    >
                        <button
                            type="button"
                            class="chat-back"
                            aria-label="Retour aux conversations"
                            @click="selectedConversation = null"
                        >
                            <ArrowLeft :size="19" />
                        </button>
                        <img
                            v-if="selectedConversation.otherAvatar"
                            :src="selectedConversation.otherAvatar"
                            :alt="selectedConversation.otherName"
                            class="h-9 w-9 rounded-full object-cover"
                        />
                        <div
                            v-else
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-equitab-navy/10 text-sm font-semibold text-equitab-navy"
                        >
                            {{ initials(selectedConversation.otherName) }}
                        </div>
                        <div>
                            <p class="font-semibold text-equitab-navy">
                                {{ selectedConversation.otherName }}
                            </p>
                            <p class="text-xs text-gray-400">
                                {{ selectedConversation.subscriptionName }}
                            </p>
                        </div>
                    </div>

                    <div
                        ref="messagesContainer"
                        class="chat-messages flex-1 overflow-y-auto p-6 space-y-3"
                        role="log"
                        aria-label="Messages de la conversation"
                    >
                        <div v-if="isLoading" class="flex justify-center py-8">
                            <div
                                class="h-5 w-5 animate-spin rounded-full border-2 border-equitab-emerald border-t-transparent"
                            />
                        </div>

                        <div
                            v-else
                            v-for="msg in messages"
                            :key="msg.id"
                            class="flex items-end gap-2"
                            :class="
                                msg.is_mine ? 'flex-row-reverse' : 'flex-row'
                            "
                        >
                            <img
                                v-if="msg.sender_avatar"
                                :src="msg.sender_avatar"
                                :alt="msg.sender_name"
                                class="h-6 w-6 shrink-0 rounded-full object-cover"
                            />
                            <div
                                v-else
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-200 text-[10px] font-semibold text-gray-500"
                            >
                                {{ initials(msg.sender_name) }}
                            </div>
                            <div
                                class="chat-bubble rounded-2xl px-4 py-2.5 text-sm"
                                :class="
                                    msg.is_mine
                                        ? 'rounded-br-sm bg-equitab-navy text-white'
                                        : 'rounded-bl-sm bg-gray-100 text-equitab-navy'
                                "
                            >
                                <p>{{ msg.body }}</p>
                                <p
                                    class="mt-1 text-right text-xs"
                                    :class="
                                        msg.is_mine
                                            ? 'text-white/60'
                                            : 'text-gray-400'
                                    "
                                >
                                    {{ msg.created_at }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 p-4">
                        <div class="flex items-end gap-3">
                            <textarea
                                aria-label="Votre message"
                                v-model="newMessage"
                                @keydown="handleKeydown"
                                placeholder="Écrivez un message... (Entrée pour envoyer)"
                                rows="1"
                                class="min-w-0 flex-1 resize-none rounded-xl border border-gray-200 px-4 py-2.5 text-sm focus:border-equitab-emerald focus:outline-none"
                            />
                            <button
                                aria-label="Envoyer le message"
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

        <ExperienceDialog
            :open="showMemberPicker"
            title="Écrire à un membre"
            @close="showMemberPicker = false"
        >
            <div class="mt-4 max-h-80 space-y-1 overflow-y-auto">
                <p
                    v-if="pickerMembers.length === 0"
                    class="py-6 text-center text-sm text-gray-400"
                >
                    Aucun membre actif dans ce groupe.
                </p>
                <button
                    v-for="member in pickerMembers"
                    :key="member.id"
                    @click="startConversationWith(member)"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left hover:bg-gray-50"
                >
                    <img
                        v-if="member.avatar"
                        :src="member.avatar"
                        :alt="member.name"
                        class="h-8 w-8 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-8 w-8 items-center justify-center rounded-full bg-equitab-navy/10 text-xs font-semibold text-equitab-navy"
                    >
                        {{ initials(member.name) }}
                    </div>
                    <span class="text-sm font-medium text-equitab-navy">{{
                        member.name
                    }}</span>
                </button>
            </div>
        </ExperienceDialog>
    </DashboardLayout>
</template>
<style scoped>
.chat-shell {
    display: flex;
    height: min(700px, calc(100dvh - 270px));
    min-height: 440px;
    overflow: hidden;
    background: white;
    border: 1px solid #dededb;
    border-radius: 25px;
}
.chat-list {
    width: 300px;
    flex: none;
    display: flex;
    flex-direction: column;
    border-right: 1px solid #e2e2df;
    background: #fafaf7;
}
.chat-thread {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}
.chat-messages {
    background: #f6f5f1;
    min-height: 0;
}
.chat-bubble {
    max-width: min(320px, 85%);
    overflow-wrap: anywhere;
    white-space: pre-wrap;
    font-size: 13px;
    line-height: 1.7;
}
.chat-back {
    display: none;
}
@media (max-width: 1200px) and (min-width: 701px) {
    .chat-list {
        width: 250px;
    }
}
@media (max-width: 700px) {
    .chat-shell {
        height: calc(100dvh - 255px);
        min-height: 420px;
    }
    .chat-list {
        width: 100%;
        border: 0;
    }
    .chat-thread {
        display: none;
    }
    .conversation-selected .chat-list {
        display: none;
    }
    .conversation-selected .chat-thread {
        display: flex;
        width: 100%;
    }
    .chat-back {
        display: grid;
        place-items: center;
        width: 40px;
        flex: none;
        border-radius: 50%;
        background: #f0f0e9;
    }
    .chat-thread-header {
        padding: 15px;
    }
    .chat-messages {
        padding: 18px 14px;
    }
}
</style>
