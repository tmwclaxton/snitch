<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import { ref, watch } from 'vue';
import ConnectController from '@/actions/App/Http/Controllers/ConnectController';
import McpConnectGuide from '@/components/agents/McpConnectGuide.vue';
import { show } from '@/routes/connect';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Connect',
                href: show(),
            },
        ],
    },
});

const props = defineProps<{
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
    has_mcp_token: boolean;
    plain_token: string | null;
}>();

const liveToken = ref<string | null>(props.plain_token);

watch(
    () => props.plain_token,
    (token) => {
        if (token) {
            liveToken.value = token;
        }
    },
);
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Connect your AI" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl space-y-8">
            <header class="border-b border-snitch-ink/10 pb-5">
                <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                    Snitch / Connect
                </p>
                <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0 max-w-3xl">
                        <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                            Connect your AI
                        </h1>
                        <p class="mt-2 text-sm text-snitch-ink/75 sm:text-base">
                            Mint a token, copy the full server URL, and paste the setup into your agent.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Form v-bind="ConnectController.rotateToken.form()">
                            <button type="submit" class="snitch-btn snitch-btn-spot px-3 py-2 text-sm">
                                <Bot class="relative z-10 size-4 shrink-0" aria-hidden="true" />
                                <span class="relative z-10">
                                    {{ has_mcp_token ? 'Rotate token' : 'Create token' }}
                                </span>
                            </button>
                        </Form>
                        <Form
                            v-if="has_mcp_token"
                            v-bind="ConnectController.revokeToken.form()"
                        >
                            <button type="submit" class="snitch-btn px-3 py-2 text-sm">
                                <span class="relative z-10">Revoke token</span>
                            </button>
                        </Form>
                    </div>
                </div>
            </header>

            <McpConnectGuide
                :mcp-url="mcp_url"
                :register-url="register_url"
                :clients="clients"
                :general="general"
                :tools="tools"
                :api-token="liveToken"
            />
        </div>
    </div>
</template>
