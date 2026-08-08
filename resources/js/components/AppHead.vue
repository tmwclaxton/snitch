<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const robots = computed(
    () => page.props.seo?.robots ?? 'noindex, nofollow',
);
</script>

<template>
    <Head>
        <meta head-key="robots" name="robots" :content="robots" />
    </Head>
</template>
