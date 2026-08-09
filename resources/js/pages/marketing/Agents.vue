<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import McpConnectGuide from '@/components/agents/McpConnectGuide.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

defineProps<{
    mcp_url: string;
    register_url: string;
    clients: Array<{
        id: string;
        name: string;
        blurb: string;
        snippet: string;
        steps: string[];
    }>;
    general: {
        title: string;
        blurb: string;
        snippet: string;
        steps: string[];
    };
    tools: string[];
}>();
</script>

<template>
    <Head title="Agents" />
    <SeoHead />

    <div class="snitch-doc mx-auto max-w-6xl px-5 py-14 sm:px-8 sm:py-20">
        <p class="snitch-ink-label">Agents</p>
        <h1 class="font-display mb-4 max-w-3xl text-4xl text-snitch-ink sm:text-5xl">
            Connect Snitch over MCP
        </h1>
        <p class="mb-10 max-w-3xl text-snitch-ink/75 sm:text-lg">
            Snitch is a data layer for social marketing agents. Create an account from your agent, claim it in
            the browser, subscribe, top up credits, then sync competitors, find influencers, and analyse posts.
        </p>

        <McpConnectGuide
            :mcp-url="mcp_url"
            :register-url="register_url"
            :clients="clients"
            :general="general"
            :tools="tools"
        />
    </div>
</template>
