<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/images/brand/mascot-mark.png"
        alt=""
        width="128"
        height="128"
        :class="className"
        class="object-contain"
        v-bind="$attrs"
        decoding="async"
    />
</template>
