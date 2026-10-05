<script setup lang="ts">
withDefaults(defineProps<{ light?: boolean }>(), { light: false });
</script>
<template>
    <img
        class="collection-wordmark"
        :src="
            light ? '/Images/EquitabLogoblanc.svg' : '/Images/EquitabLogo.svg'
        "
        alt="EquitAb"
        width="1707"
        height="397"
    />
</template>
<style scoped>
.collection-wordmark {
    display: inline-block;
    width: 156px;
    max-width: 100%;
    height: auto;
    vertical-align: middle;
    object-fit: contain;
}
</style>
