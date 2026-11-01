<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <div>
        <Head title="Appearance settings" />

        <div class="snitch-doc relative p-5 sm:p-6">
            <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
            <h2 class="snitch-display relative z-10 text-2xl text-snitch-ink">
                Appearance
            </h2>
            <p class="relative z-10 mt-1.5 text-sm text-snitch-ink/65">
                Pick light, dark, or follow the system. Paper grade stays warm either way.
            </p>
            <div class="relative z-10 mt-5">
                <AppearanceTabs />
            </div>
        </div>
    </div>
</template>
