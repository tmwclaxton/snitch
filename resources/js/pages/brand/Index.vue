<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { update } from '@/actions/App/Http/Controllers/BrandProfileController';
import BrandProfileForm from '@/components/BrandProfileForm.vue';
import type { BrandProfileFormData } from '@/components/BrandProfileForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    brand: BrandProfileFormData;
    platforms: string[];
}>();

defineOptions({
    layout: AppLayout,
});
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Brand" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-3xl">
            <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                Brand
            </h1>
            <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                Scrapbook context so Snitch knows who you are - name, site, and your handles.
            </p>

            <BrandProfileForm
                class="mt-5"
                :brand="brand"
                :platforms="platforms"
                :submit-url="update.url()"
                submit-method="put"
                submit-label="Save brand"
            />
        </div>
    </div>
</template>
