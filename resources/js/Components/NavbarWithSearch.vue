<script setup lang="ts">
import { onMounted, ref } from "vue";
import CollectionHeader from "./Experience/CollectionHeader.vue";
import Toast from "./Toast.vue";
import { useToast } from "@/composables/useToast";
withDefaults(
    defineProps<{
        canLogin?: boolean;
        canRegister?: boolean;
        isAuthenticated?: boolean;
        userName?: string;
    }>(),
    { canLogin: true, canRegister: true, isAuthenticated: false },
);
const toastComponent = ref();
const { setRef } = useToast();
onMounted(() => {
    if (toastComponent.value) setRef(toastComponent.value);
});
</script>
<template>
    <CollectionHeader
        :can-login="canLogin"
        :can-register="canRegister"
        :is-authenticated="isAuthenticated"
    /><Toast ref="toastComponent" />
</template>
