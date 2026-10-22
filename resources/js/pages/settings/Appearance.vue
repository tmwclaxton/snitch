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

        <div class="relative rounded border border-snitch-ink/10 bg-snitch-lift p-5 sm:p-6">
            <h2 class="snitch-hero-display text-2xl text-snitch-ink">
                Appearance
            </h2>
            <p class="mt-1.5 text-sm text-snitch-ink/70">
                Light uses cream paper with yellow highlights. Dark uses the Caution Tape night shell. System follows your device. Default is system.
            </p>
            <div class="mt-5">
                <AppearanceTabs />
            </div>
            <p class="mt-4 text-sm text-snitch-ink/55">
                Find this anytime under Settings → Appearance.
            </p>
        </div>
    </div>
</template>
