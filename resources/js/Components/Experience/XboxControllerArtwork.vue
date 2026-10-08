<script setup lang="ts">
import { useId } from "vue";

// Each card owns its paint definitions, including when server rendered.
const paintId = `xbox-controller-${useId()}`;
</script>

<template>
    <svg
        class="xbox-controller"
        viewBox="0 0 320 220"
        fill="none"
        aria-hidden="true"
        focusable="false"
    >
        <defs>
            <linearGradient :id="`${paintId}-shell`" x1="120" y1="30" x2="200" y2="205" gradientUnits="userSpaceOnUse">
                <stop stop-color="#555b59" />
                <stop offset=".45" stop-color="#292e2c" />
                <stop offset="1" stop-color="#141816" />
            </linearGradient>
            <linearGradient :id="`${paintId}-edge`" x1="105" y1="34" x2="208" y2="197" gradientUnits="userSpaceOnUse">
                <stop stop-color="#8b928d" />
                <stop offset=".55" stop-color="#414743" />
                <stop offset="1" stop-color="#0d110f" />
            </linearGradient>
            <radialGradient :id="`${paintId}-stick`" cx=".35" cy=".25" r=".8">
                <stop stop-color="#505753" />
                <stop offset=".65" stop-color="#242a26" />
                <stop offset="1" stop-color="#141a16" />
            </radialGradient>
        </defs>

        <!-- Shoulder buttons and tapered grips give the Xbox silhouette. -->
        <path d="M64 48 69 31Q88 22 116 30L122 42M198 42 204 30Q232 22 251 31L256 48" fill="#121714" stroke="#69716b" stroke-width="2" stroke-linejoin="round" />
        <path d="M84 35C60 35 44 50 35 80L17 158C10 188 18 207 36 209C53 211 68 187 89 158Q95 150 109 151H211Q225 150 231 158C252 187 267 211 284 209C302 207 310 188 303 158L285 80C276 50 260 35 236 35Q160 48 84 35Z" fill="#0c110e" />
        <path d="M84 31C60 31 44 46 35 76L17 154C10 184 18 203 36 205C53 207 68 183 89 154Q95 146 109 147H211Q225 146 231 154C252 183 267 207 284 205C302 203 310 184 303 154L285 76C276 46 260 31 236 31Q160 44 84 31Z" :fill="`url(#${paintId}-shell)`" :stroke="`url(#${paintId}-edge)`" stroke-width="2.5" />
        <path d="M25 161 43 89C47 76 52 70 58 65M262 65C268 70 273 76 277 89L295 161" stroke="#747c75" stroke-opacity=".35" stroke-width="2" stroke-linecap="round" />
        <path d="M46 186 79 144M52 188 83 149M268 188 237 149M274 186 241 144" stroke="#747c75" stroke-opacity=".22" stroke-width="2" stroke-linecap="round" />

        <!-- Offset thumbsticks, unlike a symmetrical generic gamepad. -->
        <g class="controller-stick controller-stick-left" transform="translate(87 78)">
            <circle r="28" fill="#161c18" stroke="#5b645d" stroke-width="1.5" />
            <circle r="22" :fill="`url(#${paintId}-stick)`" stroke="#090e0b" stroke-width="3" />
            <circle r="17" stroke="#838d85" stroke-opacity=".55" stroke-width="1.5" stroke-dasharray="1 3" />
            <path d="M-12-11Q0-20 13-10" stroke="#b7c1b8" stroke-opacity=".35" stroke-linecap="round" />
        </g>
        <g class="controller-stick controller-stick-right" transform="translate(196 127)">
            <circle r="27" fill="#151b17" stroke="#555e56" stroke-width="1.5" />
            <circle r="21" :fill="`url(#${paintId}-stick)`" stroke="#090e0b" stroke-width="3" />
            <circle r="16" stroke="#838d85" stroke-opacity=".55" stroke-width="1.5" stroke-dasharray="1 3" />
            <path d="M-11-11Q0-19 12-10" stroke="#b7c1b8" stroke-opacity=".35" stroke-linecap="round" />
        </g>

        <!-- Faceted hybrid directional pad. -->
        <g class="controller-dpad" transform="translate(121 125)">
            <circle r="25" fill="#171d19" stroke="#576159" />
            <path d="M-10-20H10L20-10V10L10 20H-10L-20 10V-10Z" fill="#49534b" stroke="#8d988e" stroke-width="1.2" />
            <path d="M-10-20 0-6 10-20M20-10 6 0 20 10M10 20 0 6-10 20M-20 10-6 0-20-10" stroke="#1a231c" stroke-width="1.5" />
            <path d="M-5-13H5V-5H13V5H5V13H-5V5H-13V-5H-5Z" fill="#232c25" />
        </g>

        <!-- Keep the face buttons neutral; service branding supplies the colour. -->
        <g class="controller-face-buttons" fill="#161d18" stroke="#747e76" stroke-width="1">
            <circle cx="239" cy="55" r="11" />
            <circle cx="261" cy="77" r="11" />
            <circle cx="239" cy="99" r="11" />
            <circle cx="217" cy="77" r="11" />
        </g>
        <g fill="#e2e9e1" font-family="Arial, sans-serif" font-size="12" font-weight="700" text-anchor="middle">
            <text x="239" y="59">Y</text>
            <text x="261" y="81">B</text>
            <text x="239" y="103">A</text>
            <text x="217" y="81">X</text>
        </g>
        <circle cx="160" cy="57" r="13" fill="#131a15" stroke="#6e7a70" />
        <circle cx="160" cy="57" r="9" fill="#edf4eb" />
        <path d="M154 51Q160 53 166 63M166 51Q160 53 154 63" stroke="#26352a" stroke-width="2.5" stroke-linecap="round" />
        <g fill="#1a221d" stroke="#5f6c62">
            <circle cx="136" cy="84" r="7" />
            <circle cx="184" cy="84" r="7" />
            <rect x="155" y="101" width="10" height="13" rx="4" />
        </g>
        <path d="M133 81H138V85H133ZM181 82H187M181 85H187M158 106H162M160 104V108" stroke="#c5d0c5" stroke-width="1" stroke-linecap="round" />
    </svg>
</template>

<style scoped>
.xbox-controller {
    display: block;
    width: 100%;
    height: 100%;
    overflow: visible;
}
</style>
