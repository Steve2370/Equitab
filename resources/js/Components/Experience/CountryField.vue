<script setup lang="ts">
import { computed, useId } from "vue";
import type { CountryOption } from "@/types/market";

const props = defineProps<{
    modelValue: string;
    countries: CountryOption[];
    label: string;
    error?: string;
    disabled?: boolean;
    required?: boolean;
    form?: string;
}>();
const emit = defineEmits<{ "update:modelValue": [country: string] }>();
const id = `country-${useId()}`;
const selected = computed(() => props.countries.find((item) => item.code === props.modelValue));
function select(event: Event): void {
    emit("update:modelValue", (event.target as HTMLSelectElement).value);
}
</script>

<template>
    <div class="country-field">
        <label :for="id">{{ label }}</label>
        <select :id="id" name="country" :form="form" :value="modelValue" :required="required" :disabled="disabled" :aria-invalid="!!error" :aria-describedby="`${id}-hint${error ? ` ${id}-error` : ''}`" @change="select">
            <option value="" disabled>Choisir un pays</option>
            <option v-for="country in countries" :key="country.code" :value="country.code">{{ country.name }} · {{ country.currency }}</option>
        </select>
        <p :id="`${id}-hint`" class="country-hint" aria-live="polite">{{ selected ? `Devise proposée : ${selected.currency === 'EUR' ? 'euro (EUR)' : 'dollar canadien (CAD)'}.` : 'Canada ou l’un des 21 pays de la zone euro.' }}</p>
        <p v-if="error" :id="`${id}-error`" class="country-error" role="alert">{{ error }}</p>
    </div>
</template>

<style scoped>
.country-field { min-width: 0; color: #303b37; }
.country-field label { display: block; margin-bottom: 6px; font-size: .8rem; font-weight: 600; }
.country-field select { width: 100%; max-width: 100%; min-height: 44px; padding: 9px 34px 9px 12px; border: 1px solid #dce5df; border-radius: 10px; background-color: #fff; color: inherit; font: inherit; font-size: 1rem; }
.country-field select:focus-visible { outline: 3px solid #187a57; outline-offset: 3px; }
.country-field select:disabled { opacity: .6; cursor: wait; }
.country-field select[aria-invalid="true"] { border-color: #b42318; }
.country-hint, .country-error { margin-top: 6px; font-size: .75rem; line-height: 1.5; overflow-wrap: anywhere; }
.country-hint { color: #59685f; }
.country-error { color: #b42318; }
</style>
