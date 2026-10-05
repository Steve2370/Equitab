<template>
    <span class="collection-wordmark" aria-label="Equitab"
        ><span class="wordmark-symbol" aria-hidden="true"
            ><i /><i /><i /><i /></span
        ><span aria-hidden="true">equit<span>ab</span></span></span
    >
</template>
<style scoped>
.collection-wordmark {
    display: inline-flex;
    align-items: center;
    font-size: 30px;
    line-height: 1.2;
    letter-spacing: -0.075em;
    font-weight: 700;
    color: inherit;
}
.collection-wordmark > span > span {
    font-weight: 400;
}
.wordmark-symbol {
    width: 24px;
    height: 24px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2px;
    margin-right: 10px;
    transform: rotate(-8deg);
}
.wordmark-symbol i {
    background: currentColor;
    border-radius: 50% 50% 2px 50%;
}
.wordmark-symbol i:nth-child(2) {
    transform: rotate(90deg);
}
.wordmark-symbol i:nth-child(3) {
    transform: rotate(-90deg);
}
.wordmark-symbol i:nth-child(4) {
    transform: rotate(180deg);
}
</style>
