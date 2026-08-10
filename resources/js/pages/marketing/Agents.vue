<script setup lang="ts">
import McpConnectGuide from '@/components/agents/McpConnectGuide.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});

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
    <div>
        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <div class="mx-auto max-w-6xl">
                <h1 class="snitch-display max-w-3xl text-4xl text-snitch-ink sm:text-5xl">
                    Connect Snitch over MCP
                </h1>
                <p class="mt-4 max-w-2xl text-snitch-ink/80">
                    Snitch is a data layer for social marketing agents. Create an account from your agent, claim
                    it in the browser, keep a balance above 20p (subscribe for plan value or top up), then sync competitors, find influencers, and
                    analyse posts. After suggest_competitors, agents must confirm_competitor_suggestions (or dismiss) - suggestions stay pending until confirmed.
                </p>

                <div class="mt-12">
                    <McpConnectGuide
                        :mcp-url="mcp_url"
                        :register-url="register_url"
                        :clients="clients"
                        :general="general"
                        :tools="tools"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
