<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    title?: string;
    description?: string;
    image?: string;
    path?: string;
    robots?: string;
}>();

const page = usePage();

const seo = computed(() => page.props.seo);
const siteName = computed(() => seo.value?.site_name || page.props.name || 'Snitch');

const title = computed(() => props.title ?? seo.value?.title ?? siteName.value);
const description = computed(
    () => props.description ?? seo.value?.description ?? '',
);
const robots = computed(
    () => props.robots ?? seo.value?.robots ?? 'index, follow',
);
const locale = computed(() => seo.value?.locale ?? 'en_GB');
const twitterCard = computed(
    () => seo.value?.twitter_card ?? 'summary_large_image',
);

const fullTitle = computed(() =>
    title.value === siteName.value
        ? siteName.value
        : `${title.value} - ${siteName.value}`,
);

const absoluteImage = computed(() => {
    if (props.image) {
        if (props.image.startsWith('http')) {
            return props.image;
        }

        const root = seo.value?.canonical
            ? new URL(seo.value.canonical).origin
            : '';

        return root ? `${root}${props.image}` : props.image;
    }

    return seo.value?.image ?? '/images/marketing/og.jpg';
});

const canonical = computed(() => {
    if (props.path) {
        const root = seo.value?.canonical
            ? new URL(seo.value.canonical).origin
            : '';
        const path = props.path.startsWith('/') ? props.path : `/${props.path}`;

        return root ? `${root}${path === '/' ? '/' : path}` : path;
    }

    return seo.value?.canonical ?? page.url;
});

const jsonLdScripts = computed(() =>
    (seo.value?.json_ld ?? []).map((node) => JSON.stringify(node)),
);
</script>

<template>
    <Head :title="title === siteName ? '' : title">
        <meta
            head-key="description"
            name="description"
            :content="description"
        />
        <meta head-key="robots" name="robots" :content="robots" />
        <meta
            head-key="og:site_name"
            property="og:site_name"
            :content="siteName"
        />
        <meta head-key="og:title" property="og:title" :content="fullTitle" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="description"
        />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta head-key="og:image" property="og:image" :content="absoluteImage" />
        <meta head-key="og:url" property="og:url" :content="canonical" />
        <meta head-key="og:locale" property="og:locale" :content="locale" />
        <meta
            head-key="twitter:card"
            name="twitter:card"
            :content="twitterCard"
        />
        <meta
            head-key="twitter:title"
            name="twitter:title"
            :content="fullTitle"
        />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            :content="description"
        />
        <meta
            head-key="twitter:image"
            name="twitter:image"
            :content="absoluteImage"
        />
        <link head-key="canonical" rel="canonical" :href="canonical" />
        <!-- Vue ignores side-effect tags in templates; render JSON-LD dynamically. -->
        <component
            :is="'script'"
            v-for="(payload, index) in jsonLdScripts"
            :key="`ld-${index}`"
            type="application/ld+json"
            :head-key="`ld-json-${index}`"
        >{{ payload }}</component>
    </Head>
</template>
