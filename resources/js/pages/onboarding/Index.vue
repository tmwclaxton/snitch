<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
import BrandProfileForm from '@/components/BrandProfileForm.vue';
import type { BrandProfileFormData } from '@/components/BrandProfileForm.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { agents } from '@/routes';

defineProps<{
    brand: BrandProfileFormData | null;
    platforms: string[];
}>();

defineOptions({
    layout: PublicLayout,
});

setLayoutProps({ minimal: true });

const step = ref<'mcp' | 'brand'>('mcp');
const mcpUrl = `${typeof window !== 'undefined' ? window.location.origin : ''}/mcp`;
const tokenHint = 'Create a token on the Agents page after signup, or via MCP create_account.';
</script>

<template>
    <div class="px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Onboarding" />

        <div class="mx-auto max-w-3xl">
            <template v-if="step === 'mcp'">
                <p class="snitch-ink-label">Step 1</p>
                <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                    Connect an agent (optional)
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-snitch-ink/75">
                    Snitch works as an MCP data layer for Cursor, Claude, and other agents. Skip if you only want
                    the website for now - you can connect later from the Agents page.
                </p>

                <div class="snitch-scrap mt-6 space-y-3 p-5 text-sm">
                    <p>
                        Endpoint:
                        <code>{{ mcpUrl }}</code>
                    </p>
                    <p>{{ tokenHint }}</p>
                    <pre class="overflow-x-auto bg-snitch-ink/5 p-3 text-xs">{
  "mcpServers": {
    "snitch": {
      "url": "{{ mcpUrl }}",
      "headers": {
        "Authorization": "Bearer YOUR_SNITCH_API_TOKEN"
      }
    }
  }
}</pre>
                    <Link :href="agents()" class="underline">Full Agents docs</Link>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" class="snitch-btn" @click="step = 'brand'">Continue</button>
                    <button type="button" class="text-sm underline" @click="step = 'brand'">Skip for now</button>
                </div>
            </template>

            <template v-else>
                <p class="snitch-ink-label">Step 2</p>
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
            </template>
        </div>
    </div>
</template>
