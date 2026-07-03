<script setup lang="ts">
import { ref } from 'vue';
import { CheckCircle, XCircle, AlertTriangle, X } from 'lucide-vue-next';

interface Toast {
    id: number;
    type: 'success' | 'error' | 'warning';
    message: string;
}

const toasts = ref<Toast[]>([]);
let counter = 0;

function add(message: string, type: Toast['type'] = 'success') {
    const id = ++counter;
    toasts.value.push({ id, type, message });
    setTimeout(() => remove(id), 4000);
}

function remove(id: number) {
    toasts.value = toasts.value.filter(t => t.id !== id);
}

defineExpose({ add });
</script>

<template>
    <div class="fixed bottom-6 right-6 z-50 flex flex-col gap-3">
        <Transition
            v-for="toast in toasts"
            :key="toast.id"
            enter-active-class="transition-all duration-300"
            enter-from-class="opacity-0 translate-x-8"
            enter-to-class="opacity-100 translate-x-0"
            leave-active-class="transition-all duration-200"
            leave-from-class="opacity-100 translate-x-0"
            leave-to-class="opacity-0 translate-x-8"
        >
            <div
                class="flex items-center gap-3 rounded-xl px-4 py-3 shadow-lg min-w-72 max-w-sm"
                :class="{
                    'bg-white border border-equitab-emerald/20': toast.type === 'success',
                    'bg-white border border-red-200': toast.type === 'error',
                    'bg-white border border-amber-200': toast.type === 'warning',
                }"
            >
                <CheckCircle v-if="toast.type === 'success'" class="h-5 w-5 shrink-0 text-equitab-emerald" />
                <XCircle v-else-if="toast.type === 'error'" class="h-5 w-5 shrink-0 text-red-500" />
                <AlertTriangle v-else class="h-5 w-5 shrink-0 text-amber-500" />
                <p class="flex-1 text-sm font-medium text-equitab-navy">{{ toast.message }}</p>
                <button @click="remove(toast.id)" class="text-gray-400 hover:text-gray-600">
                    <X class="h-4 w-4" />
                </button>
            </div>
        </Transition>
    </div>
</template>
