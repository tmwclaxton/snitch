<script setup lang="ts">
// Hidden while the logged-in app is pinned to Caution Tape night.
// settings/appearance redirects to profile; keep this page so Appearance can return.
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
                Light keeps the cream Evidence File look. Dark uses the Caution Tape night shell (same as Pricing). System follows your device. Default is system.
            </p>
            <div class="relative z-10 mt-5">
                <AppearanceTabs />
            </div>
            <p class="relative z-10 mt-4 text-sm text-snitch-ink/55">
                Find this anytime under Account → Settings → Appearance.
            </p>
        </div>
    </div>
</template>
