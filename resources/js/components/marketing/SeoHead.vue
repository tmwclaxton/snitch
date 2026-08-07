<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        image?: string;
        path?: string;
    }>(),
    {
        description:
            'Snitch shows what your competitors post across social platforms, explains why it works, and surfaces winners you can remake.',
        image: '/images/marketing/og.jpg',
    },
);

const page = usePage();
const appName = computed(() => page.props.name || 'Snitch');
const origin = computed(() =>
    typeof window !== 'undefined' ? window.location.origin : '',
);
const absoluteImage = computed(() => {
    if (props.image.startsWith('http')) {
        return props.image;
    }

    return origin.value
        ? `${origin.value}${props.image}`
        : props.image;
});
const canonical = computed(() => {
    if (!origin.value) {
        return props.path ?? '/';
    }

    return `${origin.value}${props.path ?? page.url}`;
});
</script>

<template>
    <Head :title="title">
        <meta name="description" :content="description" />
        <meta property="og:site_name" :content="appName" />
        <meta property="og:title" :content="`${title} - ${appName}`" />
        <meta property="og:description" :content="description" />
        <meta property="og:type" content="website" />
        <meta property="og:image" :content="absoluteImage" />
        <meta property="og:url" :content="canonical" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" :content="`${title} - ${appName}`" />
        <meta name="twitter:description" :content="description" />
        <meta name="twitter:image" :content="absoluteImage" />
        <link rel="canonical" :href="canonical" />
    </Head>
</template>
