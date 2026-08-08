<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
import BrandProfileForm from '@/components/BrandProfileForm.vue';
import type { BrandProfileFormData } from '@/components/BrandProfileForm.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineProps<{
    brand: BrandProfileFormData | null;
    platforms: string[];
}>();

defineOptions({
    layout: PublicLayout,
});

setLayoutProps({ minimal: true });
</script>

<template>
    <div class="px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Onboarding" />

        <div class="mx-auto max-w-3xl">
            <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                Tell Snitch about your brand
            </h1>

            <BrandProfileForm
                class="mt-5"
                :brand="brand"
                :platforms="platforms"
                :submit-url="store.url()"
                submit-method="post"
                submit-label="Save and continue"
            />
        </div>
    </div>
</template>
