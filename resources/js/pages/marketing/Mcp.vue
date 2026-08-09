<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const mcpUrl = computed(() => `${window.location.origin}/mcp`);
const registerUrl = computed(() => `${window.location.origin}/mcp/register`);

const cursorSnippet = computed(
    () => `{
  "mcpServers": {
    "snitch": {
      "url": "${mcpUrl.value}",
      "headers": {
        "Authorization": "Bearer YOUR_SNITCH_API_TOKEN"
      }
    }
  }
}`,
);

const claudeSnippet = computed(
    () => `{
  "mcpServers": {
    "snitch": {
      "type": "http",
      "url": "${mcpUrl.value}",
      "headers": {
        "Authorization": "Bearer YOUR_SNITCH_API_TOKEN"
      }
    }
  }
}`,
);
</script>

<template>
    <Head title="MCP for agents" />
    <SeoHead />

    <div class="snitch-doc mx-auto max-w-3xl px-4 py-16">
        <p class="snitch-ink-label">Agents</p>
        <h1 class="font-display mb-4 text-4xl">Connect Snitch over MCP</h1>
        <p class="mb-8 max-w-2xl text-[var(--snitch-ink)]/75">
            Snitch is a data layer for social marketing agents. Create an account from your agent, claim it in
            the browser, subscribe, top up credits, then sync competitors, find influencers, and analyse posts.
        </p>

        <section class="snitch-scrap mb-6 space-y-3 p-5">
            <h2 class="font-display text-2xl">Endpoints</h2>
            <ul class="space-y-2 text-sm">
                <li>
                    <span class="snitch-ink-label">Register</span>
                    <code class="ml-2">{{ registerUrl }}</code>
                    - create_account (no auth)
                </li>
                <li>
                    <span class="snitch-ink-label">API</span>
                    <code class="ml-2">{{ mcpUrl }}</code>
                    - bearer token required
                </li>
            </ul>
        </section>

        <section class="snitch-scrap mb-6 space-y-3 p-5">
            <h2 class="font-display text-2xl">Cursor</h2>
            <pre class="overflow-x-auto bg-[var(--snitch-ink)]/5 p-3 text-xs">{{ cursorSnippet }}</pre>
        </section>

        <section class="snitch-scrap mb-6 space-y-3 p-5">
            <h2 class="font-display text-2xl">Claude</h2>
            <pre class="overflow-x-auto bg-[var(--snitch-ink)]/5 p-3 text-xs">{{ claudeSnippet }}</pre>
        </section>

        <section class="snitch-scrap space-y-3 p-5">
            <h2 class="font-display text-2xl">Billing notes</h2>
            <ul class="list-disc space-y-2 pl-5 text-sm text-[var(--snitch-ink)]/80">
                <li>Agent-created accounts start with £0 usage until claimed.</li>
                <li>Claiming in the browser grants £5 once.</li>
                <li>Billable tools need an active platform plan plus credit balance.</li>
                <li>Usage on the billing page is shown for Apify, NanoGPT, and Firecrawl.</li>
            </ul>
        </section>
    </div>
</template>
