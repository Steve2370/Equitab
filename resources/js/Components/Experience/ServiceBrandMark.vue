<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { serviceBrand, serviceLogoSource } from "@/config/servicePresentation";
const props = withDefaults(defineProps<{ slug: string; name?: string }>(), {
    name: "",
});
const brand = computed(() => serviceBrand(props.slug));
const source = computed(() => serviceLogoSource(brand.value));
const failed = ref(false);
watch(
    () => props.slug,
    () => {
        failed.value = false;
    },
);
</script>
<template>
    <span
        class="service-brand-mark"
        :class="{ 'service-brand-wide': brand?.logoWide }"
        :aria-label="brand?.name || name"
    >
        <span v-if="source && !failed" class="service-logo-frame">
            <img
                :src="source"
                alt=""
                width="30"
                height="30"
                @error="failed = true"
                :style="{ transform: `scale(${brand?.logoScale ?? 1})` }"
            />
        </span>
        <span v-else>{{ brand?.name || name }}</span>
    </span>
</template>
<style scoped>
.service-brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
    min-width: 42px;
    min-height: 42px;
    max-width: 150px;
    padding: 7px 10px;
    border-radius: 12px;
    background: #fff;
    color: #161616;
    border: 1px solid #10101010;
    box-shadow: 0 4px 12px #00000008;
}
.service-logo-frame {
    position: relative;
    display: block;
    width: 28px;
    height: 28px;
    overflow: hidden;
}
.service-brand-wide .service-logo-frame {
    width: 80px;
    height: 34px;
}
.service-brand-mark img {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.service-brand-mark > span:not(.service-logo-frame) {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
</style>
