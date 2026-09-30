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
    <div class="px-4 py-6 sm:px-8">
        <Head title="Brand" />
        <div class="mb-6 border-b border-snitch-ink/10 pb-4">
            <h1 class="text-2xl font-semibold tracking-tight">Brand</h1>
            <p class="mt-1 text-sm text-snitch-ink/55">
                Context so Snitch knows who you are - name, description, and your handles.
            </p>
        </div>

        <div class="w-full max-w-none">
            <BrandProfileForm
                :brand="brand"
                :platforms="platforms"
                :submit-url="update.url()"
                submit-method="put"
                submit-label="Save brand"
            />
        </div>
    </div>
</template>
