<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from "vue";
import { X } from "lucide-vue-next";
const props = defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
let returnFocus: HTMLElement | null = null;
let previousOverflow = "";
function restore() {
    document.body.style.overflow = previousOverflow;
    returnFocus?.focus();
}
watch(
    () => props.open,
    async (open) => {
        await nextTick();
        if (open && dialog.value && !dialog.value.open) {
            returnFocus = document.activeElement as HTMLElement;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = "hidden";
            dialog.value.showModal();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
            restore();
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (dialog.value?.open) {
        dialog.value.close();
        restore();
    }
});
</script>
<template>
    <dialog
        ref="dialog"
        class="experience-dialog eq-experience"
        :aria-label="title"
        @cancel.prevent="emit('close')"
        @click="
            (event) => {
                if (event.target === dialog) emit('close');
            }
        "
    >
        <div class="experience-dialog-inner">
            <header>
                <h2>{{ title }}</h2>
                <button
                    type="button"
                    aria-label="Fermer la fenêtre"
                    autofocus
                    @click="emit('close')"
                >
                    <X :size="20" />
                </button>
            </header>
            <slot v-if="open" />
        </div>
    </dialog>
</template>
<style scoped>
.experience-dialog {
    margin: auto;
    border: 1px solid #dce5df;
    border-radius: 26px;
    width: min(520px, calc(100% - 32px));
    max-height: calc(100dvh - 40px);
    padding: 0;
    overflow: auto;
    color: #303b37;
    background: #fff;
}
.experience-dialog::backdrop {
    background: #303b3788;
    backdrop-filter: blur(5px);
}
.experience-dialog-inner {
    padding: 26px;
}
header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 18px;
}
h2 {
    font-size: 21px;
    letter-spacing: -0.035em;
    font-weight: 550;
}
header button {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border: 1px solid #dce5df;
    border-radius: 50%;
    flex: none;
}
</style>
